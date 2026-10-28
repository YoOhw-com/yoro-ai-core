<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A release may supply this local manifest only after public-host readiness is verified. */
final class YoRo_Preview_Download {
    const VERSION = '0.2.0';
    const RESOURCE = 'https://r2-api.yorohq.com/mcp/early-access';
    const FILENAME = 'yoro-preview-public-0.2.0.zip';
    const SHA256 = '344465bb3807b6c40292e69a26891cb1c333e87663373c04f48b256799d940dd';

    public static function current( $server_url ) {
        $directory = YORO_AI_CORE_PATH . 'assets/downloads';
        $manifest = $directory . '/yoro-preview-public.json';
        if ( ! is_file( $manifest ) || is_link( $manifest ) || ! is_readable( $manifest ) || filesize( $manifest ) > 4096 ) {
            return [];
        }
        $metadata = json_decode( (string) file_get_contents( $manifest ), true );
        return self::validate( $metadata, $server_url, $directory, YORO_AI_CORE_URL . 'assets/downloads/' );
    }

    /** Validate trusted release metadata and local bytes, never a user-supplied URL. */
    public static function validate( $metadata, $server_url, $directory, $base_url ) {
        if ( ! is_array( $metadata ) || count( $metadata ) !== 4
            || array_diff( array_keys( $metadata ), [ 'version', 'resource', 'filename', 'sha256' ] )
            || ( $metadata['version'] ?? '' ) !== self::VERSION
            || ( $metadata['resource'] ?? '' ) !== self::RESOURCE || $server_url !== self::RESOURCE
            || ( $metadata['filename'] ?? '' ) !== self::FILENAME
            || ( $metadata['sha256'] ?? '' ) !== self::SHA256 ) {
            return [];
        }
        $url = parse_url( $base_url );
        if ( ! is_array( $url ) || 'https' !== ( $url['scheme'] ?? '' ) || empty( $url['host'] )
            || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) {
            return [];
        }
        $root = realpath( $directory );
        $path = $directory . '/' . self::FILENAME;
        $real = realpath( $path );
        if ( false === $root || false === $real || is_link( $directory ) || is_link( $path )
            || dirname( $real ) !== $root || ! is_file( $path ) || ! is_readable( $path )
            || ! hash_equals( $metadata['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
            return [];
        }
        return [ 'version' => self::VERSION, 'sha256' => $metadata['sha256'], 'url' => rtrim( $base_url, '/' ) . '/' . self::FILENAME ];
    }
}
