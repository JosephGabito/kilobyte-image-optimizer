<?php
/**
 * Activating and deactivating the plugin for every site of a multisite network.
 *
 * These tests run only when the suite runs as multisite (WP_MULTISITE=1).
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Presentation\Hooks\AutomaticUploadOptimization;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class NetworkLifecycleTest extends UploadTestCase {

	/**
	 * A second site in the network.
	 *
	 * @var int
	 */
	private int $other_site;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network activation needs a multisite run (WP_MULTISITE=1).' );
		}
		$this->other_site = (int) self::factory()->blog->create();
	}

	public function test_network_activation_restarts_lost_workers_on_every_site(): void {
		$this->queue_an_upload_and_lose_the_worker( get_current_blog_id() );
		$this->queue_an_upload_and_lose_the_worker( $this->other_site );

		kilobyte_io_activate( true );

		$this->assertNotNull( $this->seconds_until_worker_runs(), 'The main site must get its worker back.' );
		switch_to_blog( $this->other_site );
		$scheduled = $this->seconds_until_worker_runs();
		restore_current_blog();
		$this->assertNotNull( $scheduled, 'The second site must get its worker back.' );
	}

	public function test_network_deactivation_stops_the_worker_on_every_site(): void {
		$this->upload( Images::jpeg() );
		switch_to_blog( $this->other_site );
		$this->upload( Images::jpeg() );
		restore_current_blog();

		kilobyte_io_deactivate( true );

		$this->assertNull( $this->seconds_until_worker_runs() );
		switch_to_blog( $this->other_site );
		$scheduled = $this->seconds_until_worker_runs();
		restore_current_blog();
		$this->assertNull( $scheduled );
	}

	/**
	 * Uploads an image on a site, then drops its worker event as a cron reset would.
	 *
	 * @param int $site Site ID.
	 * @return void
	 */
	private function queue_an_upload_and_lose_the_worker( int $site ): void {
		switch_to_blog( $site );
		$this->upload( Images::jpeg() );
		wp_unschedule_hook( AutomaticUploadOptimization::EVENT );
		$this->assertNull( $this->seconds_until_worker_runs() );
		restore_current_blog();
	}
}
