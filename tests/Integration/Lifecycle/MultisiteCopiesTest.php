<?php
/**
 * On multisite, Kilobyte keeps copies on disk instead of cleaning them up.
 *
 * Run with WP_MULTISITE=1. As a single site these tests are skipped.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;

require_once __DIR__ . '/LifecycleTestCase.php';

final class MultisiteCopiesTest extends LifecycleTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'These tests describe multisite behaviour.' );
		}
	}

	public function test_restoring_keeps_the_copies_on_disk(): void {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );

		$restored = $this->assertRestOk( $this->restore( $id ) );
		$this->run_cleanup_cron();

		$this->assertTrue( $restored['copy_retained'] );
		$this->assertNotEmpty( $copies );
		$this->assertFilesExist( $copies );
		$this->assertFalse( wp_next_scheduled( WpVariantRetirement::EVENT ), 'Multisite must not keep an hourly cleanup scheduled.' );
	}

	public function test_deleting_an_optimized_image_leaves_its_copies_on_disk(): void {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );

		$this->assertNotFalse( wp_delete_attachment( $id, true ) );
		$this->make_cleanup_batches_due();
		$this->run_cleanup_cron();

		$this->assertNotEmpty( $copies );
		$this->assertFilesExist( $copies );
	}
}
