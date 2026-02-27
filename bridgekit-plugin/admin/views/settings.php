<?php
/**
 * BridgeKit — Settings Page
 *
 * Connection info display, API key management, and system diagnostics.
 *
 * @package BridgeKit
 */

defined( 'ABSPATH' ) || exit;

// Handle settings save
if ( isset( $_POST['bridgekit_save_settings'] ) ) {
    check_admin_referer( 'bridgekit_settings', 'bridgekit_settings_nonce' );

    $rate_limit = absint( $_POST['bridgekit_rate_limit'] ?? 10 );
    $cleanup_hours = absint( $_POST['bridgekit_cleanup_hours'] ?? 1 );
    $auto_import = isset( $_POST['bridgekit_auto_import'] ) ? '1' : '0';
    $keep_zip = isset( $_POST['bridgekit_keep_zip'] ) ? '1' : '0';

    update_option( 'bridgekit_rate_limit', max( 1, min( 100, $rate_limit ) ) );
    update_option( 'bridgekit_cleanup_hours', max( 1, min( 168, $cleanup_hours ) ) );
    update_option( 'bridgekit_auto_import', $auto_import );
    update_option( 'bridgekit_keep_zip', $keep_zip );

    $saved = true;
}

// Current settings
$rate_limit    = get_option( 'bridgekit_rate_limit', 10 );
$cleanup_hours = get_option( 'bridgekit_cleanup_hours', 1 );
$auto_import   = get_option( 'bridgekit_auto_import', '1' );
$keep_zip      = get_option( 'bridgekit_keep_zip', '1' );

// System info
$upload_dir       = wp_upload_dir();
$tmp_dir          = trailingslashit( $upload_dir['basedir'] ) . BRIDGEKIT_TMP_DIR;
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
$rest_url        = rest_url( BRIDGEKIT_REST_NAMESPACE );
$has_app_passwords = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
$next_cleanup    = wp_next_scheduled( 'bridgekit_cleanup_tmp' );
$counts          = BridgeKit\Library::get_status_counts();
?>

