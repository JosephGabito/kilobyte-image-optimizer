<?php
/**
 * WordPress test library configuration.
 *
 * The test library drops and recreates every table in this database on each run.
 * Point it at a database that holds nothing else.
 *
 * @package KilobyteImageOptimizer\Tests
 */

$kilobyte_tests_env = static function ( string $name, string $fallback ): string {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
};

define( 'ABSPATH', rtrim( $kilobyte_tests_env( 'WP_CORE_DIR', dirname( __DIR__ ) . '/.cache/wordpress' ), '/' ) . '/' );

define( 'DB_NAME', $kilobyte_tests_env( 'WP_TESTS_DB_NAME', 'kilobyte_tests' ) );
define( 'DB_USER', $kilobyte_tests_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $kilobyte_tests_env( 'WP_TESTS_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', $kilobyte_tests_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Kilobyte tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
define( 'FS_METHOD', 'direct' );

define( 'AUTH_KEY', 'kilobyte-tests' );
define( 'SECURE_AUTH_KEY', 'kilobyte-tests' );
define( 'LOGGED_IN_KEY', 'kilobyte-tests' );
define( 'NONCE_KEY', 'kilobyte-tests' );
define( 'AUTH_SALT', 'kilobyte-tests' );
define( 'SECURE_AUTH_SALT', 'kilobyte-tests' );
define( 'LOGGED_IN_SALT', 'kilobyte-tests' );
define( 'NONCE_SALT', 'kilobyte-tests' );

// Each test database gets its own uploads folder, so parallel runs never share files.
define( 'UPLOADS', 'wp-content/uploads-' . DB_NAME );
