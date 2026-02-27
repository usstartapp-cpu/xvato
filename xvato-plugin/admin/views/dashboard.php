<?php
/**
 * Xvato — Dashboard View (Template Library Grid)
 *
 * @package Xvato
 */

defined( 'ABSPATH' ) || exit;

$search  = sanitize_text_field( $_GET['s'] ?? '' );
$status  = sanitize_key( $_GET['status'] ?? '' );
$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page = 12;

// Query templates
$args = [
    'post_type'      => XVATO_CPT,
    'posts_per_page' => $per_page,
    'paged'          => $paged,
    'post_status'    => 'any',
    'orderby'        => 'date',
    'order'          => 'DESC',
];

if ( $search ) {
    $args['s'] = $search;
}

if ( $status ) {
    $args['meta_query'] = [
        [
            'key'   => '_bk_import_status',
            'value' => $status,
        ],
    ];
}

$query  = new WP_Query( $args );
$counts = Xvato\Library::get_status_counts();

// Flash messages
$imported     = absint( $_GET['imported'] ?? 0 );
$deleted      = absint( $_GET['deleted'] ?? 0 );
$bulk_deleted = absint( $_GET['bulk_deleted'] ?? 0 );
$bulk_reimport = absint( $_GET['bulk_reimport'] ?? 0 );
$error        = sanitize_text_field( $_GET['error'] ?? '' );
$reimport     = sanitize_text_field( $_GET['reimport'] ?? '' );
?>

