<?php
/**
 * Xvato — Settings Page
 *
 * Connection info, API key management, system diagnostics.
 * Dark-mode-first with Envato-style sleek UI.
 *
 * @package Xvato
 */

defined( 'ABSPATH' ) || exit;

// Handle settings save
if ( isset( $_POST['xvato_save_settings'] ) ) {
    check_admin_referer( 'xvato_settings', 'xvato_settings_nonce' );

    $rate_limit = absint( $_POST['xvato_rate_limit'] ?? 10 );
    $cleanup_hours = absint( $_POST['xvato_cleanup_hours'] ?? 1 );
    $auto_import = isset( $_POST['xvato_auto_import'] ) ? '1' : '0';
    $keep_zip = isset( $_POST['xvato_keep_zip'] ) ? '1' : '0';

    update_option( 'xvato_rate_limit', max( 1, min( 100, $rate_limit ) ) );
    update_option( 'xvato_cleanup_hours', max( 1, min( 168, $cleanup_hours ) ) );
    update_option( 'xvato_auto_import', $auto_import );
    update_option( 'xvato_keep_zip', $keep_zip );

    $saved = true;
}

// Current settings
$rate_limit    = get_option( 'xvato_rate_limit', 10 );
$cleanup_hours = get_option( 'xvato_cleanup_hours', 1 );
$auto_import   = get_option( 'xvato_auto_import', '1' );
$keep_zip      = get_option( 'xvato_keep_zip', '1' );

// System info
$upload_dir       = wp_upload_dir();
$tmp_dir          = trailingslashit( $upload_dir['basedir'] ) . XVATO_TMP_DIR;
$tmp_exists       = is_dir( $tmp_dir );
$tmp_writable     = $tmp_exists && is_writable( $tmp_dir );
$tmp_size         = 0;

if ( $tmp_exists ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $tmp_dir, RecursiveDirectoryIterator::SKIP_DOTS )
    );
    foreach ( $files as $file ) {
        $tmp_size += $file->getSize();
    }
}

$elementor_active = defined( 'ELEMENTOR_VERSION' );
$elementor_version = $elementor_active ? ELEMENTOR_VERSION : null;
$elementor_pro    = defined( 'ELEMENTOR_PRO_VERSION' );
$wp_cli_available = false;
if ( function_exists( 'exec' ) ) {
    exec( 'which wp 2>/dev/null', $output, $return );
    $wp_cli_available = ( 0 === $return );
}

$zip_available   = class_exists( 'ZipArchive' );
$rest_url        = rest_url( XVATO_REST_NAMESPACE );
$has_app_passwords = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
$next_cleanup    = wp_next_scheduled( 'xvato_cleanup_tmp' );
$counts          = Xvato\Library::get_status_counts();
?>

