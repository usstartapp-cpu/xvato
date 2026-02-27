<?php
/**
 * Xvato — Kit Detail View
 *
 * Workflow after a ZIP is imported:
 *  1. Review the kit — see which templates/pages are inside
 *  2. Activate the required theme (install if missing)
 *  3. Select which templates/pages to import
 *  4. Optionally create WordPress pages linked to imported templates
 *  5. Optionally apply the kit's global colours to Elementor
 *
 * @package Xvato
 */

defined( 'ABSPATH' ) || exit;

$kit_id = absint( $_GET['kit_id'] ?? 0 );
if ( ! $kit_id ) {
    echo '<div class="xv-wrap xv-dark-page" data-xv-theme="dark"><p>' . esc_html__( 'Invalid kit ID.', 'xvato' ) . '</p></div>';
    return;
}

$post = get_post( $kit_id );
if ( ! $post || XVATO_CPT !== $post->post_type ) {
    echo '<div class="xv-wrap xv-dark-page" data-xv-theme="dark"><p>' . esc_html__( 'Kit not found.', 'xvato' ) . '</p></div>';
    return;
}

$entry = Xvato\Library::format_entry( $post );
$fresh = ! empty( $_GET['fresh'] );
?>

<div class="xv-wrap xv-dark-page xv-kit-detail" data-xv-theme="dark" data-kit-id="<?php echo esc_attr( $kit_id ); ?>">

    <!-- Back Link -->
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=xvato' ) ); ?>" class="xv-kit-back">
        ← <?php esc_html_e( 'Back to Library', 'xvato' ); ?>
    </a>

    <?php if ( $fresh ) : ?>
        <div class="xv-notice xv-notice--success" style="margin-bottom:20px;">
            <span>✓</span>
            <p><?php esc_html_e( 'Template kit imported successfully! Follow the steps below to set up your site.', 'xvato' ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <!-- Kit Header -->
    <div class="xv-kit-header">
        <div class="xv-kit-header-left">
            <img src="<?php echo esc_url( $entry['thumbnail_url'] ); ?>" alt="" class="xv-kit-thumb">
            <div class="xv-kit-info">
                <h1><?php echo esc_html( $entry['title'] ); ?></h1>
                <p>
                    <?php if ( $entry['category'] ) : ?>
                        <span class="xv-card-category"><?php echo esc_html( $entry['category'] ); ?></span>
                    <?php endif; ?>
                    <?php
                    printf(
                        /* translators: %s = status */
                        esc_html__( 'Status: %s', 'xvato' ),
                        '<strong>' . esc_html( ucfirst( $entry['status'] ) ) . '</strong>'
                    );
                    ?>
                </p>
            </div>
        </div>
        <div class="xv-kit-header-right">
            <span class="xv-version-badge">v<?php echo esc_html( XVATO_VERSION ); ?></span>
            <button type="button" class="xv-theme-btn" id="xv-theme-toggle" title="Toggle light/dark mode">
                <span class="xv-theme-icon-sun">☀️</span>
                <span class="xv-theme-icon-moon">🌙</span>
            </button>
        </div>
    </div>

    <!-- Loading state (replaced by JS) -->
    <div id="xv-kit-loader" class="xv-kit-loading">
        <span class="xv-spinner xv-spinner--lg"></span>
        <?php esc_html_e( 'Analysing template kit…', 'xvato' ); ?>
    </div>

    <!-- Kit Sections (populated by JS) -->
    <div id="xv-kit-content" class="xv-kit-sections" style="display:none;">

        <!-- Section 1: Theme Requirement -->
        <div class="xv-kit-section" id="xv-section-theme">
            <div class="xv-kit-section-header">
                <div class="xv-kit-section-header-left">
                    <span class="xv-kit-section-step" id="xv-step-theme">1</span>
                    <div>
                        <h2 class="xv-kit-section-title"><?php esc_html_e( 'Required Theme', 'xvato' ); ?></h2>
                        <p class="xv-kit-section-subtitle"><?php esc_html_e( 'This kit works best with a specific theme', 'xvato' ); ?></p>
                    </div>
                </div>
            </div>
            <div class="xv-kit-section-body" id="xv-theme-body">
                <!-- Filled by JS -->
            </div>
        </div>

        <!-- Section 2: Select Templates -->
        <div class="xv-kit-section" id="xv-section-templates">
            <div class="xv-kit-section-header">
                <div class="xv-kit-section-header-left">
                    <span class="xv-kit-section-step">2</span>
                    <div>
                        <h2 class="xv-kit-section-title"><?php esc_html_e( 'Select Templates', 'xvato' ); ?></h2>
                        <p class="xv-kit-section-subtitle"><?php esc_html_e( 'Choose which pages and templates to import', 'xvato' ); ?></p>
                    </div>
                </div>
            </div>
            <div class="xv-kit-section-body">
                <div class="xv-tpl-select-bar">
                    <label>
                        <input type="checkbox" id="xv-select-all-tpl">
                        <?php esc_html_e( 'Select All', 'xvato' ); ?>
                        <span id="xv-tpl-count" style="color:var(--xv-text-muted);"></span>
                    </label>
                    <div class="xv-tpl-filter-btns" id="xv-tpl-filters">
                        <!-- Populated by JS: All, Pages, Headers, Footers, etc. -->
                    </div>
                </div>
                <div class="xv-tpl-grid" id="xv-tpl-grid">
                    <!-- Filled by JS -->
                </div>
            </div>
        </div>

        <!-- Section 3: Global Colors -->
        <div class="xv-kit-section" id="xv-section-colors">
            <div class="xv-kit-section-header">
                <div class="xv-kit-section-header-left">
                    <span class="xv-kit-section-step">3</span>
                    <div>
                        <h2 class="xv-kit-section-title"><?php esc_html_e( 'Global Colours', 'xvato' ); ?></h2>
                        <p class="xv-kit-section-subtitle"><?php esc_html_e( 'Apply this kit\'s colour palette to Elementor', 'xvato' ); ?></p>
                    </div>
                </div>
                <button type="button" class="xv-btn xv-btn--sm" id="xv-apply-colors" style="display:none;">
                    <span class="dashicons dashicons-art"></span>
                    <?php esc_html_e( 'Apply Colours', 'xvato' ); ?>
                </button>
            </div>
            <div class="xv-kit-section-body" id="xv-colors-body">
                <!-- Filled by JS -->
            </div>
        </div>

        <!-- Section 4: Import -->
        <div class="xv-kit-section" id="xv-section-import">
            <div class="xv-kit-section-header">
                <div class="xv-kit-section-header-left">
                    <span class="xv-kit-section-step">4</span>
                    <div>
                        <h2 class="xv-kit-section-title"><?php esc_html_e( 'Import & Create Pages', 'xvato' ); ?></h2>
                        <p class="xv-kit-section-subtitle"><?php esc_html_e( 'Import selected templates and create WordPress pages', 'xvato' ); ?></p>
                    </div>
                </div>
            </div>
            <div class="xv-kit-section-body">
                <div class="xv-import-options">
                    <label class="xv-import-option">
                        <input type="checkbox" id="xv-create-pages" checked>
                        <div class="xv-import-option-label">
                            <strong><?php esc_html_e( 'Create WordPress Pages', 'xvato' ); ?></strong>
                            <span><?php esc_html_e( 'Automatically create draft pages for each selected template, with the design already applied.', 'xvato' ); ?></span>
                        </div>
                    </label>
                </div>

                <div id="xv-import-progress" style="display:none;">
                    <p id="xv-import-status" style="font-size:13px;color:var(--xv-text-secondary);margin:0 0 8px;">
                        <?php esc_html_e( 'Importing…', 'xvato' ); ?>
                    </p>
                    <div class="xv-progress">
                        <div class="xv-progress-bar" id="xv-progress-bar"></div>
                    </div>
                </div>

                <div id="xv-import-results" class="xv-import-results" style="display:none;">
                    <!-- Filled by JS -->
                </div>
            </div>
            <div class="xv-kit-footer">
                <div class="xv-kit-footer-info">
                    <span id="xv-selected-count">0</span> <?php esc_html_e( 'templates selected', 'xvato' ); ?>
                </div>
                <div class="xv-kit-footer-actions">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=xvato' ) ); ?>" class="xv-btn">
                        <?php esc_html_e( 'Cancel', 'xvato' ); ?>
                    </a>
                    <button type="button" class="xv-btn xv-btn--primary" id="xv-import-btn" disabled>
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Import Selected', 'xvato' ); ?>
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /#xv-kit-content -->
</div>
