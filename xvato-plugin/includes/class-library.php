<?php
/**
 * Xvato — Library (Custom Post Type)
 *
 * Registers the xv_template_library CPT and manages metadata.
 *
 * @package Xvato
 */

namespace Xvato;

defined( 'ABSPATH' ) || exit;

class Library {

    /**
     * Initialize: register CPT on 'init'.
     */
    public static function init(): void {
        add_action( 'init', [ self::class, 'register_post_type' ] );
    }

    /**
     * Register the xv_template_library custom post type.
     */
    public static function register_post_type(): void {
        $labels = [
            'name'               => __( 'Template Library', 'xvato' ),
            'singular_name'      => __( 'Template Kit', 'xvato' ),
            'add_new'            => __( 'Add New', 'xvato' ),
            'add_new_item'       => __( 'Add New Template Kit', 'xvato' ),
            'edit_item'          => __( 'Edit Template Kit', 'xvato' ),
            'view_item'          => __( 'View Template Kit', 'xvato' ),
            'all_items'          => __( 'All Templates', 'xvato' ),
            'search_items'       => __( 'Search Templates', 'xvato' ),
            'not_found'          => __( 'No templates found.', 'xvato' ),
            'not_found_in_trash' => __( 'No templates found in Trash.', 'xvato' ),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => false, // We use custom admin pages
            'show_in_menu'        => false,
            'show_in_rest'        => false, // Our own REST routes handle this
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'hierarchical'        => false,
            'supports'            => [ 'title', 'thumbnail' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
        ];

        register_post_type( XVATO_CPT, $args );
    }

    /**
     * Create a new library entry from import payload.
     *
     * @param array $payload Sanitized import data.
     * @return int|\WP_Error Post ID on success, WP_Error on failure.
     */
    public static function create_entry( array $payload ): int|\WP_Error {
        $post_data = [
            'post_type'   => XVATO_CPT,
            'post_title'  => $payload['title'] ?: __( 'Untitled Template Kit', 'xvato' ),
            'post_status' => 'publish',
            'meta_input'  => [
                '_xv_import_status'  => 'pending',
                '_xv_source_url'     => $payload['source_url'] ?? '',
                '_xv_download_url'   => $payload['download_url'] ?? '',
                '_xv_thumbnail_url'  => $payload['thumbnail_url'] ?? '',
                '_xv_category'       => $payload['category'] ?? '',
                '_xv_import_log'     => [],
                '_xv_import_error'   => '',
                '_xv_imported_at'    => '',
                '_xv_template_ids'   => [], // Elementor template IDs after import
                '_xv_manifest'       => [], // Parsed manifest.json data
                '_xv_dependencies'   => [], // Required plugins/theme
                '_xv_zip_path'       => '', // Path to stored ZIP for re-import
            ],
        ];

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Try to sideload the thumbnail
        if ( ! empty( $payload['thumbnail_url'] ) ) {
            self::sideload_thumbnail( $post_id, $payload['thumbnail_url'] );
        }

        self::add_log( $post_id, 'Entry created. Awaiting import.' );

        return $post_id;
    }

    /**
     * Update the status of a library entry.
     *
     * @param int    $post_id
     * @param string $status  One of: pending, downloading, extracting, importing, complete, failed
     */
    public static function update_status( int $post_id, string $status ): void {
        update_post_meta( $post_id, '_xv_import_status', sanitize_key( $status ) );

        if ( 'complete' === $status ) {
            update_post_meta( $post_id, '_xv_imported_at', current_time( 'mysql' ) );
        }
    }

    /**
     * Add a log entry for a library item.
     *
     * @param int    $post_id
     * @param string $message
     */
    public static function add_log( int $post_id, string $message ): void {
        $log   = get_post_meta( $post_id, '_xv_import_log', true ) ?: [];
        $log[] = [
            'time'    => current_time( 'mysql' ),
            'message' => sanitize_text_field( $message ),
        ];
        update_post_meta( $post_id, '_xv_import_log', $log );
    }

    /**
     * Set error on a library item.
     *
     * @param int    $post_id
     * @param string $error
     */
    public static function set_error( int $post_id, string $error ): void {
        update_post_meta( $post_id, '_xv_import_error', sanitize_text_field( $error ) );
        self::update_status( $post_id, 'failed' );
        self::add_log( $post_id, 'ERROR: ' . $error );
    }

    /**
     * Format a library post for API/display output.
     *
     * @param \WP_Post $post
     * @return array
     */
    public static function format_entry( \WP_Post $post ): array {
        $thumb_id  = get_post_thumbnail_id( $post->ID );
        $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';

        // Fallback to stored external thumbnail URL
        if ( ! $thumb_url ) {
            $thumb_url = get_post_meta( $post->ID, '_xv_thumbnail_url', true );
        }

        return [
            'id'            => $post->ID,
            'title'         => $post->post_title,
            'status'        => get_post_meta( $post->ID, '_xv_import_status', true ) ?: 'unknown',
            'thumbnail_url' => $thumb_url ?: XVATO_URL . 'assets/placeholder.svg',
            'category'      => get_post_meta( $post->ID, '_xv_category', true ),
            'source_url'    => get_post_meta( $post->ID, '_xv_source_url', true ),
            'imported_at'   => get_post_meta( $post->ID, '_xv_imported_at', true ),
            'template_ids'  => get_post_meta( $post->ID, '_xv_template_ids', true ) ?: [],
            'dependencies'  => get_post_meta( $post->ID, '_xv_dependencies', true ) ?: [],
            'error'         => get_post_meta( $post->ID, '_xv_import_error', true ),
            'created'       => $post->post_date,
        ];
    }

    /**
     * Download and attach a thumbnail image to a library entry.
     *
     * @param int    $post_id
     * @param string $url
     */
    private static function sideload_thumbnail( int $post_id, string $url ): void {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // media_sideload_image returns HTML string, attachment ID, or WP_Error
        $attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    /**
     * Get counts by status.
     *
     * @return array
     */
    public static function get_status_counts(): array {
        global $wpdb;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value as status, COUNT(*) as count
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s AND pm.meta_key = '_xv_import_status'
             GROUP BY pm.meta_value",
            XVATO_CPT
        ) );

        $counts = [
            'pending'     => 0,
            'downloading' => 0,
            'extracting'  => 0,
            'importing'   => 0,
            'complete'    => 0,
            'failed'      => 0,
        ];

        foreach ( $results as $row ) {
            $counts[ $row->status ] = (int) $row->count;
        }

        $counts['total'] = array_sum( $counts );

        return $counts;
    }
}