<div class="xv-wrap xv-dark-page xv-settings" data-xv-theme="dark">
    <!-- Page Header -->
    <div class="xv-page-header">
        <div class="xv-page-title-group">
            <img src="<?php echo esc_url( XVATO_URL . 'assets/xvato-logo.png' ); ?>" alt="Xvato" class="xv-page-logo">
            <h1 class="xv-page-title">
                <span class="xv-page-title-accent">Xvato</span> Settings
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
    <?php if ( ! empty( $saved ) ) : ?>
        <div class="xv-notice xv-notice--success">
            <span>✓</span>
            <p><?php esc_html_e( 'Settings saved.', 'xvato' ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['cleared'] ) ) : ?>
        <div class="xv-notice xv-notice--success">
            <span>✓</span>
            <p><?php esc_html_e( 'Temporary files cleared.', 'xvato' ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['reset'] ) ) : ?>
        <div class="xv-notice xv-notice--success">
            <span>✓</span>
            <p><?php printf( esc_html__( '%d failed import(s) reset to pending.', 'xvato' ), absint( $_GET['reset'] ) ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['imported_settings'] ) ) : ?>
        <div class="xv-notice xv-notice--success">
            <span>✓</span>
            <p><?php esc_html_e( 'Settings imported successfully.', 'xvato' ); ?></p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['import_error'] ) ) : ?>
        <div class="xv-notice xv-notice--error">
            <span>✗</span>
            <p>
            <?php
            $import_err = sanitize_key( $_GET['import_error'] );
            switch ( $import_err ) {
                case 'no_file':
                    esc_html_e( 'No file selected for import.', 'xvato' );
                    break;
                case 'invalid_type':
                    esc_html_e( 'Invalid file type. Please upload a .json file.', 'xvato' );
                    break;
                case 'invalid_json':
                    esc_html_e( 'Invalid settings file. Could not parse the JSON data.', 'xvato' );
                    break;
                default:
                    esc_html_e( 'Settings import failed.', 'xvato' );
            }
            ?>
            </p>
            <button class="xv-notice-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <div class="xv-settings-layout">
        <!-- ─── Left Column: Settings ──────────────────────────── -->
        <div class="xv-settings-main">

            <!-- Chrome Extension Setup -->
            <div class="xv-card">
                <h2 class="xv-card-header">
                    <span class="xv-header-icon">🔗</span>
                    <?php esc_html_e( 'Chrome Extension Setup', 'xvato' ); ?>
                </h2>
                <div class="xv-card-content">
                    <p style="color: var(--xv-text-secondary); margin-bottom: 16px;"><?php esc_html_e( 'Follow these steps to connect the Xvato Chrome Extension to your WordPress site:', 'xvato' ); ?></p>

                    <ol class="xv-steps">
                        <li>
                            <strong><?php esc_html_e( 'Install the Chrome Extension', 'xvato' ); ?></strong>
                            <p class="description"><?php esc_html_e( 'Load it as an unpacked extension from chrome://extensions or install from the Chrome Web Store.', 'xvato' ); ?></p>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Generate an Application Password', 'xvato' ); ?></strong>
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__( 'Go to %s → scroll to "Application Passwords" → enter a name like "Xvato" → click "Add New Application Password".', 'xvato' ),
                                    '<a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'Users → Profile', 'xvato' ) . '</a>'
                                );
                                ?>
                            </p>
                            <?php if ( ! $has_app_passwords ) : ?>
                                <div class="xv-notice xv-notice--warning" style="margin-top: 8px;">
                                    <span>⚠</span>
                                    <p><strong><?php esc_html_e( 'Application Passwords are not available.', 'xvato' ); ?></strong>
                                    <?php esc_html_e( 'Your site must be served over HTTPS, or use the "Application Passwords" plugin for HTTP development.', 'xvato' ); ?></p>
                                </div>
                            <?php endif; ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Configure the Extension', 'xvato' ); ?></strong>
                            <p class="description"><?php esc_html_e( 'Click the Xvato icon in Chrome, enter your site URL and credentials, then click "Test Connection".', 'xvato' ); ?></p>
                        </li>
                    </ol>

                    <div class="xv-connection-info">
                        <h4><?php esc_html_e( 'Your Connection Details', 'xvato' ); ?></h4>
                        <table class="xv-info-table">
                            <tr>
                                <th><?php esc_html_e( 'Site URL', 'xvato' ); ?></th>
                                <td>
                                    <code id="xv-site-url"><?php echo esc_html( home_url() ); ?></code>
                                    <button type="button" class="xv-btn xv-btn--sm xv-copy-btn" data-copy="xv-site-url" title="<?php esc_attr_e( 'Copy', 'xvato' ); ?>">
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'REST API', 'xvato' ); ?></th>
                                <td>
                                    <code id="xv-rest-url"><?php echo esc_html( $rest_url ); ?></code>
                                    <button type="button" class="xv-btn xv-btn--sm xv-copy-btn" data-copy="xv-rest-url" title="<?php esc_attr_e( 'Copy', 'xvato' ); ?>">
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Username', 'xvato' ); ?></th>
                                <td>
                                    <code id="xv-username"><?php echo esc_html( wp_get_current_user()->user_login ); ?></code>
                                    <button type="button" class="xv-btn xv-btn--sm xv-copy-btn" data-copy="xv-username" title="<?php esc_attr_e( 'Copy', 'xvato' ); ?>">
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Import Settings -->
            <div class="xv-card">
                <h2 class="xv-card-header">
                    <span class="xv-header-icon">⚙️</span>
                    <?php esc_html_e( 'Import Settings', 'xvato' ); ?>
                </h2>
                <div class="xv-card-content">
                    <form method="post">
                        <?php wp_nonce_field( 'xvato_settings', 'xvato_settings_nonce' ); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="xvato_auto_import"><?php esc_html_e( 'Auto-Import', 'xvato' ); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="xvato_auto_import" id="xvato_auto_import" value="1" <?php checked( $auto_import, '1' ); ?>>
                                        <?php esc_html_e( 'Automatically start importing when a template kit is received from the Chrome Extension', 'xvato' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="xvato_keep_zip"><?php esc_html_e( 'Keep ZIP Files', 'xvato' ); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="xvato_keep_zip" id="xvato_keep_zip" value="1" <?php checked( $keep_zip, '1' ); ?>>
                                        <?php esc_html_e( 'Keep downloaded ZIP files after import (allows re-importing later)', 'xvato' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="xvato_rate_limit"><?php esc_html_e( 'Rate Limit', 'xvato' ); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="xvato_rate_limit" id="xvato_rate_limit" value="<?php echo esc_attr( $rate_limit ); ?>" min="1" max="100" class="small-text">
                                    <span class="description"><?php esc_html_e( 'imports per 5-minute window', 'xvato' ); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="xvato_cleanup_hours"><?php esc_html_e( 'Temp Cleanup', 'xvato' ); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="xvato_cleanup_hours" id="xvato_cleanup_hours" value="<?php echo esc_attr( $cleanup_hours ); ?>" min="1" max="168" class="small-text">
                                    <span class="description"><?php esc_html_e( 'hours — delete temporary files older than this', 'xvato' ); ?></span>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" name="xvato_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'xvato' ); ?>">
                        </p>
                    </form>
                </div>
            </div>

            <!-- Maintenance -->
            <div class="xv-card xv-card--danger">
                <h2 class="xv-card-header">
                    <span class="xv-header-icon">🛠</span>
                    <?php esc_html_e( 'Maintenance', 'xvato' ); ?>
                </h2>
                <div class="xv-card-content">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Clear Temp Files', 'xvato' ); ?></th>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xvato_clear_tmp' ), 'xvato_clear_tmp', 'xvato_nonce' ) ); ?>"
                                   class="xv-btn"
                                   onclick="return confirm('<?php esc_attr_e( 'Delete all temporary files? This cannot be undone.', 'xvato' ); ?>')">
                                    <?php esc_html_e( 'Clear Now', 'xvato' ); ?>
                                </a>
                                <span class="description">
                                    <?php
                                    printf(
                                        esc_html__( 'Current temp directory size: %s', 'xvato' ),
                                        size_format( $tmp_size )
                                    );
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Reset Failed Imports', 'xvato' ); ?></th>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xvato_reset_failed' ), 'xvato_reset_failed', 'xvato_nonce' ) ); ?>"
                                   class="xv-btn"
                                   onclick="return confirm('<?php esc_attr_e( 'Reset all failed imports to pending? They will be re-processed.', 'xvato' ); ?>')">
                                    <?php esc_html_e( 'Reset Failed', 'xvato' ); ?>
                                </a>
                                <span class="description">
                                    <?php
                                    printf(
                                        esc_html( _n( '%d failed import', '%d failed imports', $counts['failed'], 'xvato' ) ),
                                        $counts['failed']
                                    );
                                    ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Export / Import Settings -->
            <div class="xv-card">
                <h2 class="xv-card-header">
                    <span class="xv-header-icon">📦</span>
                    <?php esc_html_e( 'Export / Import Settings', 'xvato' ); ?>
                </h2>
                <div class="xv-card-content">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Export', 'xvato' ); ?></th>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xvato_export_settings' ), 'xvato_export_settings', 'xvato_nonce' ) ); ?>"
                                   class="xv-btn">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e( 'Download Settings JSON', 'xvato' ); ?>
                                </a>
                                <p class="description"><?php esc_html_e( 'Export your Xvato settings to a JSON file for backup or migration.', 'xvato' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Import', 'xvato' ); ?></th>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="xv-import-settings-form">
                                    <input type="hidden" name="action" value="xvato_import_settings">
                                    <?php wp_nonce_field( 'xvato_import_settings', 'xvato_nonce' ); ?>
                                    <input type="file" name="xvato_settings_file" accept=".json" required>
                                    <button type="submit" class="xv-btn" onclick="return confirm('<?php esc_attr_e( 'Import settings from this file? Current settings will be overwritten.', 'xvato' ); ?>')">
                                        <span class="dashicons dashicons-upload"></span>
                                        <?php esc_html_e( 'Import', 'xvato' ); ?>
                                    </button>
                                </form>
                                <p class="description"><?php esc_html_e( 'Upload a previously exported .json file to restore settings.', 'xvato' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- ─── Right Column: System Status ────────────────────── -->
        <div class="xv-settings-sidebar">
            <div class="xv-card">
                <h2 class="xv-card-header">
                    <span class="xv-header-icon">💚</span>
                    <?php esc_html_e( 'System Status', 'xvato' ); ?>
                </h2>
                <div class="xv-card-content">
                    <table class="xv-status-table">
                        <tr>
                            <td><?php esc_html_e( 'Xvato', 'xvato' ); ?></td>
                            <td><span class="xv-badge xv-badge--complete"><?php echo esc_html( XVATO_VERSION ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'WordPress', 'xvato' ); ?></td>
                            <td><span class="xv-badge xv-badge--complete"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'PHP', 'xvato' ); ?></td>
                            <td>
                                <span class="xv-badge <?php echo version_compare( PHP_VERSION, '8.0', '>=' ) ? 'xv-badge--complete' : 'xv-badge--failed'; ?>">
                                    <?php echo esc_html( PHP_VERSION ); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Elementor', 'xvato' ); ?></td>
                            <td>
                                <?php if ( $elementor_active ) : ?>
                                    <span class="xv-badge xv-badge--complete"><?php echo esc_html( $elementor_version ); ?></span>
                                <?php else : ?>
                                    <span class="xv-badge xv-badge--failed"><?php esc_html_e( 'Not Active', 'xvato' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Elementor Pro', 'xvato' ); ?></td>
                            <td>
                                <?php if ( $elementor_pro ) : ?>
                                    <span class="xv-badge xv-badge--complete"><?php echo esc_html( ELEMENTOR_PRO_VERSION ); ?></span>
                                <?php else : ?>
                                    <span class="xv-badge xv-badge--pending"><?php esc_html_e( 'Not Active', 'xvato' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'WP-CLI', 'xvato' ); ?></td>
                            <td>
                                <?php if ( $wp_cli_available ) : ?>
                                    <span class="xv-badge xv-badge--complete"><?php esc_html_e( 'Available', 'xvato' ); ?></span>
                                <?php else : ?>
                                    <span class="xv-badge xv-badge--pending"><?php esc_html_e( 'Not Found', 'xvato' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'ZipArchive', 'xvato' ); ?></td>
                            <td>
                                <?php if ( $zip_available ) : ?>
                                    <span class="xv-badge xv-badge--complete"><?php esc_html_e( 'Available', 'xvato' ); ?></span>
                                <?php else : ?>
                                    <span class="xv-badge xv-badge--pending"><?php esc_html_e( 'Using PclZip', 'xvato' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'App Passwords', 'xvato' ); ?></td>
                            <td>
                                <?php if ( $has_app_passwords ) : ?>
                                    <span class="xv-badge xv-badge--complete"><?php esc_html_e( 'Available', 'xvato' ); ?></span>
                                <?php else : ?>
                                    <span class="xv-badge xv-badge--failed"><?php esc_html_e( 'Unavailable', 'xvato' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Temp Directory', 'xvato' ); ?></td>
                            <td>
                                <?php if ( $tmp_writable ) : ?>
                                    <span class="xv-badge xv-badge--complete"><?php esc_html_e( 'Writable', 'xvato' ); ?></span>
                                <?php elseif ( $tmp_exists ) : ?>
                                    <span class="xv-badge xv-badge--failed"><?php esc_html_e( 'Not Writable', 'xvato' ); ?></span>
                                <?php else : ?>
                                    <span class="xv-badge xv-badge--pending"><?php esc_html_e( 'Not Created', 'xvato' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'HTTPS', 'xvato' ); ?></td>
                            <td>
                                <?php if ( is_ssl() ) : ?>
                                    <span class="xv-badge xv-badge--complete"><?php esc_html_e( 'Yes', 'xvato' ); ?></span>
                                <?php else : ?>
                                    <span class="xv-badge xv-badge--pending"><?php esc_html_e( 'No', 'xvato' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Next Cleanup', 'xvato' ); ?></td>
                            <td>
                                <?php if ( $next_cleanup ) : ?>
                                    <span class="xv-badge xv-badge--pending">
                                        <?php echo esc_html( human_time_diff( time(), $next_cleanup ) ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="xv-badge xv-badge--pending"><?php esc_html_e( 'Not Scheduled', 'xvato' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Library Stats -->
            <div class="xv-card">
                <h2 class="xv-card-header">
                    <span class="xv-header-icon">📊</span>
                    <?php esc_html_e( 'Library Stats', 'xvato' ); ?>
                </h2>
                <div class="xv-card-content">
                    <table class="xv-status-table">
                        <tr>
                            <td><?php esc_html_e( 'Total', 'xvato' ); ?></td>
                            <td><strong style="color: var(--xv-text);"><?php echo esc_html( $counts['total'] ); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Imported', 'xvato' ); ?></td>
                            <td><span class="xv-badge xv-badge--complete"><?php echo esc_html( $counts['complete'] ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Pending', 'xvato' ); ?></td>
                            <td><span class="xv-badge xv-badge--pending"><?php echo esc_html( $counts['pending'] ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'In Progress', 'xvato' ); ?></td>
                            <td><span class="xv-badge xv-badge--pending"><?php echo esc_html( $counts['downloading'] + $counts['extracting'] + $counts['importing'] ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Failed', 'xvato' ); ?></td>
                            <td><span class="xv-badge xv-badge--failed"><?php echo esc_html( $counts['failed'] ); ?></span></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="xv-card">
                <h2 class="xv-card-header">
                    <span class="xv-header-icon">🔗</span>
                    <?php esc_html_e( 'Quick Links', 'xvato' ); ?>
                </h2>
                <div class="xv-card-content">
                    <ul class="xv-quick-links">
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=xvato' ) ); ?>">
                                <span class="dashicons dashicons-grid-view"></span>
                                <?php esc_html_e( 'Template Library', 'xvato' ); ?>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">
                                <span class="dashicons dashicons-admin-network"></span>
                                <?php esc_html_e( 'Application Passwords', 'xvato' ); ?>
                            </a>
                        </li>
                        <li>
                            <a href="https://elements.envato.com" target="_blank" rel="noopener">
                                <span class="dashicons dashicons-external"></span>
                                <?php esc_html_e( 'Envato Elements', 'xvato' ); ?>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url( $rest_url . '/status' ); ?>" target="_blank" rel="noopener">
                                <span class="dashicons dashicons-rest-api"></span>
                                <?php esc_html_e( 'API Status Endpoint', 'xvato' ); ?>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
