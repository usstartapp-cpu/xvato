<?php
/**
 * Xvato — REST API Endpoints
 *
 * Registers and handles all custom REST API routes:
 *   POST /xvato/v1/import   — Accept import from Chrome extension
 *   GET  /xvato/v1/status   — Connection test + site info
 *   GET  /xvato/v1/status/{id} — Check import job status
 *   GET  /xvato/v1/library  — List imported templates
 *
 * @package Xvato
 */

namespace Xvato;

defined( 'ABSPATH' ) || exit;

class RestAPI {

    /**
     * Register all REST routes.
     */
    public static function register_routes(): void {

        // POST /import — Receive import request from Chrome extension
        register_rest_route( XVATO_REST_NAMESPACE, '/import', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_import' ],
            'permission_callback' => [ Security::class, 'check_permission' ],
            'args'                => [
                'title' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'download_url' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'thumbnail_url' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'category' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'source_url' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ] );

        // GET /status — Connection test + site info
        register_rest_route( XVATO_REST_NAMESPACE, '/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_status' ],
            'permission_callback' => [ Security::class, 'check_permission' ],
        ] );

        // GET /status/{id} — Check specific import job status
        register_rest_route( XVATO_REST_NAMESPACE, '/status/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_job_status' ],
            'permission_callback' => [ Security::class, 'check_permission' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param );
                    },
                ],
            ],
        ] );

        // GET /library — List all imported templates
        register_rest_route( XVATO_REST_NAMESPACE, '/library', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_library' ],
            'permission_callback' => [ Security::class, 'check_permission' ],
            'args'                => [
                'page' => [
                    'default'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default'           => 20,
                    'sanitize_callback' => 'absint',
                ],
                'search' => [
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    // ─── Handlers ──────────────────────────────────────────────

    /**
     * Handle POST /import
     * Receives metadata from the Chrome Extension and queues the import.
     */
    public static function handle_import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        // Rate limiting
        $rate_check = Security::check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        // Sanitize the full payload
        $payload = Security::sanitize_import_payload( $request->get_json_params() );

        if ( empty( $payload['title'] ) ) {
            return new \WP_Error(
                'xvato_missing_title',
                __( 'Template title is required.', 'xvato' ),
                [ 'status' => 400 ]
            );
        }

        // Create a library entry with "pending" status
        $post_id = Library::create_entry( $payload );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // If we have a download URL, start the import
        if ( ! empty( $payload['download_url'] ) ) {
            // Try immediate processing first (fast path)
            $immediate = self::try_immediate_import( $post_id, $payload['download_url'] );

            if ( $immediate ) {
                $status = get_post_meta( $post_id, '_bk_import_status', true ) ?: 'processing';
                return new \WP_REST_Response( [
                    'success' => true,
                    'message' => 'complete' === $status
                        ? __( 'Import completed successfully!', 'xvato' )
                        : __( 'Import is processing.', 'xvato' ),
                    'data'    => [
                        'job_id'  => $post_id,
                        'status'  => $status,
                        'title'   => $payload['title'],
                    ],
                ], 200 );
            }

            // Fallback: schedule via cron (for hosts with execution time limits)
            wp_schedule_single_event(
                time(),
                'xvato_process_import',
                [ $post_id, $payload['download_url'] ]
            );
            spawn_cron();

            return new \WP_REST_Response( [
                'success' => true,
                'message' => __( 'Import queued for background processing.', 'xvato' ),
                'data'    => [
                    'job_id'  => $post_id,
                    'status'  => 'queued',
                    'title'   => $payload['title'],
                ],
            ], 202 );
        }

        // No download URL — entry created for manual upload
        return new \WP_REST_Response( [
            'success' => true,
            'message' => __( 'Template entry created. Upload the ZIP file manually in the Xvato dashboard.', 'xvato' ),
            'data'    => [
                'job_id' => $post_id,
                'status' => 'awaiting_upload',
                'title'  => $payload['title'],
            ],
        ], 201 );
    }

    /**
     * Try to process the import immediately (synchronously).
     * Returns true if the import was started, false if it should be deferred.
     *
     * @param int    $post_id
     * @param string $download_url
     * @return bool
     */
    private static function try_immediate_import( int $post_id, string $download_url ): bool {
        // Check if we have enough execution time (need at least 60 seconds)
        $max_execution = (int) ini_get( 'max_execution_time' );
        if ( $max_execution > 0 && $max_execution < 60 ) {
            // Not enough time — defer to cron
            return false;
        }

        // Increase time limit for this request
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // 5 minutes
        }

        try {
            $importer = new Importer();
            $importer->process_from_url( $post_id, $download_url );
            return true;
        } catch ( \Throwable $e ) {
            Library::set_error( $post_id, 'Immediate import failed: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Handle GET /status — Connection test.
     */
    public static function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
        $data = [
            'connected'         => true,
            'site_name'         => get_bloginfo( 'name' ),
            'site_url'          => home_url(),
            'wp_version'        => get_bloginfo( 'version' ),
            'xvato_version' => XVATO_VERSION,
            'php_version'       => PHP_VERSION,
            'elementor_active'  => defined( 'ELEMENTOR_VERSION' ),
            'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
            'elementor_pro'     => defined( 'ELEMENTOR_PRO_VERSION' ),
            'wp_cli_available'  => defined( 'WP_CLI' ) || self::check_wp_cli(),
            'library_count'     => wp_count_posts( XVATO_CPT )->publish ?? 0,
        ];

        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Handle GET /status/{id} — Job status check.
     */
    public static function handle_job_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $post_id = (int) $request['id'];
        $post    = get_post( $post_id );

        if ( ! $post || XVATO_CPT !== $post->post_type ) {
            return new \WP_Error(
                'xvato_not_found',
                __( 'Import job not found.', 'xvato' ),
                [ 'status' => 404 ]
            );
        }

        $status  = get_post_meta( $post_id, '_bk_import_status', true ) ?: 'unknown';
        $log     = get_post_meta( $post_id, '_bk_import_log', true ) ?: [];
        $error   = get_post_meta( $post_id, '_bk_import_error', true ) ?: '';

        return new \WP_REST_Response( [
            'job_id'      => $post_id,
            'title'       => $post->post_title,
            'status'      => $status,
            'log'         => $log,
            'error'       => $error,
            'created'     => $post->post_date,
            'modified'    => $post->post_modified,
        ], 200 );
    }

    /**
     * Handle GET /library — List imported templates.
     */
    public static function handle_library( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'post_type'      => XVATO_CPT,
            'posts_per_page' => min( (int) $request['per_page'], 50 ),
            'paged'          => (int) $request['page'],
            'post_status'    => 'any',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ( ! empty( $request['search'] ) ) {
            $args['s'] = $request['search'];
        }

        $query = new \WP_Query( $args );
        $items = [];

        foreach ( $query->posts as $post ) {
            $items[] = Library::format_entry( $post );
        }

        return new \WP_REST_Response( [
            'items'       => $items,
            'total'       => $query->found_posts,
            'pages'       => $query->max_num_pages,
            'current_page' => (int) $request['page'],
        ], 200 );
    }

    /**
     * Check if WP-CLI binary is accessible on the server.
     */
    private static function check_wp_cli(): bool {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }
        exec( 'which wp 2>/dev/null', $output, $return );
        return 0 === $return;
    }
}

// ─── Background Import Hook ───────────────────────────────────
add_action( 'xvato_process_import', function ( int $post_id, string $download_url ) {
    $importer = new Importer();
    $importer->process_from_url( $post_id, $download_url );
}, 10, 2 );
