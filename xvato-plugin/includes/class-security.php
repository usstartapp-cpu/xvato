<?php
/**
 * Xvato — Security Class
 *
 * Handles request validation, rate limiting, and permission checks.
 *
 * @package Xvato
 */

namespace Xvato;

defined( 'ABSPATH' ) || exit;

class Security {

    /**
     * Rate limit: max imports per window.
     */
    private const RATE_LIMIT_MAX    = 10;
    private const RATE_LIMIT_WINDOW = 300; // 5 minutes in seconds

    /**
     * Permission callback for REST API endpoints.
     * Validates that the user is authenticated and has manage_options capability.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public static function check_permission( \WP_REST_Request $request ): bool|\WP_Error {
        // If WordPress already authenticated the user (e.g., cookie auth), check capabilities
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Try manual Application Password authentication as fallback.
        // Some hosts strip the Authorization header before WP can process it,
        // so we attempt to authenticate manually.
        $auth_header = '';

        // Try multiple sources for the Authorization header
        if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            foreach ( $headers as $name => $value ) {
                if ( strtolower( $name ) === 'authorization' ) {
                    $auth_header = $value;
                    break;
                }
            }
        }

        if ( $auth_header && 0 === stripos( $auth_header, 'Basic ' ) ) {
            $decoded = base64_decode( substr( $auth_header, 6 ) );
            if ( $decoded && strpos( $decoded, ':' ) !== false ) {
                list( $username, $password ) = explode( ':', $decoded, 2 );

                // Authenticate via Application Passwords
                $user = wp_authenticate_application_password( null, $username, $password );

                if ( $user instanceof \WP_User && ! is_wp_error( $user ) ) {
                    wp_set_current_user( $user->ID );
                    if ( current_user_can( 'manage_options' ) ) {
                        return true;
                    }
                }
            }
        }

        return new \WP_Error(
            'xvato_unauthorized',
            __( 'You must be an administrator to use Xvato.', 'xvato' ),
            [ 'status' => 401 ]
        );
    }

    /**
     * Check rate limiting for import requests.
     *
     * @return bool|\WP_Error
     */
    public static function check_rate_limit(): bool|\WP_Error {
        $user_id = get_current_user_id();
        $key     = 'xvato_rate_' . $user_id;
        $data    = get_transient( $key );

        if ( false === $data ) {
            // First request in this window
            set_transient( $key, [ 'count' => 1, 'start' => time() ], self::RATE_LIMIT_WINDOW );
            return true;
        }

        if ( $data['count'] >= self::RATE_LIMIT_MAX ) {
            $remaining = self::RATE_LIMIT_WINDOW - ( time() - $data['start'] );
            return new \WP_Error(
                'xvato_rate_limited',
                sprintf(
                    /* translators: %d: seconds remaining */
                    __( 'Rate limit exceeded. Try again in %d seconds.', 'xvato' ),
                    max( 0, $remaining )
                ),
                [ 'status' => 429 ]
            );
        }

        // Increment counter
        $data['count']++;
        set_transient( $key, $data, self::RATE_LIMIT_WINDOW );

        return true;
    }

    /**
     * Validate a URL string.
     *
     * @param string $url
     * @return bool
     */
    public static function is_valid_url( string $url ): bool {
        return (bool) filter_var( $url, FILTER_VALIDATE_URL )
            && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true );
    }

    /**
     * Validate that a file is a ZIP archive.
     *
     * @param string $file_path
     * @return bool
     */
    public static function is_valid_zip( string $file_path ): bool {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        $mime = wp_check_filetype( $file_path );
        if ( ! in_array( $mime['type'], [ 'application/zip', 'application/x-zip-compressed' ], true ) ) {
            return false;
        }

        // Double-check with ZipArchive if available
        if ( class_exists( '\ZipArchive' ) ) {
            $zip    = new \ZipArchive();
            $result = $zip->open( $file_path, \ZipArchive::CHECKCONS );
            if ( true !== $result ) {
                return false;
            }
            $zip->close();
        }

        return true;
    }

    /**
     * Scan extracted directory for potentially dangerous files.
     *
     * @param string $dir
     * @return array List of suspicious file paths.
     */
    public static function scan_extracted_files( string $dir ): array {
        $suspicious    = [];
        $dangerous_ext = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'sh', 'bat' ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $ext = strtolower( $file->getExtension() );
                if ( in_array( $ext, $dangerous_ext, true ) ) {
                    $suspicious[] = $file->getPathname();
                }
            }
        }

        return $suspicious;
    }

    /**
     * Sanitize an import payload from the Chrome extension.
     *
     * @param array $payload
     * @return array Sanitized payload.
     */
    public static function sanitize_import_payload( array $payload ): array {
        return [
            'title'         => sanitize_text_field( $payload['title'] ?? '' ),
            'download_url'  => esc_url_raw( $payload['download_url'] ?? '' ),
            'thumbnail_url' => esc_url_raw( $payload['thumbnail_url'] ?? '' ),
            'category'      => sanitize_text_field( $payload['category'] ?? '' ),
            'source_url'    => esc_url_raw( $payload['source_url'] ?? '' ),
            'timestamp'     => sanitize_text_field( $payload['timestamp'] ?? '' ),
        ];
    }
}
