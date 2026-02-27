<?php
/**
 * Xvato — Downloader
 *
 * Downloads files from remote URLs using wp_remote_get.
 *
 * @package Xvato
 */

namespace Xvato;

defined( 'ABSPATH' ) || exit;

class Downloader {

    /**
     * Download a file from a URL to the Xvato tmp directory.
     *
     * @param string $url        Remote file URL.
     * @param string $filename   Desired filename (sanitized).
     * @param array  $headers    Optional extra HTTP headers.
     * @return string|\WP_Error  Local file path on success, WP_Error on failure.
     */
    public static function download( string $url, string $filename = '', array $headers = [] ): string|\WP_Error {
        if ( ! Security::is_valid_url( $url ) ) {
            return new \WP_Error( 'xvato_invalid_url', __( 'Invalid download URL.', 'xvato' ) );
        }

        // Generate a safe filename
        if ( empty( $filename ) ) {
            $filename = 'bk-' . wp_generate_uuid4() . '.zip';
        } else {
            $filename = sanitize_file_name( $filename );
        }

        // Get tmp directory
        $tmp_dir = self::get_tmp_dir();
        if ( is_wp_error( $tmp_dir ) ) {
            return $tmp_dir;
        }

        $dest_path = trailingslashit( $tmp_dir ) . $filename;

        // Download using WordPress HTTP API
        // Timeout set to 5 minutes for large files
        $response = wp_remote_get( $url, [
            'timeout'     => 300,
            'stream'      => true,
            'filename'    => $dest_path,
            'headers'     => array_merge( [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'application/zip, application/octet-stream, */*',
            ], $headers ),
            'sslverify'   => true,
            'redirection' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            // Clean up partial file
            if ( file_exists( $dest_path ) ) {
                wp_delete_file( $dest_path );
            }
            return new \WP_Error(
                'xvato_download_failed',
                sprintf(
                    /* translators: %s: error message */
                    __( 'Download failed: %s', 'xvato' ),
                    $response->get_error_message()
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) {
            if ( file_exists( $dest_path ) ) {
                wp_delete_file( $dest_path );
            }
            return new \WP_Error(
                'xvato_download_http_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Download returned HTTP %d.', 'xvato' ),
                    $status_code
                )
            );
        }

        // Verify the file was actually created and is a real ZIP
        if ( ! file_exists( $dest_path ) || filesize( $dest_path ) < 100 ) {
            return new \WP_Error(
                'xvato_download_empty',
                __( 'Downloaded file is empty or missing.', 'xvato' )
            );
        }

        // Check the first bytes to ensure it's actually a ZIP (PK\x03\x04)
        $handle = fopen( $dest_path, 'rb' );
        if ( $handle ) {
            $magic = fread( $handle, 4 );
            fclose( $handle );
            if ( $magic !== "PK\x03\x04" && $magic !== "PK\x05\x06" ) {
                // Might be an HTML error page or redirect page
                $content_preview = file_get_contents( $dest_path, false, null, 0, 500 );
                wp_delete_file( $dest_path );

                if ( stripos( $content_preview, '<html' ) !== false || stripos( $content_preview, '<!DOCTYPE' ) !== false ) {
                    return new \WP_Error(
                        'xvato_download_html',
                        __( 'Download URL returned an HTML page instead of a ZIP file. The download link may have expired.', 'xvato' )
                    );
                }

                return new \WP_Error(
                    'xvato_download_not_zip',
                    __( 'Downloaded file is not a valid ZIP archive (invalid file header).', 'xvato' )
                );
            }
        }

        return $dest_path;
    }

    /**
     * Download a file from upload (multipart form data).
     *
     * @param array $file $_FILES array entry.
     * @return string|\WP_Error Local file path on success.
     */
    public static function from_upload( array $file ): string|\WP_Error {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new \WP_Error( 'xvato_no_upload', __( 'No file uploaded.', 'xvato' ) );
        }

        $tmp_dir = self::get_tmp_dir();
        if ( is_wp_error( $tmp_dir ) ) {
            return $tmp_dir;
        }

        $filename  = 'bk-' . wp_generate_uuid4() . '.zip';
        $dest_path = trailingslashit( $tmp_dir ) . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
            return new \WP_Error( 'xvato_move_failed', __( 'Could not save uploaded file.', 'xvato' ) );
        }

        return $dest_path;
    }

    /**
     * Get (and create if needed) the tmp directory path.
     *
     * @return string|\WP_Error
     */
    public static function get_tmp_dir(): string|\WP_Error {
        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . XVATO_TMP_DIR;

        if ( ! file_exists( $tmp_dir ) ) {
            if ( ! wp_mkdir_p( $tmp_dir ) ) {
                return new \WP_Error(
                    'xvato_dir_failed',
                    __( 'Could not create temporary directory.', 'xvato' )
                );
            }
            // Protect directory
            file_put_contents( $tmp_dir . '/.htaccess', "Deny from all\n" );
            file_put_contents( $tmp_dir . '/index.php', "<?php // Silence is golden.\n" );
        }

        return $tmp_dir;
    }
}
