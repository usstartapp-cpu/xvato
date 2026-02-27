<?php
/**
 * BridgeKit — Importer
 *
 * Core import logic:
 *  1. Download ZIP from URL
 *  2. Extract using WP_Filesystem / unzip_file()
 *  3. Parse manifest.json (Envato Template Kit format)
 *  4. Import templates via Elementor CLI or internal API
 *  5. Record results in library CPT
 *
 * @package BridgeKit
 */

namespace BridgeKit;

defined( 'ABSPATH' ) || exit;

class Importer {

    /**
     * Process an import from a remote URL.
     * Called by the background cron job.
     *
     * @param int    $post_id      Library CPT entry ID.
     * @param string $download_url Remote ZIP URL.
     */
    public function process_from_url( int $post_id, string $download_url ): void {
        Library::update_status( $post_id, 'downloading' );
        Library::add_log( $post_id, 'Starting download from URL.' );

        // Step 1: Download
        $zip_path = Downloader::download( $download_url );

        if ( is_wp_error( $zip_path ) ) {
            Library::set_error( $post_id, $zip_path->get_error_message() );
            return;
        }

        Library::add_log( $post_id, 'Download complete: ' . basename( $zip_path ) );

        // Store the zip path for potential re-import
        update_post_meta( $post_id, '_bk_zip_path', $zip_path );

        // Continue with the shared import pipeline
        $this->process_zip( $post_id, $zip_path );
    }

    /**
     * Process an import from a local ZIP file (for re-imports or manual uploads).
     *
     * @param int    $post_id  Library CPT entry ID.
     * @param string $zip_path Local path to the ZIP file.
     */
    public function process_zip( int $post_id, string $zip_path ): void {
        // Step 2: Validate ZIP
        if ( ! Security::is_valid_zip( $zip_path ) ) {
            Library::set_error( $post_id, 'File is not a valid ZIP archive.' );
            return;
        }

        // Step 3: Extract
        Library::update_status( $post_id, 'extracting' );
        Library::add_log( $post_id, 'Extracting ZIP archive.' );

        $extract_dir = $this->extract_zip( $zip_path );

        if ( is_wp_error( $extract_dir ) ) {
            Library::set_error( $post_id, $extract_dir->get_error_message() );
            return;
        }

        Library::add_log( $post_id, 'Extraction complete.' );

        // Step 4: Security scan
        $suspicious = Security::scan_extracted_files( $extract_dir );
        if ( ! empty( $suspicious ) ) {
            Library::add_log( $post_id, 'WARNING: Found ' . count( $suspicious ) . ' suspicious file(s): ' . implode( ', ', array_map( 'basename', $suspicious ) ) );
            // Don't block — just warn. Template Kits shouldn't have PHP files.
        }

        // Step 5: Parse manifest
        $manifest = $this->parse_manifest( $extract_dir );

        if ( is_wp_error( $manifest ) ) {
            // Not all ZIPs have manifests — try direct Elementor import
            Library::add_log( $post_id, 'No manifest.json found. Attempting direct import.' );
            $manifest = null;
        } else {
            update_post_meta( $post_id, '_bk_manifest', $manifest );
            Library::add_log( $post_id, 'Manifest parsed: ' . ( $manifest['name'] ?? 'unnamed' ) . ' (' . count( $manifest['templates'] ?? [] ) . ' templates).' );

            // Store dependencies
            $deps = $this->extract_dependencies( $manifest );
            update_post_meta( $post_id, '_bk_dependencies', $deps );
        }

        // Step 6: Import into Elementor
        Library::update_status( $post_id, 'importing' );
        Library::add_log( $post_id, 'Starting Elementor import.' );

        $result = $this->import_to_elementor( $post_id, $extract_dir, $zip_path, $manifest );

        if ( is_wp_error( $result ) ) {
            Library::set_error( $post_id, $result->get_error_message() );
            // Clean up extracted files on failure
            BridgeKit::delete_directory( $extract_dir );
            return;
        }

        // Step 7: Success — record template IDs
        update_post_meta( $post_id, '_bk_template_ids', $result );
        Library::update_status( $post_id, 'complete' );
        Library::add_log( $post_id, 'Import complete! ' . count( $result ) . ' template(s) imported.' );

        // Clean up extracted files (keep the ZIP for re-import)
        BridgeKit::delete_directory( $extract_dir );
    }

