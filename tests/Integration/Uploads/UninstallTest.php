<?php
/**
 * Deleting the plugin from the Plugins screen, which runs uninstall.php.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Presentation\Hooks\AutomaticUploadOptimization;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class UninstallTest extends UploadTestCase {

	/**
	 * Value of WP_TESTS_SKIP_INSTALL before this class ran.
	 *
	 * @var string|false
	 */
	private static $skip_install;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		// The separate PHP process reuses the tables this run already installed.
		self::$skip_install = getenv( 'WP_TESTS_SKIP_INSTALL' );
		putenv( 'WP_TESTS_SKIP_INSTALL=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Read by the separate process's bootstrap.
	}

	public static function tear_down_after_class(): void {
		putenv( false === self::$skip_install ? 'WP_TESTS_SKIP_INSTALL' : 'WP_TESTS_SKIP_INSTALL=' . self::$skip_install ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restores the value.
		parent::tear_down_after_class();
	}

	/**
	 * uninstall.php declares a global function, so it can run only once per PHP process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstalling_removes_preferences_and_scheduled_work_and_keeps_optimized_copies(): void {
		$this->set_preferences( array( 'format' => 'webp' ) );
		$optimized = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $optimized ) );
		$queued = $this->upload( Images::jpeg() );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', WpVariantRetirement::EVENT );
		$this->assertNotFalse( wp_next_scheduled( AutomaticUploadOptimization::EVENT ) );
		update_option( 'kilobyte_io_resume_cleanup', true );

		define( 'WP_UNINSTALL_PLUGIN', 'kilobyte-image-optimizer/kilobyte-image-optimizer.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress defines this before it loads uninstall.php.
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertFalse( get_option( 'kilobyte_io_settings' ) );
		$this->assertFalse( get_option( 'kilobyte_io_resume_cleanup' ) );
		$this->assertFalse( wp_next_scheduled( AutomaticUploadOptimization::EVENT ) );
		$this->assertFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
		$this->assertNotEmpty( $this->delivered_copies( $optimized ), 'Optimized copies and their records must stay for a reinstall.' );
		$this->assertSame( self::QUEUED, $this->media_library_status( $queued ), 'Queued uploads must stay for a reinstall.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstalling_still_removes_preferences_when_a_scheduling_filter_throws(): void {
		$this->set_preferences( array( 'format' => 'webp' ) );
		$this->upload( Images::jpeg() );
		add_filter(
			'pre_unschedule_hook',
			static function () {
				throw new \RuntimeException( 'A scheduling plugin failed.' );
			}
		);

		define( 'WP_UNINSTALL_PLUGIN', 'kilobyte-image-optimizer/kilobyte-image-optimizer.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress defines this before it loads uninstall.php.
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertFalse( get_option( 'kilobyte_io_settings' ) );
		$this->assertNotFalse( wp_next_scheduled( AutomaticUploadOptimization::EVENT ), 'The worker stays scheduled when WordPress could not remove it.' );
	}
}