<div class="wrap bk-settings">
    <h1 class="wp-heading-inline">
        <?php esc_html_e( 'BridgeKit Settings', 'bridgekit' ); ?>
    </h1>

    <?php if ( ! empty( $saved ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved.', 'bridgekit' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['cleared'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Temporary files cleared.', 'bridgekit' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['reset'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( esc_html__( '%d failed import(s) reset to pending.', 'bridgekit' ), absint( $_GET['reset'] ) ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['imported_settings'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings imported successfully.', 'bridgekit' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['import_error'] ) ) : ?>
        <div class="notice notice-error is-dismissible">
            <p>
            <?php
            $import_err = sanitize_key( $_GET['import_error'] );
            switch ( $import_err ) {
                case 'no_file':
                    esc_html_e( 'No file selected for import.', 'bridgekit' );
                    break;
                case 'invalid_type':
                    esc_html_e( 'Invalid file type. Please upload a .json file.', 'bridgekit' );
                    break;
                case 'invalid_json':
                    esc_html_e( 'Invalid settings file. Could not parse the JSON data.', 'bridgekit' );
                    break;
                default:
                    esc_html_e( 'Settings import failed.', 'bridgekit' );
            }
            ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="bk-settings-layout">
        <!-- ─── Left Column: Settings ──────────────────────────── -->
        <div class="bk-settings-main">

            <!-- Connection Setup Guide -->
            <div class="bk-card">
                <h2 class="bk-card-header">
                    <span class="dashicons dashicons-admin-links"></span>
                    <?php esc_html_e( 'Chrome Extension Setup', 'bridgekit' ); ?>
                </h2>
                <div class="bk-card-content">
                    <p><?php esc_html_e( 'Follow these steps to connect the BridgeKit Chrome Extension to your WordPress site:', 'bridgekit' ); ?></p>

                    <ol class="bk-steps">
                        <li>
                            <strong><?php esc_html_e( 'Install the Chrome Extension', 'bridgekit' ); ?></strong>
                            <p class="description"><?php esc_html_e( 'Load it as an unpacked extension from chrome://extensions or install from the Chrome Web Store.', 'bridgekit' ); ?></p>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Generate an Application Password', 'bridgekit' ); ?></strong>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: link to user profile */
                                    esc_html__( 'Go to %s → scroll to "Application Passwords" → enter a name like "BridgeKit" → click "Add New Application Password".', 'bridgekit' ),
                                    '<a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'Users → Profile', 'bridgekit' ) . '</a>'
                                );
                                ?>
                            </p>
                            <?php if ( ! $has_app_passwords ) : ?>
                                <div class="notice notice-warning inline">
                                    <p><strong><?php esc_html_e( '⚠ Application Passwords are not available.', 'bridgekit' ); ?></strong>
                                    <?php esc_html_e( 'Your site must be served over HTTPS, or you can use the "Application Passwords" plugin for HTTP development sites.', 'bridgekit' ); ?></p>
                                </div>
                            <?php endif; ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Configure the Extension', 'bridgekit' ); ?></strong>
                            <p class="description"><?php esc_html_e( 'Click the BridgeKit icon in Chrome, enter your site URL and credentials, then click "Test Connection".', 'bridgekit' ); ?></p>
                        </li>
                    </ol>

                    <div class="bk-connection-info">
                        <h4><?php esc_html_e( 'Your Connection Details', 'bridgekit' ); ?></h4>
                        <table class="bk-info-table">
                            <tr>
                                <th><?php esc_html_e( 'Site URL', 'bridgekit' ); ?></th>
                                <td>
                                    <code id="bk-site-url"><?php echo esc_html( home_url() ); ?></code>
                                    <button type="button" class="button button-small bk-copy-btn" data-copy="bk-site-url" title="<?php esc_attr_e( 'Copy', 'bridgekit' ); ?>">
                                        <span class="dashicons dashicons-clipboard" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'REST API', 'bridgekit' ); ?></th>
                                <td>
                                    <code id="bk-rest-url"><?php echo esc_html( $rest_url ); ?></code>
                                    <button type="button" class="button button-small bk-copy-btn" data-copy="bk-rest-url" title="<?php esc_attr_e( 'Copy', 'bridgekit' ); ?>">
                                        <span class="dashicons dashicons-clipboard" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Username', 'bridgekit' ); ?></th>
                                <td>
                                    <code id="bk-username"><?php echo esc_html( wp_get_current_user()->user_login ); ?></code>
                                    <button type="button" class="button button-small bk-copy-btn" data-copy="bk-username" title="<?php esc_attr_e( 'Copy', 'bridgekit' ); ?>">
                                        <span class="dashicons dashicons-clipboard" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                    </button>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Import Settings -->
            <div class="bk-card">
                <h2 class="bk-card-header">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php esc_html_e( 'Import Settings', 'bridgekit' ); ?>
                </h2>
                <div class="bk-card-content">
                    <form method="post">
                        <?php wp_nonce_field( 'bridgekit_settings', 'bridgekit_settings_nonce' ); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="bridgekit_auto_import"><?php esc_html_e( 'Auto-Import', 'bridgekit' ); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="bridgekit_auto_import" id="bridgekit_auto_import" value="1" <?php checked( $auto_import, '1' ); ?>>
                                        <?php esc_html_e( 'Automatically start importing when a template kit is received from the Chrome Extension', 'bridgekit' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="bridgekit_keep_zip"><?php esc_html_e( 'Keep ZIP Files', 'bridgekit' ); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="bridgekit_keep_zip" id="bridgekit_keep_zip" value="1" <?php checked( $keep_zip, '1' ); ?>>
                                        <?php esc_html_e( 'Keep downloaded ZIP files after import (allows re-importing later)', 'bridgekit' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="bridgekit_rate_limit"><?php esc_html_e( 'Rate Limit', 'bridgekit' ); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="bridgekit_rate_limit" id="bridgekit_rate_limit" value="<?php echo esc_attr( $rate_limit ); ?>" min="1" max="100" class="small-text">
                                    <span class="description"><?php esc_html_e( 'imports per 5-minute window', 'bridgekit' ); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="bridgekit_cleanup_hours"><?php esc_html_e( 'Temp Cleanup', 'bridgekit' ); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="bridgekit_cleanup_hours" id="bridgekit_cleanup_hours" value="<?php echo esc_attr( $cleanup_hours ); ?>" min="1" max="168" class="small-text">
                                    <span class="description"><?php esc_html_e( 'hours — delete temporary files older than this', 'bridgekit' ); ?></span>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" name="bridgekit_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'bridgekit' ); ?>">
                        </p>
                    </form>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="bk-card bk-card--danger">
                <h2 class="bk-card-header">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'Maintenance', 'bridgekit' ); ?>
                </h2>
                <div class="bk-card-content">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Clear Temp Files', 'bridgekit' ); ?></th>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bridgekit_clear_tmp' ), 'bridgekit_clear_tmp', 'bridgekit_nonce' ) ); ?>"
                                   class="button"
                                   onclick="return confirm('<?php esc_attr_e( 'Delete all temporary files? This cannot be undone.', 'bridgekit' ); ?>')">
                                    <?php esc_html_e( 'Clear Now', 'bridgekit' ); ?>
                                </a>
                                <span class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: size of temp directory */
                                        esc_html__( 'Current temp directory size: %s', 'bridgekit' ),
                                        size_format( $tmp_size )
                                    );
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Reset Failed Imports', 'bridgekit' ); ?></th>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bridgekit_reset_failed' ), 'bridgekit_reset_failed', 'bridgekit_nonce' ) ); ?>"
                                   class="button"
                                   onclick="return confirm('<?php esc_attr_e( 'Reset all failed imports to pending? They will be re-processed.', 'bridgekit' ); ?>')">
                                    <?php esc_html_e( 'Reset Failed', 'bridgekit' ); ?>
                                </a>
                                <span class="description">
                                    <?php
                                    printf(
                                        /* translators: %d: number of failed imports */
                                        esc_html( _n( '%d failed import', '%d failed imports', $counts['failed'], 'bridgekit' ) ),
                                        $counts['failed']
                                    );
                                    ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Settings Export / Import -->
            <div class="bk-card">
                <h2 class="bk-card-header">
                    <span class="dashicons dashicons-migrate"></span>
                    <?php esc_html_e( 'Export / Import Settings', 'bridgekit' ); ?>
                </h2>
                <div class="bk-card-content">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Export', 'bridgekit' ); ?></th>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bridgekit_export_settings' ), 'bridgekit_export_settings', 'bridgekit_nonce' ) ); ?>"
                                   class="button">
                                    <span class="dashicons dashicons-download" style="font-size:16px; width:16px; height:16px; vertical-align:middle; margin-top:-2px;"></span>
                                    <?php esc_html_e( 'Download Settings JSON', 'bridgekit' ); ?>
                                </a>
                                <p class="description"><?php esc_html_e( 'Export your BridgeKit settings to a JSON file for backup or migration.', 'bridgekit' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Import', 'bridgekit' ); ?></th>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="bk-import-settings-form">
                                    <input type="hidden" name="action" value="bridgekit_import_settings">
                                    <?php wp_nonce_field( 'bridgekit_import_settings', 'bridgekit_nonce' ); ?>
                                    <input type="file" name="bridgekit_settings_file" accept=".json" required>
                                    <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Import settings from this file? Current settings will be overwritten.', 'bridgekit' ); ?>')">
                                        <span class="dashicons dashicons-upload" style="font-size:16px; width:16px; height:16px; vertical-align:middle; margin-top:-2px;"></span>
                                        <?php esc_html_e( 'Import', 'bridgekit' ); ?>
                                    </button>
                                </form>
                                <p class="description"><?php esc_html_e( 'Upload a previously exported .json file to restore settings.', 'bridgekit' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- ─── Right Column: System Status ────────────────────── -->
        <div class="bk-settings-sidebar">
            <div class="bk-card">
                <h2 class="bk-card-header">
                    <span class="dashicons dashicons-heart"></span>
                    <?php esc_html_e( 'System Status', 'bridgekit' ); ?>
                </h2>
                <div class="bk-card-content">
                    <table class="bk-status-table">
                        <tr>
                            <td><?php esc_html_e( 'BridgeKit', 'bridgekit' ); ?></td>
                            <td><span class="bk-badge bk-badge--complete"><?php echo esc_html( BRIDGEKIT_VERSION ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'WordPress', 'bridgekit' ); ?></td>
                            <td><span class="bk-badge bk-badge--complete"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'PHP', 'bridgekit' ); ?></td>
                            <td>
                                <span class="bk-badge <?php echo version_compare( PHP_VERSION, '8.0', '>=' ) ? 'bk-badge--complete' : 'bk-badge--failed'; ?>">
                                    <?php echo esc_html( PHP_VERSION ); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Elementor', 'bridgekit' ); ?></td>
                            <td>
                                <?php if ( $elementor_active ) : ?>
                                    <span class="bk-badge bk-badge--complete"><?php echo esc_html( $elementor_version ); ?></span>
                                <?php else : ?>
                                    <span class="bk-badge bk-badge--failed"><?php esc_html_e( 'Not Active', 'bridgekit' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Elementor Pro', 'bridgekit' ); ?></td>
                            <td>
                                <?php if ( $elementor_pro ) : ?>
                                    <span class="bk-badge bk-badge--complete"><?php echo esc_html( ELEMENTOR_PRO_VERSION ); ?></span>
                                <?php else : ?>
                                    <span class="bk-badge bk-badge--pending"><?php esc_html_e( 'Not Active', 'bridgekit' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'WP-CLI', 'bridgekit' ); ?></td>
                            <td>
                                <?php if ( $wp_cli_available ) : ?>
                                    <span class="bk-badge bk-badge--complete"><?php esc_html_e( 'Available', 'bridgekit' ); ?></span>
                                <?php else : ?>
                                    <span class="bk-badge bk-badge--pending"><?php esc_html_e( 'Not Found', 'bridgekit' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'ZipArchive', 'bridgekit' ); ?></td>
                            <td>
                                <?php if ( $zip_available ) : ?>
                                    <span class="bk-badge bk-badge--complete"><?php esc_html_e( 'Available', 'bridgekit' ); ?></span>
                                <?php else : ?>
                                    <span class="bk-badge bk-badge--pending"><?php esc_html_e( 'Using PclZip', 'bridgekit' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'App Passwords', 'bridgekit' ); ?></td>
                            <td>
                                <?php if ( $has_app_passwords ) : ?>
                                    <span class="bk-badge bk-badge--complete"><?php esc_html_e( 'Available', 'bridgekit' ); ?></span>
                                <?php else : ?>
                                    <span class="bk-badge bk-badge--failed"><?php esc_html_e( 'Unavailable', 'bridgekit' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Temp Directory', 'bridgekit' ); ?></td>
                            <td>
                                <?php if ( $tmp_writable ) : ?>
                                    <span class="bk-badge bk-badge--complete"><?php esc_html_e( 'Writable', 'bridgekit' ); ?></span>
                                <?php elseif ( $tmp_exists ) : ?>
                                    <span class="bk-badge bk-badge--failed"><?php esc_html_e( 'Not Writable', 'bridgekit' ); ?></span>
                                <?php else : ?>
                                    <span class="bk-badge bk-badge--pending"><?php esc_html_e( 'Not Created', 'bridgekit' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'HTTPS', 'bridgekit' ); ?></td>
                            <td>
                                <?php if ( is_ssl() ) : ?>
                                    <span class="bk-badge bk-badge--complete"><?php esc_html_e( 'Yes', 'bridgekit' ); ?></span>
                                <?php else : ?>
                                    <span class="bk-badge bk-badge--pending"><?php esc_html_e( 'No', 'bridgekit' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Next Cleanup', 'bridgekit' ); ?></td>
                            <td>
                                <?php if ( $next_cleanup ) : ?>
                                    <span class="bk-badge bk-badge--pending">
                                        <?php echo esc_html( human_time_diff( time(), $next_cleanup ) ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="bk-badge bk-badge--pending"><?php esc_html_e( 'Not Scheduled', 'bridgekit' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Library Stats -->
            <div class="bk-card">
                <h2 class="bk-card-header">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <?php esc_html_e( 'Library Stats', 'bridgekit' ); ?>
                </h2>
                <div class="bk-card-content">
                    <table class="bk-status-table">
                        <tr>
                            <td><?php esc_html_e( 'Total', 'bridgekit' ); ?></td>
                            <td><strong><?php echo esc_html( $counts['total'] ); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Imported', 'bridgekit' ); ?></td>
                            <td><span class="bk-badge bk-badge--complete"><?php echo esc_html( $counts['complete'] ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Pending', 'bridgekit' ); ?></td>
                            <td><span class="bk-badge bk-badge--pending"><?php echo esc_html( $counts['pending'] ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'In Progress', 'bridgekit' ); ?></td>
                            <td><span class="bk-badge bk-badge--pending"><?php echo esc_html( $counts['downloading'] + $counts['extracting'] + $counts['importing'] ); ?></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Failed', 'bridgekit' ); ?></td>
                            <td><span class="bk-badge bk-badge--failed"><?php echo esc_html( $counts['failed'] ); ?></span></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="bk-card">
                <h2 class="bk-card-header">
                    <span class="dashicons dashicons-admin-links"></span>
                    <?php esc_html_e( 'Quick Links', 'bridgekit' ); ?>
                </h2>
                <div class="bk-card-content">
                    <ul class="bk-quick-links">
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bridgekit' ) ); ?>">
                                <span class="dashicons dashicons-grid-view"></span>
                                <?php esc_html_e( 'Template Library', 'bridgekit' ); ?>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">
                                <span class="dashicons dashicons-admin-network"></span>
                                <?php esc_html_e( 'Application Passwords', 'bridgekit' ); ?>
                            </a>
                        </li>
                        <li>
                            <a href="https://elements.envato.com" target="_blank" rel="noopener">
                                <span class="dashicons dashicons-external"></span>
                                <?php esc_html_e( 'Envato Elements', 'bridgekit' ); ?>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url( $rest_url . '/status' ); ?>" target="_blank" rel="noopener">
                                <span class="dashicons dashicons-rest-api"></span>
                                <?php esc_html_e( 'API Status Endpoint', 'bridgekit' ); ?>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