    /**
     * Extract a ZIP file to a unique temp subdirectory.
     *
     * @param string $zip_path
     * @return string|\WP_Error Extraction directory path on success.
     */
    private function extract_zip( string $zip_path ): string|\WP_Error {
        WP_Filesystem();

        $tmp_dir     = Downloader::get_tmp_dir();
        if ( is_wp_error( $tmp_dir ) ) {
            return $tmp_dir;
        }

        $extract_dir = trailingslashit( $tmp_dir ) . 'extract-' . wp_generate_uuid4();

        if ( ! wp_mkdir_p( $extract_dir ) ) {
            return new \WP_Error( 'bridgekit_extract_dir', __( 'Could not create extraction directory.', 'bridgekit' ) );
        }

        $result = unzip_file( $zip_path, $extract_dir );

        if ( is_wp_error( $result ) ) {
            BridgeKit::delete_directory( $extract_dir );
            return new \WP_Error(
                'bridgekit_unzip_failed',
                sprintf( __( 'Extraction failed: %s', 'bridgekit' ), $result->get_error_message() )
            );
        }

        return $extract_dir;
    }

    /**
     * Locate and parse manifest.json from an extracted Template Kit.
     *
     * @param string $dir Extraction directory.
     * @return array|\WP_Error Parsed manifest or error.
     */
    private function parse_manifest( string $dir ): array|\WP_Error {
        // Look for manifest.json (may be in a subdirectory)
        $manifest_path = null;

        // Direct location
        if ( file_exists( $dir . '/manifest.json' ) ) {
            $manifest_path = $dir . '/manifest.json';
        } else {
            // Check one level deep (common for Envato kits)
            $subdirs = glob( $dir . '/*', GLOB_ONLYDIR );
            foreach ( $subdirs as $subdir ) {
                if ( file_exists( $subdir . '/manifest.json' ) ) {
                    $manifest_path = $subdir . '/manifest.json';
                    break;
                }
            }
        }

        if ( ! $manifest_path ) {
            return new \WP_Error( 'bridgekit_no_manifest', __( 'No manifest.json found in the archive.', 'bridgekit' ) );
        }

        $content = file_get_contents( $manifest_path );
        if ( false === $content ) {
            return new \WP_Error( 'bridgekit_manifest_read', __( 'Could not read manifest.json.', 'bridgekit' ) );
        }

        $manifest = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new \WP_Error( 'bridgekit_manifest_parse', __( 'Invalid manifest.json: ' . json_last_error_msg(), 'bridgekit' ) );
        }

        // Store the directory where the manifest was found (templates are relative to this)
        $manifest['_base_dir'] = dirname( $manifest_path );

