<?php
/**
 * Xvato — Admin Pages
 *
 * Registers WP Admin menu pages and enqueues assets.
 *
 * @package Xvato
 */

namespace Xvato;

defined( 'ABSPATH' ) || exit;

class Admin {

    /**
     * Initialize admin hooks.
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'admin_head', [ self::class, 'inline_menu_icon_css' ] );

        // Handle manual file upload
        add_action( 'admin_post_xvato_upload', [ self::class, 'handle_manual_upload' ] );

        // Handle re-import
        add_action( 'admin_post_xvato_reimport', [ self::class, 'handle_reimport' ] );

        // Handle delete
        add_action( 'admin_post_xvato_delete', [ self::class, 'handle_delete' ] );

        // Handle maintenance actions
        add_action( 'admin_post_xvato_clear_tmp', [ self::class, 'handle_clear_tmp' ] );
        add_action( 'admin_post_xvato_reset_failed', [ self::class, 'handle_reset_failed' ] );

        // Handle bulk actions
        add_action( 'admin_post_xvato_bulk', [ self::class, 'handle_bulk_action' ] );

        // Handle settings export/import
        add_action( 'admin_post_xvato_export_settings', [ self::class, 'handle_export_settings' ] );
        add_action( 'admin_post_xvato_import_settings', [ self::class, 'handle_import_settings' ] );

        // AJAX: Kit detail operations
        add_action( 'wp_ajax_xvato_get_kit_templates', [ self::class, 'ajax_get_kit_templates' ] );
        add_action( 'wp_ajax_xvato_import_selected',   [ self::class, 'ajax_import_selected' ] );
        add_action( 'wp_ajax_xvato_activate_theme',     [ self::class, 'ajax_activate_theme' ] );
        add_action( 'wp_ajax_xvato_apply_colors',       [ self::class, 'ajax_apply_colors' ] );
    }

    /**
     * Register admin menu pages.
     */
    public static function register_menus(): void {
        // Full-colour Xvato logo SVG as admin menu icon (served as asset URL)
        $icon_url = XVATO_URL . 'assets/xvato-icon-wp.svg';

        add_menu_page(
            __( 'Xvato', 'xvato' ),
            __( 'Xvato', 'xvato' ),
            'manage_options',
            'xvato',
            [ self::class, 'render_dashboard' ],
            $icon_url,
            65
        );

        add_submenu_page(
            'xvato',
            __( 'Template Library', 'xvato' ),
            __( 'Library', 'xvato' ),
            'manage_options',
            'xvato',
            [ self::class, 'render_dashboard' ]
        );

        add_submenu_page(
            'xvato',
            __( 'Xvato Settings', 'xvato' ),
            __( 'Settings', 'xvato' ),
            'manage_options',
            'xvato-settings',
            [ self::class, 'render_settings' ]
        );

        // Hidden page — Kit Detail (no menu entry, accessed via link)
        add_submenu_page(
            null, // hidden
            __( 'Kit Detail', 'xvato' ),
            __( 'Kit Detail', 'xvato' ),
            'manage_options',
            'xvato-kit',
            [ self::class, 'render_kit_detail' ]
        );
    }

    /**
     * Output critical sidebar-icon CSS on every admin page so the
     * menu icon never reverts to WordPress' oversized default.
     */
    public static function inline_menu_icon_css(): void {
        echo '<style id="xvato-menu-icon">
#adminmenu .toplevel_page_xvato .wp-menu-image{display:flex!important;align-items:center!important;justify-content:center!important;height:100%!important}
#adminmenu .toplevel_page_xvato .wp-menu-image img{width:20px!important;height:20px!important;min-width:20px!important;min-height:20px!important;max-width:20px!important;max-height:20px!important;padding:0!important;margin:0!important;object-fit:contain;display:block!important;opacity:1!important;filter:none!important}
#adminmenu .toplevel_page_xvato .wp-menu-name{display:inline-flex!important;align-items:center!important;vertical-align:middle!important}
#adminmenu .toplevel_page_xvato>a{display:flex!important;align-items:center!important}
</style>';
    }