<div class="xv-wrap xv-dark-page xv-dashboard" data-xv-theme="dark">
    <!-- Page Header -->
    <div class="xv-page-header">
        <div class="xv-page-title-group">
            <img src="<?php echo esc_url( XVATO_URL . 'assets/xvato-logo.png' ); ?>" alt="Xvato" class="xv-page-logo">
            <h1 class="xv-page-title">
                <span class="xv-page-title-accent">Xvato</span> Template Library
            </h1>
        </div>
        <div class="xv-page-actions">
            <span class="xv-version-badge">v<?php echo esc_html( XVATO_VERSION ); ?></span>
            <button type="button" class="xv-theme-btn" id="xv-theme-toggle" title="Toggle light/dark mode">
                <span class="xv-theme-icon-sun">☀️</span>
                <span class="xv-theme-icon-moon">🌙</span>
            </button>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ( $imported ) : ?>
        <div class="xv-notice xv-notice--success">
            <span>✓</span>
            <p><?php printf( esc_html__( 'Template imported successfully! (ID: %d)', 'xvato' ), $imported ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( $deleted ) : ?>
        <div class="xv-notice xv-notice--success">
            <span>✓</span>
            <p><?php esc_html_e( 'Template deleted.', 'xvato' ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( $bulk_deleted ) : ?>
        <div class="xv-notice xv-notice--success">
            <span>✓</span>
            <p><?php printf( esc_html( _n( '%d template deleted.', '%d templates deleted.', $bulk_deleted, 'xvato' ) ), $bulk_deleted ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( $bulk_reimport ) : ?>
        <div class="xv-notice xv-notice--info">
            <span>↻</span>
            <p><?php printf( esc_html( _n( '%d template queued for re-import.', '%d templates queued for re-import.', $bulk_reimport, 'xvato' ) ), $bulk_reimport ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( $reimport ) : ?>
        <div class="xv-notice xv-notice--info">
            <span>↻</span>
            <p><?php esc_html_e( 'Re-import started.', 'xvato' ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( $error ) : ?>
        <div class="xv-notice xv-notice--error">
            <span>✗</span>
            <p><?php echo esc_html( $error ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <!-- Stats Bar -->
    <div class="xv-stats">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=xvato' ) ); ?>" class="xv-stat <?php echo ! $status ? 'active' : ''; ?>">
            <span class="xv-stat-count"><?php echo esc_html( $counts['total'] ); ?></span>
            <span class="xv-stat-label"><?php esc_html_e( 'All', 'xvato' ); ?></span>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=xvato&status=complete' ) ); ?>" class="xv-stat <?php echo 'complete' === $status ? 'active' : ''; ?>">
            <span class="xv-stat-count"><?php echo esc_html( $counts['complete'] ); ?></span>
            <span class="xv-stat-label"><?php esc_html_e( 'Imported', 'xvato' ); ?></span>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=xvato&status=pending' ) ); ?>" class="xv-stat <?php echo 'pending' === $status ? 'active' : ''; ?>">
            <span class="xv-stat-count"><?php echo esc_html( $counts['pending'] + $counts['downloading'] + $counts['extracting'] + $counts['importing'] ); ?></span>
            <span class="xv-stat-label"><?php esc_html_e( 'In Progress', 'xvato' ); ?></span>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=xvato&status=failed' ) ); ?>" class="xv-stat <?php echo 'failed' === $status ? 'active' : ''; ?>">
            <span class="xv-stat-count"><?php echo esc_html( $counts['failed'] ); ?></span>
            <span class="xv-stat-label"><?php esc_html_e( 'Failed', 'xvato' ); ?></span>
        </a>
    </div>

    <!-- Search + Upload Bar -->
    <div class="xv-toolbar">
        <form method="get" class="xv-search-form">
            <input type="hidden" name="page" value="xvato">
            <?php if ( $status ) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
            <?php endif; ?>
            <input
                type="search"
                name="s"
                value="<?php echo esc_attr( $search ); ?>"
                placeholder="<?php esc_attr_e( 'Search templates…', 'xvato' ); ?>"
                class="xv-search-input"
            >
            <button type="submit" class="xv-btn"><?php esc_html_e( 'Search', 'xvato' ); ?></button>
        </form>

        <div class="xv-toolbar-right">
            <button type="button" class="xv-btn" id="xv-select-toggle" style="display:none;">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e( 'Select', 'xvato' ); ?>
            </button>
            <button type="button" class="xv-btn xv-btn--primary" id="xv-upload-toggle">
                <span class="dashicons dashicons-upload"></span>
                <?php esc_html_e( 'Upload ZIP', 'xvato' ); ?>
            </button>
        </div>
    </div>

    <!-- Bulk Actions Bar (hidden by default) -->
    <div id="xv-bulk-bar" class="xv-bulk-bar" style="display: none;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="xv-bulk-form">
            <input type="hidden" name="action" value="xvato_bulk">
            <?php wp_nonce_field( 'xvato_bulk', 'xvato_nonce' ); ?>
            <div class="xv-bulk-bar-inner">
                <label class="xv-bulk-select-all">
                    <input type="checkbox" id="xv-select-all">
                    <strong id="xv-bulk-count">0</strong> <?php esc_html_e( 'selected', 'xvato' ); ?>
                </label>
                <div class="xv-bulk-actions">
                    <button type="submit" name="bulk_action" value="reimport" class="xv-btn" onclick="return confirm('<?php esc_attr_e( 'Re-import all selected templates?', 'xvato' ); ?>')">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Re-import', 'xvato' ); ?>
                    </button>
                    <button type="submit" name="bulk_action" value="delete" class="xv-btn xv-btn--danger" onclick="return confirm('<?php esc_attr_e( 'Delete all selected templates and their files? This cannot be undone.', 'xvato' ); ?>')">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e( 'Delete', 'xvato' ); ?>
                    </button>
                    <button type="button" class="xv-btn" id="xv-bulk-cancel">
                        <?php esc_html_e( 'Cancel', 'xvato' ); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Upload Form (hidden by default) -->
    <div id="xv-upload-form" class="xv-upload-form" style="display: none;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="xvato_upload">
            <?php wp_nonce_field( 'xvato_upload', 'xvato_nonce' ); ?>

            <div class="xv-upload-fields">
                <div class="xv-upload-field">
                    <label for="xvato_title"><?php esc_html_e( 'Title', 'xvato' ); ?></label>
                    <input type="text" name="xvato_title" id="xvato_title" placeholder="<?php esc_attr_e( 'Template Kit Name', 'xvato' ); ?>" required>
                </div>
                <div class="xv-upload-field">
                    <label for="xvato_category"><?php esc_html_e( 'Category', 'xvato' ); ?></label>
                    <input type="text" name="xvato_category" id="xvato_category" placeholder="<?php esc_attr_e( 'e.g., Business, Portfolio', 'xvato' ); ?>">
                </div>
                <div class="xv-upload-field">
                    <label for="xvato_zip"><?php esc_html_e( 'ZIP File', 'xvato' ); ?></label>
                    <input type="file" name="xvato_zip" id="xvato_zip" accept=".zip" required>
                </div>
                <div class="xv-upload-field">
                    <button type="submit" class="xv-btn xv-btn--primary"><?php esc_html_e( 'Import', 'xvato' ); ?></button>
                    <button type="button" class="xv-btn" id="xv-upload-cancel"><?php esc_html_e( 'Cancel', 'xvato' ); ?></button>
                </div>
            </div>
        </form>
    </div>

    <!-- Template Grid -->
    <?php if ( $query->have_posts() ) : ?>
        <div class="xv-grid">
            <?php while ( $query->have_posts() ) : $query->the_post();
                $post_id     = get_the_ID();
                $entry       = Xvato\Library::format_entry( get_post() );
                $status_class = 'xv-badge--' . esc_attr( $entry['status'] );
            ?>
                <div class="xv-card" data-id="<?php echo esc_attr( $post_id ); ?>">
                    <div class="xv-card-thumb">
                        <label class="xv-card-checkbox" title="<?php esc_attr_e( 'Select', 'xvato' ); ?>">
                            <input type="checkbox" name="bulk_ids[]" value="<?php echo esc_attr( $post_id ); ?>" form="xv-bulk-form" class="xv-bulk-check">
                        </label>
                        <img
                            src="<?php echo esc_url( $entry['thumbnail_url'] ); ?>"
                            alt="<?php echo esc_attr( $entry['title'] ); ?>"
                            loading="lazy"
                        >
                        <span class="xv-badge <?php echo esc_attr( $status_class ); ?>">
                            <?php echo esc_html( ucfirst( $entry['status'] ) ); ?>
                        </span>
                    </div>
                    <div class="xv-card-body">
                        <h3 class="xv-card-title"><?php echo esc_html( $entry['title'] ); ?></h3>
                        <?php if ( $entry['category'] ) : ?>
                            <span class="xv-card-category"><?php echo esc_html( $entry['category'] ); ?></span>
                        <?php endif; ?>
                        <span class="xv-card-date"><?php echo esc_html( human_time_diff( strtotime( $entry['created'] ), time() ) ); ?> ago</span>

                        <?php if ( ! empty( $entry['dependencies'] ) ) : ?>
                            <div class="xv-card-deps">
                                <?php if ( ! empty( $entry['dependencies']['elementor_pro'] ) ) : ?>
                                    <span class="xv-dep xv-dep--warning">Elementor Pro Required</span>
                                <?php endif; ?>
                                <?php if ( ! empty( $entry['dependencies']['theme'] ) ) : ?>
                                    <span class="xv-dep"><?php echo esc_html( $entry['dependencies']['theme'] ); ?> Theme</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $entry['error'] ) : ?>
                            <p class="xv-card-error" title="<?php echo esc_attr( $entry['error'] ); ?>">
                                <?php echo esc_html( wp_trim_words( $entry['error'], 10 ) ); ?>
                            </p>
                        <?php endif; ?>

                        <?php
                        $log = get_post_meta( $post_id, '_bk_import_log', true );
                        if ( ! empty( $log ) && is_array( $log ) ) :
                            $last_log = end( $log );
                        ?>
                            <p class="xv-card-log" title="<?php echo esc_attr( $last_log['message'] ?? '' ); ?>">
                                <small><?php echo esc_html( $last_log['message'] ?? '' ); ?></small>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="xv-card-actions">
                        <!-- Setup Kit — the main workflow entry point -->
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=xvato-kit&kit_id=' . $post_id ) ); ?>"
                           class="xv-btn xv-btn--sm xv-btn--primary"
                           title="<?php esc_attr_e( 'Setup Kit — preview, activate theme, choose pages', 'xvato' ); ?>">
                            <span class="dashicons dashicons-admin-customizer"></span>
                            <?php esc_html_e( 'Setup', 'xvato' ); ?>
                        </a>

                        <?php if ( $entry['source_url'] ) : ?>
                            <a href="<?php echo esc_url( $entry['source_url'] ); ?>" target="_blank" rel="noopener" class="xv-btn xv-btn--sm" title="<?php esc_attr_e( 'View on Envato', 'xvato' ); ?>">
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        <?php endif; ?>

                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xvato_reimport&id=' . $post_id ), 'xvato_reimport', 'xvato_nonce' ) ); ?>"
                           class="xv-btn xv-btn--sm"
                           title="<?php esc_attr_e( 'Re-import', 'xvato' ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Re-import this template kit?', 'xvato' ); ?>')">
                            <span class="dashicons dashicons-update"></span>
                        </a>

                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xvato_delete&id=' . $post_id ), 'xvato_delete', 'xvato_nonce' ) ); ?>"
                           class="xv-btn xv-btn--sm xv-btn--danger"
                           title="<?php esc_attr_e( 'Delete', 'xvato' ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Delete this template and its stored files?', 'xvato' ); ?>')">
                            <span class="dashicons dashicons-trash"></span>
                        </a>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <!-- Pagination -->
        <?php if ( $query->max_num_pages > 1 ) : ?>
            <div class="xv-pagination">
                <?php
                echo paginate_links( [
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $query->max_num_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                ?>
            </div>
        <?php endif; ?>

        <?php wp_reset_postdata(); ?>

    <?php else : ?>
        <div class="xv-empty">
            <div class="xv-empty-icon">
                <span class="dashicons dashicons-download"></span>
            </div>
            <h2><?php esc_html_e( 'No templates yet', 'xvato' ); ?></h2>
            <p><?php esc_html_e( 'Use the Xvato Chrome Extension to import templates from Envato Elements, or upload a ZIP file manually.', 'xvato' ); ?></p>
        </div>
    <?php endif; ?>
</div>
