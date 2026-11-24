<?php
/**
 * What happens to the rest of a request after WordPress fails to delete an image.
 *
 * Each test runs in its own PHP process. The bug these tests describe leaves locks held for
 * the rest of the process, which would otherwise break every test that runs after them.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LifecycleTestCase.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FailedDeletionTest extends LifecycleTestCase {

	/**
	 * Value of WP_TESTS_SKIP_INSTALL before this class ran.
	 *
	 * @var string|false
	 */
	private static $skip_install;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		// The separate PHP processes reuse the tables this run already installed.
		self::$skip_install = getenv( 'WP_TESTS_SKIP_INSTALL' );
		putenv( 'WP_TESTS_SKIP_INSTALL=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Read by the separate process's bootstrap.
	}

	public static function tear_down_after_class(): void {
		putenv( false === self::$skip_install ? 'WP_TESTS_SKIP_INSTALL' : 'WP_TESTS_SKIP_INSTALL=' . self::$skip_install ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restores the value.
		parent::tear_down_after_class();
	}

	/**
	 * Known bug: the locks taken before deleting are only released by the `deleted_post` hook,
	 * which WordPress skips when its delete query fails.
	 *
	 * @group known-bug
	 */
	public function test_a_failed_deletion_does_not_stop_the_next_image_from_being_deleted(): void {
		$first  = $this->optimized_jpeg();
		$second = $this->optimized_jpeg();
		$this->fail_deleting_post_row( $first );

		$this->assertNotFalse( wp_delete_attachment( $second, true ), 'The second image must still be deletable.' );
	}

	/**
	 * Known bug: same cause. The stats lock is still held, so the next save fails.
	 *
	 * @group known-bug
	 */
	public function test_a_failed_deletion_does_not_stop_other_images_from_being_optimized(): void {
		$this->fail_deleting_post_row( $this->optimized_jpeg() );
		$next = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertFalse( $this->assertRestOk( $this->optimize_on_server( $next ) )['skipped'] );
	}

	/**
	 * Deletes an optimized image while the database refuses to remove its post row.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function fail_deleting_post_row( int $id ): void {
		global $wpdb;
		$row    = "DELETE FROM `{$wpdb->posts}` WHERE `ID` = {$id}";
		$refuse = static fn ( $sql ) => is_string( $sql ) && 0 === strpos( $sql, $row ) ? 'SELECT * FROM kilobyte_missing_table' : $sql;
		add_filter( 'query', $refuse );
		$errors = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( wp_delete_attachment( $id, true ), 'WordPress should report the failed deletion.' );
		} finally {
			$wpdb->suppress_errors( $errors );
			remove_filter( 'query', $refuse );
		}
	}
}
