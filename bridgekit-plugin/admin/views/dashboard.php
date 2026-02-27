<?php
/**
 * BridgeKit — Dashboard View (Template Library Grid)
 *
 * @package BridgeKit
 */

defined( 'ABSPATH' ) || exit;

$search  = sanitize_text_field( $_GET['s'] ?? '' );
$status  = sanitize_key( $_GET['status'] ?? '' );
$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page = 12;

// Query templates
$args = [
    'post_type'      => BRIDGEKIT_CPT,
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
$counts = BridgeKit\Library::get_status_counts();

// Flash messages
$imported     = absint( $_GET['imported'] ?? 0 );
$deleted      = absint( $_GET['deleted'] ?? 0 );
$bulk_deleted = absint( $_GET['bulk_deleted'] ?? 0 );
$bulk_reimport = absint( $_GET['bulk_reimport'] ?? 0 );
$error        = sanitize_text_field( $_GET['error'] ?? '' );
$reimport     = sanitize_text_field( $_GET['reimport'] ?? '' );
?>

<div class="wrap bk-dashboard">
    <h1 class="wp-heading-inline">
        <?php esc_html_e( 'BridgeKit Template Library', 'bridgekit' ); ?>
    </h1>

    <!-- Flash Messages -->
    <?php if ( $imported ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( esc_html__( 'Template imported successfully! (ID: %d)', 'bridgekit' ), $imported ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $deleted ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Template deleted.', 'bridgekit' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $bulk_deleted ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( esc_html( _n( '%d template deleted.', '%d templates deleted.', $bulk_deleted, 'bridgekit' ) ), $bulk_deleted ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $bulk_reimport ) : ?>
        <div class="notice notice-info is-dismissible">
            <p><?php printf( esc_html( _n( '%d template queued for re-import.', '%d templates queued for re-import.', $bulk_reimport, 'bridgekit' ) ), $bulk_reimport ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $reimport ) : ?>
        <div class="notice notice-info is-dismissible">
            <p><?php esc_html_e( 'Re-import started.', 'bridgekit' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $error ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( $error ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Stats Bar -->
    <div class="bk-stats">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bridgekit' ) ); ?>" class="bk-stat <?php echo ! $status ? 'active' : ''; ?>">
            <span class="bk-stat-count"><?php echo esc_html( $counts['total'] ); ?></span>
            <span class="bk-stat-label"><?php esc_html_e( 'All', 'bridgekit' ); ?></span>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bridgekit&status=complete' ) ); ?>" class="bk-stat <?php echo 'complete' === $status ? 'active' : ''; ?>">
            <span class="bk-stat-count"><?php echo esc_html( $counts['complete'] ); ?></span>
            <span class="bk-stat-label"><?php esc_html_e( 'Imported', 'bridgekit' ); ?></span>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bridgekit&status=pending' ) ); ?>" class="bk-stat <?php echo 'pending' === $status ? 'active' : ''; ?>">
            <span class="bk-stat-count"><?php echo esc_html( $counts['pending'] + $counts['downloading'] + $counts['extracting'] + $counts['importing'] ); ?></span>
            <span class="bk-stat-label"><?php esc_html_e( 'In Progress', 'bridgekit' ); ?></span>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bridgekit&status=failed' ) ); ?>" class="bk-stat <?php echo 'failed' === $status ? 'active' : ''; ?>">
            <span class="bk-stat-count"><?php echo esc_html( $counts['failed'] ); ?></span>
            <span class="bk-stat-label"><?php esc_html_e( 'Failed', 'bridgekit' ); ?></span>
        </a>
    </div>

    <!-- Search + Upload Bar -->
    <div class="bk-toolbar">
        <form method="get" class="bk-search-form">
            <input type="hidden" name="page" value="bridgekit">
            <?php if ( $status ) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
            <?php endif; ?>
            <input
                type="search"
                name="s"
                value="<?php echo esc_attr( $search ); ?>"
                placeholder="<?php esc_attr_e( 'Search templates…', 'bridgekit' ); ?>"
                class="bk-search-input"
            >
            <button type="submit" class="button"><?php esc_html_e( 'Search', 'bridgekit' ); ?></button>
        </form>

        <div class="bk-toolbar-right">
            <button type="button" class="button" id="bk-select-toggle" style="display:none;">
                <span class="dashicons dashicons-yes-alt" style="vertical-align: middle; margin-top: -2px;"></span>
                <?php esc_html_e( 'Select', 'bridgekit' ); ?>
            </button>
            <button type="button" class="button button-primary" id="bk-upload-toggle">
                <span class="dashicons dashicons-upload" style="vertical-align: middle; margin-top: -2px;"></span>
                <?php esc_html_e( 'Upload ZIP', 'bridgekit' ); ?>
            </button>
        </div>
    </div>

    <!-- Bulk Actions Bar (hidden by default, shown when cards are selected) -->
    <div id="bk-bulk-bar" class="bk-bulk-bar" style="display: none;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bk-bulk-form">
            <input type="hidden" name="action" value="bridgekit_bulk">
            <?php wp_nonce_field( 'bridgekit_bulk', 'bridgekit_nonce' ); ?>
            <div class="bk-bulk-bar-inner">
                <label class="bk-bulk-select-all">
                    <input type="checkbox" id="bk-select-all">
                    <strong id="bk-bulk-count">0</strong> <?php esc_html_e( 'selected', 'bridgekit' ); ?>
                </label>
                <div class="bk-bulk-actions">
                    <button type="submit" name="bulk_action" value="reimport" class="button" onclick="return confirm('<?php esc_attr_e( 'Re-import all selected templates?', 'bridgekit' ); ?>')">
                        <span class="dashicons dashicons-update" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-top:-1px;"></span>
                        <?php esc_html_e( 'Re-import', 'bridgekit' ); ?>
                    </button>
                    <button type="submit" name="bulk_action" value="delete" class="button bk-btn-danger" onclick="return confirm('<?php esc_attr_e( 'Delete all selected templates and their files? This cannot be undone.', 'bridgekit' ); ?>')">
                        <span class="dashicons dashicons-trash" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-top:-1px;"></span>
                        <?php esc_html_e( 'Delete', 'bridgekit' ); ?>
                    </button>
                    <button type="button" class="button" id="bk-bulk-cancel">
                        <?php esc_html_e( 'Cancel', 'bridgekit' ); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Upload Form (hidden by default) -->
    <div id="bk-upload-form" class="bk-upload-form" style="display: none;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="bridgekit_upload">
            <?php wp_nonce_field( 'bridgekit_upload', 'bridgekit_nonce' ); ?>

            <div class="bk-upload-fields">
                <div class="bk-upload-field">
                    <label for="bridgekit_title"><?php esc_html_e( 'Title', 'bridgekit' ); ?></label>
                    <input type="text" name="bridgekit_title" id="bridgekit_title" placeholder="<?php esc_attr_e( 'Template Kit Name', 'bridgekit' ); ?>" required>
                </div>
                <div class="bk-upload-field">
                    <label for="bridgekit_category"><?php esc_html_e( 'Category', 'bridgekit' ); ?></label>
                    <input type="text" name="bridgekit_category" id="bridgekit_category" placeholder="<?php esc_attr_e( 'e.g., Business, Portfolio', 'bridgekit' ); ?>">
                </div>
                <div class="bk-upload-field">
                    <label for="bridgekit_zip"><?php esc_html_e( 'ZIP File', 'bridgekit' ); ?></label>
                    <input type="file" name="bridgekit_zip" id="bridgekit_zip" accept=".zip" required>
                </div>
                <div class="bk-upload-field">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'bridgekit' ); ?></button>
                    <button type="button" class="button" id="bk-upload-cancel"><?php esc_html_e( 'Cancel', 'bridgekit' ); ?></button>
                </div>
            </div>
        </form>
    </div>

    <!-- Template Grid -->
    <?php if ( $query->have_posts() ) : ?>
        <div class="bk-grid">
            <?php while ( $query->have_posts() ) : $query->the_post();
                $post_id     = get_the_ID();
                $entry       = BridgeKit\Library::format_entry( get_post() );
                $status_class = 'bk-badge--' . esc_attr( $entry['status'] );
            ?>
                <div class="bk-card" data-id="<?php echo esc_attr( $post_id ); ?>">
                    <div class="bk-card-thumb">
                        <label class="bk-card-checkbox" title="<?php esc_attr_e( 'Select', 'bridgekit' ); ?>">
                            <input type="checkbox" name="bulk_ids[]" value="<?php echo esc_attr( $post_id ); ?>" form="bk-bulk-form" class="bk-bulk-check">
                        </label>
                        <img
                            src="<?php echo esc_url( $entry['thumbnail_url'] ); ?>"
                            alt="<?php echo esc_attr( $entry['title'] ); ?>"
                            loading="lazy"
                        >
                        <span class="bk-badge <?php echo esc_attr( $status_class ); ?>">
                            <?php echo esc_html( ucfirst( $entry['status'] ) ); ?>
                        </span>
                    </div>
                    <div class="bk-card-body">
                        <h3 class="bk-card-title"><?php echo esc_html( $entry['title'] ); ?></h3>
                        <?php if ( $entry['category'] ) : ?>
                            <span class="bk-card-category"><?php echo esc_html( $entry['category'] ); ?></span>
                        <?php endif; ?>
                        <span class="bk-card-date"><?php echo esc_html( human_time_diff( strtotime( $entry['created'] ), time() ) ); ?> ago</span>

                        <?php if ( ! empty( $entry['dependencies'] ) ) : ?>
                            <div class="bk-card-deps">
                                <?php if ( ! empty( $entry['dependencies']['elementor_pro'] ) ) : ?>
                                    <span class="bk-dep bk-dep--warning">Elementor Pro Required</span>
                                <?php endif; ?>
                                <?php if ( ! empty( $entry['dependencies']['theme'] ) ) : ?>
                                    <span class="bk-dep"><?php echo esc_html( $entry['dependencies']['theme'] ); ?> Theme</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $entry['error'] ) : ?>
                            <p class="bk-card-error" title="<?php echo esc_attr( $entry['error'] ); ?>">
                                <?php echo esc_html( wp_trim_words( $entry['error'], 10 ) ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="bk-card-actions">
                        <?php if ( $entry['source_url'] ) : ?>
                            <a href="<?php echo esc_url( $entry['source_url'] ); ?>" target="_blank" rel="noopener" class="button button-small" title="<?php esc_attr_e( 'View on Envato', 'bridgekit' ); ?>">
                                <span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                            </a>
                        <?php endif; ?>

                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bridgekit_reimport&id=' . $post_id ), 'bridgekit_reimport', 'bridgekit_nonce' ) ); ?>"
                           class="button button-small"
                           title="<?php esc_attr_e( 'Re-import', 'bridgekit' ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Re-import this template kit?', 'bridgekit' ); ?>')">
                            <span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                        </a>

                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bridgekit_delete&id=' . $post_id ), 'bridgekit_delete', 'bridgekit_nonce' ) ); ?>"
                           class="button button-small bk-btn-danger"
                           title="<?php esc_attr_e( 'Delete', 'bridgekit' ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Delete this template and its stored files?', 'bridgekit' ); ?>')">
                            <span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                        </a>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <!-- Pagination -->
        <?php if ( $query->max_num_pages > 1 ) : ?>
            <div class="bk-pagination">
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
        <div class="bk-empty">
            <div class="bk-empty-icon">
                <span class="dashicons dashicons-download" style="font-size: 48px; width: 48px; height: 48px; color: #ccc;"></span>
            </div>
            <h2><?php esc_html_e( 'No templates yet', 'bridgekit' ); ?></h2>
            <p><?php esc_html_e( 'Use the BridgeKit Chrome Extension to import templates from Envato Elements, or upload a ZIP file manually.', 'bridgekit' ); ?></p>
        </div>
    <?php endif; ?>
</div>
