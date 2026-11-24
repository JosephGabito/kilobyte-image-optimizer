<?php
/**
 * PHPUnit bootstrap: load the WordPress test library with the plugin active.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

$plugin_root = dirname( __DIR__ );

require_once $plugin_root . '/vendor/autoload.php';
require_once $plugin_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_root . '/vendor/yoast/phpunit-polyfills' );

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: $plugin_root . '/vendor/wp-phpunit/wp-phpunit';

require_once __DIR__ . '/Support/UploadedFiles.php';

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ): void {
		require $plugin_root . '/kilobyte-image-optimizer.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/Support/Images.php';
require_once __DIR__ . '/Support/TestCase.php';

// Start every run with an empty uploads folder that the test library can scan.
$kilobyte_uploads = wp_upload_dir( null, false )['basedir'];
if ( is_dir( $kilobyte_uploads ) ) {
	exec( 'rm -rf ' . escapeshellarg( $kilobyte_uploads ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
}
wp_mkdir_p( $kilobyte_uploads );