    /**
     * Enqueue admin CSS and JS.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'xvato' ) ) {
            return;
        }

        wp_enqueue_style(
            'xvato-admin',
            XVATO_URL . 'admin/css/admin.css',
            [],
            XVATO_VERSION
        );

        wp_enqueue_script(
            'xvato-admin',
            XVATO_URL . 'admin/js/admin.js',
            [],
            XVATO_VERSION,
            true
        );

        wp_localize_script( 'xvato-admin', 'xvatoAdmin', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'restUrl'   => rest_url( XVATO_REST_NAMESPACE ),
            'nonce'     => wp_create_nonce( 'xvato_admin' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
            'kitUrl'    => admin_url( 'admin.php?page=xvato-kit' ),
        ] );
    }

    /**
     * Render the main dashboard (library grid).
     */
    public static function render_dashboard(): void {
        include XVATO_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render the settings page.
     */
    public static function render_settings(): void {
        include XVATO_DIR . 'admin/views/settings.php';
    }

    /**
     * Render the kit detail page (template selection, page import, etc.).
     */
    public static function render_kit_detail(): void {
        include XVATO_DIR . 'admin/views/kit-detail.php';
    }

    // ─── Action Handlers ──────────────────────────────────────

    public static function handle_manual_upload(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_upload', 'xvato_nonce' );

        if ( empty( $_FILES['xvato_zip'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=no_file' ) );
            exit;
        }

        $title = sanitize_text_field( $_POST['xvato_title'] ?? 'Manual Upload' );

        $zip_path = Downloader::from_upload( $_FILES['xvato_zip'] );
        if ( is_wp_error( $zip_path ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=' . urlencode( $zip_path->get_error_message() ) ) );
            exit;
        }

        if ( ! Security::is_valid_zip( $zip_path ) ) {
            wp_delete_file( $zip_path );
            wp_redirect( admin_url( 'admin.php?page=xvato&error=invalid_zip' ) );
            exit;
        }

        $post_id = Library::create_entry( [
            'title'        => $title,
            'download_url' => '',
            'source_url'   => '',
            'category'     => sanitize_text_field( $_POST['xvato_category'] ?? '' ),
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=' . urlencode( $post_id->get_error_message() ) ) );
            exit;
        }

        update_post_meta( $post_id, '_xv_zip_path', $zip_path );

        // Prepare kit: extract manifest & metadata WITHOUT auto-importing templates.
        // The user will choose which templates to import from the Kit Detail page.
        $importer = new Importer();
        $importer->prepare_kit( $post_id, $zip_path );

        // Always redirect to Kit Detail page for selective import workflow
        wp_redirect( admin_url( 'admin.php?page=xvato-kit&kit_id=' . $post_id . '&fresh=1' ) );
        exit;
    }

    public static function handle_reimport(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_reimport', 'xvato_nonce' );

        $post_id = absint( $_GET['id'] ?? 0 );
        if ( ! $post_id ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=invalid_id' ) );
            exit;
        }

        $zip_path = get_post_meta( $post_id, '_xv_zip_path', true );
        if ( ! $zip_path || ! file_exists( $zip_path ) ) {
            $download_url = get_post_meta( $post_id, '_xv_download_url', true );
            if ( $download_url ) {
                Library::update_status( $post_id, 'pending' );
                wp_schedule_single_event( time(), 'xvato_process_import', [ $post_id, $download_url ] );
                spawn_cron();
                wp_redirect( admin_url( 'admin.php?page=xvato&reimport=queued' ) );
                exit;
            }

            wp_redirect( admin_url( 'admin.php?page=xvato&error=no_zip' ) );
            exit;
        }

        Library::update_status( $post_id, 'pending' );
        update_post_meta( $post_id, '_xv_import_error', '' );
        update_post_meta( $post_id, '_xv_import_log', [] );

        // Re-prepare the kit (parse manifest) so the user can re-select templates
        $importer = new Importer();
        $importer->prepare_kit( $post_id, $zip_path );

        wp_redirect( admin_url( 'admin.php?page=xvato-kit&kit_id=' . $post_id . '&fresh=1' ) );
        exit;
    }

    public static function handle_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_delete', 'xvato_nonce' );

        $post_id = absint( $_GET['id'] ?? 0 );
        if ( ! $post_id ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=invalid_id' ) );
            exit;
        }

        $zip_path = get_post_meta( $post_id, '_xv_zip_path', true );
        if ( $zip_path && file_exists( $zip_path ) ) {
            wp_delete_file( $zip_path );
        }

        wp_delete_post( $post_id, true );

        wp_redirect( admin_url( 'admin.php?page=xvato&deleted=1' ) );
        exit;
    }

    public static function handle_clear_tmp(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_clear_tmp', 'xvato_nonce' );

        $plugin = Xvato::instance();
        $plugin->cleanup_tmp_directory();

        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . XVATO_TMP_DIR;

        if ( is_dir( $tmp_dir ) ) {
            $files = glob( $tmp_dir . '/*' );
            foreach ( $files as $file ) {
                if ( is_file( $file ) && ! in_array( basename( $file ), [ '.htaccess', 'index.php' ], true ) ) {
                    wp_delete_file( $file );
                }
                if ( is_dir( $file ) && ! in_array( basename( $file ), [ '.', '..' ], true ) ) {
                    Xvato::delete_directory( $file );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=xvato-settings&cleared=1' ) );
        exit;
    }

    public static function handle_reset_failed(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_reset_failed', 'xvato_nonce' );

        $failed_posts = get_posts( [
            'post_type'      => XVATO_CPT,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'   => '_xv_import_status',
                    'value' => 'failed',
                ],
            ],
            'fields' => 'ids',
        ] );

        $count = 0;
        foreach ( $failed_posts as $post_id ) {
            Library::update_status( $post_id, 'pending' );
            update_post_meta( $post_id, '_xv_import_error', '' );
            Library::add_log( $post_id, 'Status reset to pending by admin.' );

            $download_url = get_post_meta( $post_id, '_xv_download_url', true );
            if ( $download_url ) {
                wp_schedule_single_event( time() + $count, 'xvato_process_import', [ $post_id, $download_url ] );
            }

            $count++;
        }

        if ( $count > 0 ) {
            spawn_cron();
        }

        wp_redirect( admin_url( 'admin.php?page=xvato-settings&reset=' . $count ) );
        exit;
    }

    // ─── Bulk Actions ─────────────────────────────────────────

    public static function handle_bulk_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_bulk', 'xvato_nonce' );

        $action = sanitize_key( $_POST['bulk_action'] ?? '' );
        $ids    = array_map( 'absint', (array) ( $_POST['bulk_ids'] ?? [] ) );
        $ids    = array_filter( $ids );

        if ( empty( $ids ) || ! in_array( $action, [ 'delete', 'reimport' ], true ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=invalid_bulk' ) );
            exit;
        }

        $count = 0;

        if ( 'delete' === $action ) {
            foreach ( $ids as $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post || XVATO_CPT !== $post->post_type ) {
                    continue;
                }

                $zip_path = get_post_meta( $post_id, '_xv_zip_path', true );
                if ( $zip_path && file_exists( $zip_path ) ) {
                    wp_delete_file( $zip_path );
                }

                wp_delete_post( $post_id, true );
                $count++;
            }

            wp_redirect( admin_url( 'admin.php?page=xvato&bulk_deleted=' . $count ) );
            exit;
        }

        if ( 'reimport' === $action ) {
            foreach ( $ids as $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post || XVATO_CPT !== $post->post_type ) {
                    continue;
                }

                Library::update_status( $post_id, 'pending' );
                update_post_meta( $post_id, '_xv_import_error', '' );
                Library::add_log( $post_id, 'Bulk re-import queued by admin.' );

                $zip_path     = get_post_meta( $post_id, '_xv_zip_path', true );
                $download_url = get_post_meta( $post_id, '_xv_download_url', true );

                if ( $zip_path && file_exists( $zip_path ) ) {
                    wp_schedule_single_event( time() + $count, 'xvato_process_zip', [ $post_id, $zip_path ] );
                } elseif ( $download_url ) {
                    wp_schedule_single_event( time() + $count, 'xvato_process_import', [ $post_id, $download_url ] );
                }

                $count++;
            }

            if ( $count > 0 ) {
                spawn_cron();
            }

            wp_redirect( admin_url( 'admin.php?page=xvato&bulk_reimport=' . $count ) );
            exit;
        }
    }

    // ─── Kit Detail AJAX Handlers ───────────────────────────────

    /**
     * AJAX: Return the list of templates inside a kit's ZIP/manifest.
     */
    public static function ajax_get_kit_templates(): void {
        check_ajax_referer( 'xvato_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $post_id = absint( $_POST['kit_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( 'Invalid kit ID.' );
        }

        $manifest = get_post_meta( $post_id, '_xv_manifest', true );
        $template_ids = get_post_meta( $post_id, '_xv_template_ids', true ) ?: [];
        $deps = get_post_meta( $post_id, '_xv_dependencies', true ) ?: [];

        $templates = [];

        if ( ! empty( $manifest['templates'] ) ) {
            foreach ( $manifest['templates'] as $idx => $tpl ) {
                $templates[] = [
                    'index'    => $idx,
                    'title'    => $tpl['title'] ?? $tpl['name'] ?? ( 'Template ' . ( $idx + 1 ) ),
                    'type'     => $tpl['type'] ?? $tpl['metadata']['template_type'] ?? 'page',
                    'thumb'    => $tpl['thumbnail'] ?? '',
                    'file'     => $tpl['source'] ?? $tpl['file'] ?? '',
                    'imported' => isset( $template_ids[ $idx ] ),
                ];
            }
        } elseif ( ! empty( $template_ids ) ) {
            // Already imported — list by Elementor template IDs
            foreach ( $template_ids as $idx => $tpl_id ) {
                // Skip placeholder entries from CLI import
                if ( ! is_numeric( $tpl_id ) || 'cli_import_success' === $tpl_id ) {
                    continue;
                }
                $tpl_post = get_post( (int) $tpl_id );
                $templates[] = [
                    'index'    => $idx,
                    'title'    => $tpl_post ? $tpl_post->post_title : "Template #{$tpl_id}",
                    'type'     => get_post_meta( $tpl_id, '_elementor_template_type', true ) ?: 'page',
                    'thumb'    => get_the_post_thumbnail_url( $tpl_id, 'medium' ) ?: '',
                    'file'     => '',
                    'imported' => true,
                    'post_id'  => (int) $tpl_id,
                ];
            }

            // If we only had placeholder IDs and no manifest, show a helpful message
            if ( empty( $templates ) && empty( $manifest['templates'] ) ) {
                // The kit was imported via CLI — templates exist but we can't enumerate them individually
                // Re-extract the ZIP manifest if possible
                $zip_path = get_post_meta( $post_id, '_xv_zip_path', true );
                if ( $zip_path && file_exists( $zip_path ) ) {
                    // Offer re-import to get proper template list
                    $templates[] = [
                        'index'    => 0,
                        'title'    => get_the_title( $post_id ) . ' (full kit)',
                        'type'     => 'kit',
                        'thumb'    => '',
                        'file'     => '',
                        'imported' => true,
                        'post_id'  => 0,
                        'note'     => 'Imported via WP-CLI. Re-import from Setup to get individual template selection.',
                    ];
                }
            }
        }

        // Extract global colors from manifest
        $global_colors = [];
        if ( ! empty( $manifest['global_colors'] ) ) {
            $global_colors = $manifest['global_colors'];
        } elseif ( ! empty( $manifest['settings']['colors'] ) ) {
            $global_colors = $manifest['settings']['colors'];
        }

        // Theme requirement
        $theme_slug = $deps['theme'] ?? $manifest['theme'] ?? '';
        $theme_active = false;
        if ( $theme_slug ) {
            $current = wp_get_theme();
            $theme_active = ( strtolower( $current->get_stylesheet() ) === strtolower( $theme_slug ) );
        }

        wp_send_json_success( [
            'templates'     => $templates,
            'dependencies'  => $deps,
            'global_colors' => $global_colors,
            'theme_slug'    => $theme_slug,
            'theme_active'  => $theme_active,
            'kit_name'      => get_the_title( $post_id ),
        ] );
    }

    /**
     * AJAX: Import only selected templates from a kit.
     */
    public static function ajax_import_selected(): void {
        check_ajax_referer( 'xvato_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $post_id  = absint( $_POST['kit_id'] ?? 0 );
        $selected = array_map( 'absint', (array) ( $_POST['selected'] ?? [] ) );
        $create_pages = ! empty( $_POST['create_pages'] );

        if ( ! $post_id || empty( $selected ) ) {
            wp_send_json_error( 'Invalid request.' );
        }

        $manifest = get_post_meta( $post_id, '_xv_manifest', true );
        $zip_path = get_post_meta( $post_id, '_xv_zip_path', true );

        if ( ! $manifest || empty( $manifest['templates'] ) ) {
            wp_send_json_error( 'No manifest data available for selective import.' );
        }

        if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
            wp_send_json_error( 'Elementor is not active.' );
        }

        // Re-extract the ZIP if needed
        $base_dir = $manifest['_base_dir'] ?? '';
        if ( ! $base_dir || ! is_dir( $base_dir ) ) {
            if ( ! $zip_path || ! file_exists( $zip_path ) ) {
                wp_send_json_error( 'ZIP file not found. Re-import from the library.' );
            }

            WP_Filesystem();
            $upload_dir  = wp_upload_dir();
            $tmp_dir     = trailingslashit( $upload_dir['basedir'] ) . XVATO_TMP_DIR;
            $extract_dir = trailingslashit( $tmp_dir ) . 'extract-' . wp_generate_uuid4();
            wp_mkdir_p( $extract_dir );
            $unzip = unzip_file( $zip_path, $extract_dir );

            if ( is_wp_error( $unzip ) ) {
                wp_send_json_error( 'Failed to extract: ' . $unzip->get_error_message() );
            }

            // Re-find manifest base
            if ( file_exists( $extract_dir . '/manifest.json' ) ) {
                $base_dir = $extract_dir;
            } else {
                $subdirs = glob( $extract_dir . '/*', GLOB_ONLYDIR );
                foreach ( $subdirs as $subdir ) {
                    if ( file_exists( $subdir . '/manifest.json' ) ) {
                        $base_dir = $subdir;
                        break;
                    }
                }
                // If still no manifest base, use the extract dir itself
                if ( ! $base_dir || ! is_dir( $base_dir ) ) {
                    $base_dir = $extract_dir;
                    // Check one level deep for template files
                    if ( empty( glob( $base_dir . '/*.json' ) ) ) {
                        $sub = glob( $extract_dir . '/*', GLOB_ONLYDIR );
                        if ( ! empty( $sub ) ) {
                            $base_dir = $sub[0]; // use first subdirectory
                        }
                    }
                }
            }
        }

        $source = \Elementor\Plugin::$instance->templates_manager->get_source( 'local' );
        if ( ! $source ) {
            wp_send_json_error( 'Elementor template source not available.' );
        }

        $imported   = [];
        $created_pages = [];
        $errors     = [];

        foreach ( $selected as $idx ) {
            if ( ! isset( $manifest['templates'][ $idx ] ) ) {
                continue;
            }

            $tpl      = $manifest['templates'][ $idx ];
            $filename = $tpl['source'] ?? $tpl['file'] ?? '';
            $name     = $tpl['title'] ?? $tpl['name'] ?? basename( $filename, '.json' );

            if ( ! $filename ) {
                $errors[] = "Template #{$idx}: no file reference.";
                continue;
            }

            $file_path = trailingslashit( $base_dir ) . $filename;

            if ( ! file_exists( $file_path ) ) {
                $errors[] = "{$name}: file not found.";
                continue;
            }

            $result = $source->import_template( $name, $file_path );

            if ( is_wp_error( $result ) ) {
                $errors[] = "{$name}: " . $result->get_error_message();
                continue;
            }

            if ( ! empty( $result ) ) {
                foreach ( $result as $item ) {
                    if ( isset( $item['template_id'] ) ) {
                        $imported[] = [
                            'index'       => $idx,
                            'template_id' => $item['template_id'],
                            'title'       => $name,
                        ];

                        // If user wants pages created, create a WP page linked to this template
                        if ( $create_pages ) {
                            $page_id = wp_insert_post( [
                                'post_type'   => 'page',
                                'post_title'  => $name,
                                'post_status' => 'draft',
                                'meta_input'  => [
                                    '_wp_page_template'        => 'elementor_canvas',
                                    '_elementor_edit_mode'     => 'builder',
                                ],
                            ] );

                            if ( ! is_wp_error( $page_id ) ) {
                                // Copy the template content to the page
                                $tpl_data = get_post_meta( $item['template_id'], '_elementor_data', true );
                                if ( $tpl_data ) {
                                    update_post_meta( $page_id, '_elementor_data', $tpl_data );
                                }
                                $tpl_css = get_post_meta( $item['template_id'], '_elementor_css', true );
                                if ( $tpl_css ) {
                                    update_post_meta( $page_id, '_elementor_css', $tpl_css );
                                }
                                // Copy page settings
                                $page_settings = get_post_meta( $item['template_id'], '_elementor_page_settings', true );
                                if ( $page_settings ) {
                                    update_post_meta( $page_id, '_elementor_page_settings', $page_settings );
                                }
                                $created_pages[] = [
                                    'page_id' => $page_id,
                                    'title'   => $name,
                                    'edit_url' => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Update stored template IDs
        $existing_ids = get_post_meta( $post_id, '_xv_template_ids', true ) ?: [];
        foreach ( $imported as $imp ) {
            $existing_ids[ $imp['index'] ] = $imp['template_id'];
        }
        update_post_meta( $post_id, '_xv_template_ids', $existing_ids );

        // Update status to complete if we imported anything
        if ( ! empty( $imported ) ) {
            Library::update_status( $post_id, 'complete' );
        }

        Library::add_log( $post_id, 'Selective import: ' . count( $imported ) . ' template(s) imported.' );
        if ( ! empty( $created_pages ) ) {
            Library::add_log( $post_id, count( $created_pages ) . ' page(s) created as drafts.' );
        }

        // Clean up extracted temp files (keep the ZIP)
        if ( isset( $extract_dir ) && is_dir( $extract_dir ) ) {
            Xvato::delete_directory( $extract_dir );
        }

        wp_send_json_success( [
            'imported'      => $imported,
            'created_pages' => $created_pages,
            'errors'        => $errors,
        ] );
    }

    /**
     * AJAX: Activate a theme required by a kit.
     */
    public static function ajax_activate_theme(): void {
        check_ajax_referer( 'xvato_admin', 'nonce' );

        if ( ! current_user_can( 'switch_themes' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $theme_slug = sanitize_text_field( $_POST['theme'] ?? '' );
        if ( ! $theme_slug ) {
            wp_send_json_error( 'No theme specified.' );
        }

        $theme = wp_get_theme( $theme_slug );

        if ( ! $theme->exists() ) {
            // Try to install it
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/theme.php';

            $api = themes_api( 'theme_information', [ 'slug' => $theme_slug ] );
            if ( is_wp_error( $api ) ) {
                wp_send_json_error( 'Theme not found in repository: ' . $api->get_error_message() );
            }

            $upgrader = new \Theme_Upgrader( new \WP_Ajax_Upgrader_Skin() );
            $result   = $upgrader->install( $api->download_link );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( 'Installation failed: ' . $result->get_error_message() );
            }

            $theme = wp_get_theme( $theme_slug );
        }

        if ( $theme->exists() ) {
            switch_theme( $theme_slug );
            wp_send_json_success( [
                'message' => sprintf( 'Theme "%s" activated successfully.', $theme->get( 'Name' ) ),
                'theme'   => $theme->get( 'Name' ),
            ] );
        } else {
            wp_send_json_error( 'Could not activate theme.' );
        }
    }

    /**
     * AJAX: Apply global colors from a kit to Elementor settings.
     */
    public static function ajax_apply_colors(): void {
        check_ajax_referer( 'xvato_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $colors = (array) ( $_POST['colors'] ?? [] );
        if ( empty( $colors ) ) {
            wp_send_json_error( 'No colors provided.' );
        }

        if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
            wp_send_json_error( 'Elementor is not active.' );
        }

        // Get active Elementor kit
        $active_kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();
        if ( ! $active_kit_id ) {
            wp_send_json_error( 'No active Elementor kit found.' );
        }

        $kit_settings = get_post_meta( $active_kit_id, '_elementor_page_settings', true ) ?: [];

        // Map colors into Elementor's system_colors format
        $system_colors = $kit_settings['system_colors'] ?? [];
        $custom_colors = $kit_settings['custom_colors'] ?? [];

        foreach ( $colors as $color ) {
            $id    = sanitize_key( $color['id'] ?? wp_generate_uuid4() );
            $title = sanitize_text_field( $color['title'] ?? 'Color' );
            $value = sanitize_hex_color( $color['color'] ?? '' );

            if ( ! $value ) continue;

            $custom_colors[] = [
                '_id'   => $id,
                'title' => $title,
                'color' => $value,
            ];
        }

        $kit_settings['custom_colors'] = $custom_colors;
        update_post_meta( $active_kit_id, '_elementor_page_settings', $kit_settings );

        // Clear Elementor CSS cache
        if ( class_exists( '\Elementor\Plugin' ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        wp_send_json_success( [
            'message' => count( $colors ) . ' color(s) applied to Elementor kit.',
        ] );
    }

    // ─── Settings Export / Import ─────────────────────────────

    public static function handle_export_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_export_settings', 'xvato_nonce' );

        $settings = [
            'xvato_version'    => XVATO_VERSION,
            'exported_at'       => current_time( 'mysql' ),
            'site_url'          => home_url(),
            'settings' => [
                'rate_limit'    => get_option( 'xvato_rate_limit', 10 ),
                'cleanup_hours' => get_option( 'xvato_cleanup_hours', 1 ),
                'auto_import'   => get_option( 'xvato_auto_import', '1' ),
                'keep_zip'      => get_option( 'xvato_keep_zip', '1' ),
            ],
        ];

        $filename = 'xvato-settings-' . gmdate( 'Y-m-d' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( wp_json_encode( $settings, JSON_PRETTY_PRINT ) ) );

        echo wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public static function handle_import_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_import_settings', 'xvato_nonce' );

        if ( empty( $_FILES['xvato_settings_file'] ) || $_FILES['xvato_settings_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_redirect( admin_url( 'admin.php?page=xvato-settings&import_error=no_file' ) );
            exit;
        }

        $file = $_FILES['xvato_settings_file'];

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( 'json' !== $ext ) {
            wp_redirect( admin_url( 'admin.php?page=xvato-settings&import_error=invalid_type' ) );
            exit;
        }

        $contents = file_get_contents( $file['tmp_name'] );
        $data     = json_decode( $contents, true );

        if ( ! is_array( $data ) || empty( $data['settings'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato-settings&import_error=invalid_json' ) );
            exit;
        }

        $s = $data['settings'];

        if ( isset( $s['rate_limit'] ) ) {
            update_option( 'xvato_rate_limit', max( 1, min( 100, absint( $s['rate_limit'] ) ) ) );
        }
        if ( isset( $s['cleanup_hours'] ) ) {
            update_option( 'xvato_cleanup_hours', max( 1, min( 168, absint( $s['cleanup_hours'] ) ) ) );
        }
        if ( isset( $s['auto_import'] ) ) {
            update_option( 'xvato_auto_import', $s['auto_import'] ? '1' : '0' );
        }
        if ( isset( $s['keep_zip'] ) ) {
            update_option( 'xvato_keep_zip', $s['keep_zip'] ? '1' : '0' );
        }

        wp_redirect( admin_url( 'admin.php?page=xvato-settings&imported_settings=1' ) );
        exit;
    }
}