        return $manifest;
    }

    /**
     * Extract dependency info from a manifest.
     *
     * @param array $manifest
     * @return array
     */
    private function extract_dependencies( array $manifest ): array {
        $deps = [];

        // Common manifest fields from Envato Template Kits
        if ( ! empty( $manifest['requirements'] ) ) {
            $deps = $manifest['requirements'];
        }

        // Check for Elementor version requirements
        if ( ! empty( $manifest['minimum_elementor_version'] ) ) {
            $deps['elementor_min_version'] = $manifest['minimum_elementor_version'];
        }

        if ( ! empty( $manifest['elementor_pro_required'] ) ) {
            $deps['elementor_pro'] = true;
        }

        // Check for theme requirements
        if ( ! empty( $manifest['theme'] ) ) {
            $deps['theme'] = $manifest['theme'];
        }

        return $deps;
    }

    /**
     * Import templates into Elementor.
     * Strategy: Try WP-CLI first (stable), fall back to internal API.
     *
     * @param int         $post_id
     * @param string      $extract_dir
     * @param string      $zip_path
     * @param array|null  $manifest
     * @return array|\WP_Error Array of imported template IDs, or error.
     */
    private function import_to_elementor( int $post_id, string $extract_dir, string $zip_path, ?array $manifest ): array|\WP_Error {
        // Check if Elementor is active
        if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
            return new \WP_Error(
                'bridgekit_no_elementor',
                __( 'Elementor is not installed or activated.', 'bridgekit' )
            );
        }

        // Strategy 1: Try WP-CLI (most reliable)
        $cli_result = $this->import_via_cli( $zip_path );
        if ( ! is_wp_error( $cli_result ) ) {
            return $cli_result;
        }

        Library::add_log( $post_id, 'WP-CLI not available. Falling back to internal API.' );

        // Strategy 2: Internal Elementor API (version-gated)
        return $this->import_via_internal_api( $extract_dir, $manifest );
    }

    /**
     * Import via WP-CLI (stable, documented method).
     *
     * @param string $zip_path
     * @return array|\WP_Error
     */
    private function import_via_cli( string $zip_path ): array|\WP_Error {
        if ( ! function_exists( 'exec' ) ) {
            return new \WP_Error( 'bridgekit_no_exec', 'exec() is disabled.' );
        }

        // Check if wp-cli is available
        exec( 'which wp 2>/dev/null', $which_output, $which_return );
        if ( 0 !== $which_return ) {
            return new \WP_Error( 'bridgekit_no_wp_cli', 'WP-CLI not found.' );
        }

        $wp_path = ABSPATH;
        $escaped_zip = escapeshellarg( $zip_path );
        $escaped_wp  = escapeshellarg( $wp_path );

        // Try kit import first (for full Template Kits)
        $command = sprintf(
            'wp elementor kit import %s --path=%s --allow-root 2>&1',
            $escaped_zip,
            $escaped_wp
        );

        exec( $command, $output, $return_code );

        if ( 0 === $return_code ) {
            // Parse output for template IDs if possible
            return $this->parse_cli_output( $output );
        }

        // Fall back to library import (for single templates)
        $command = sprintf(
            'wp elementor library import %s --path=%s --allow-root 2>&1',
            $escaped_zip,
            $escaped_wp
        );

        exec( $command, $output2, $return_code2 );

        if ( 0 === $return_code2 ) {
            return $this->parse_cli_output( $output2 );
        }

        return new \WP_Error(
            'bridgekit_cli_failed',
            'WP-CLI import failed: ' . implode( "\n", array_merge( $output, $output2 ) )
        );
    }

    /**
     * Import via Elementor's internal PHP API.
     * Version-gated to handle API changes across Elementor versions.
     *
     * @param string     $extract_dir
     * @param array|null $manifest
     * @return array|\WP_Error
     */
    private function import_via_internal_api( string $extract_dir, ?array $manifest ): array|\WP_Error {
        $imported_ids = [];

        // Find all JSON template files
        $template_files = $this->find_template_files( $extract_dir, $manifest );

        if ( empty( $template_files ) ) {
            return new \WP_Error(
                'bridgekit_no_templates',
                __( 'No importable template files found in the archive.', 'bridgekit' )
            );
        }

        // Use Elementor's Source_Local for importing
        $source = \Elementor\Plugin::$instance->templates_manager->get_source( 'local' );

        if ( ! $source ) {
            return new \WP_Error( 'bridgekit_no_source', __( 'Elementor template source not available.', 'bridgekit' ) );
        }

        foreach ( $template_files as $file_info ) {
            $file_path = $file_info['path'];
            $name      = $file_info['name'] ?? basename( $file_path, '.json' );

            if ( ! file_exists( $file_path ) ) {
                continue;
            }

            // Read the JSON content
            $content = file_get_contents( $file_path );
            if ( false === $content ) {
                continue;
            }

            $data = json_decode( $content, true );
            if ( ! $data ) {
                continue;
            }

            // Import using Elementor's import method
            $result = $source->import_template( $name, $file_path );

            if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
                foreach ( $result as $imported ) {
                    if ( isset( $imported['template_id'] ) ) {
                        $imported_ids[] = $imported['template_id'];
                    }
                }
            }
        }

        if ( empty( $imported_ids ) ) {
            return new \WP_Error(
                'bridgekit_import_empty',
                __( 'Import completed but no templates were created.', 'bridgekit' )
            );
        }

        return $imported_ids;
    }

    /**
     * Find all importable template JSON files in the extracted directory.
     *
     * @param string     $dir
     * @param array|null $manifest
     * @return array Array of ['path' => ..., 'name' => ...]
     */
    private function find_template_files( string $dir, ?array $manifest ): array {
        $files = [];

        // If manifest has template list, use it
        if ( $manifest && ! empty( $manifest['templates'] ) ) {
            $base_dir = $manifest['_base_dir'] ?? $dir;
            foreach ( $manifest['templates'] as $template ) {
                $filename = $template['source'] ?? $template['file'] ?? '';
                if ( $filename ) {
                    $path = trailingslashit( $base_dir ) . $filename;
                    if ( file_exists( $path ) ) {
                        $files[] = [
                            'path' => $path,
                            'name' => $template['title'] ?? $template['name'] ?? basename( $filename, '.json' ),
                        ];
                    }
                }
            }
        }

        // If no files from manifest, scan directory for .json files
        if ( empty( $files ) ) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
            );

            foreach ( $iterator as $file ) {
                if ( $file->isFile() && strtolower( $file->getExtension() ) === 'json' ) {
                    // Skip manifest.json itself
                    if ( $file->getFilename() === 'manifest.json' ) {
                        continue;
                    }

                    // Quick check: does it look like an Elementor template?
                    $content = file_get_contents( $file->getPathname() );
                    $data    = json_decode( $content, true );

                    if ( $data && ( isset( $data['content'] ) || isset( $data['type'] ) ) ) {
                        $files[] = [
                            'path' => $file->getPathname(),
                            'name' => $data['title'] ?? basename( $file->getFilename(), '.json' ),
                        ];
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Parse WP-CLI output to extract template IDs.
     *
     * @param array $output Lines of CLI output.
     * @return array Template IDs.
     */
    private function parse_cli_output( array $output ): array {
        $ids = [];
        foreach ( $output as $line ) {
            // WP-CLI typically outputs "Success: X template(s) imported." or similar
            if ( preg_match( '/template.*?(\d+)/i', $line, $matches ) ) {
                $ids[] = (int) $matches[1];
            }
        }

        // If we couldn't parse specific IDs, return a placeholder
        return ! empty( $ids ) ? $ids : [ 'cli_import_success' ];
    }
}
