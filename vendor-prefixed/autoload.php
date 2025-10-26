<?php
/**
 * Simple autoloader for vendor-prefixed dependencies bundled with the plugin.
 *
 * @package WPGraphQL\WooCommerce
 */

declare( strict_types=1 );

spl_autoload_register(
    static function ( string $class ): void {
        $root_prefix = 'WPGraphQL\\WooCommerce\\Vendor\\';

        if ( 0 !== strncmp( $class, $root_prefix, strlen( $root_prefix ) ) ) {
            return;
        }

        $relative_class = substr( $class, strlen( $root_prefix ) );

        $package_map = [
            'Firebase\\JWT\\' => __DIR__ . '/firebase/php-jwt/src/',
        ];

        foreach ( $package_map as $package_prefix => $base_dir ) {
            if ( 0 !== strncmp( $relative_class, $package_prefix, strlen( $package_prefix ) ) ) {
                continue;
            }

            $relative_path = substr( $relative_class, strlen( $package_prefix ) );
            $file          = $base_dir . str_replace( '\\', '/', $relative_path ) . '.php';

            if ( file_exists( $file ) ) {
                require_once $file;
            }

            return;
        }
    }
);
