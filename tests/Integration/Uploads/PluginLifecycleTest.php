<?php
/**
 * Activating and deactivating the plugin, and restarting a background worker that WordPress lost.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Presentation\Hooks\AutomaticUploadOptimization;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class PluginLifecycleTest extends UploadTestCase {

	public function test_deactivating_stops_the_worker_and_keeps_queued_uploads(): void {
		$id = $this->upload_through_rest( Images::jpeg() );

		kilobyte_io_deactivate();

		$this->assertNull( $this->seconds_until_worker_runs() );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	public function test_activating_again_restarts_the_worker_for_uploads_still_queued(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		// An admin page visit a moment ago used up the once-a-minute check for a lost worker.
		wp_unschedule_hook( AutomaticUploadOptimization::EVENT );
		$this->open_an_admin_page();
		kilobyte_io_deactivate();

		kilobyte_io_activate();

		$this->assertNotNull( $this->seconds_until_worker_runs() );
		$this->make_upload_job_due( $id );
		$this->run_background_worker();
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_activating_with_nothing_queued_schedules_no_worker(): void {
		kilobyte_io_activate();

		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_activating_keeps_the_preferences_the_site_already_saved(): void {
		$this->set_preferences( array( 'enabled' => false ) );

		kilobyte_io_activate();
		$id = $this->upload_through_rest( Images::jpeg() );

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
	}

	public function test_activating_a_fresh_install_saves_the_default_preferences(): void {
		delete_option( 'kilobyte_io_settings' );

		kilobyte_io_activate();

		$saved = get_option( 'kilobyte_io_settings' );
		$this->assertIsArray( $saved );
		$this->assertTrue( $saved['enabled'] );
		$this->assertSame( 'webp', $saved['format'] );
	}

	public function test_a_scheduling_filter_that_throws_does_not_stop_deactivation(): void {
		$this->upload_through_rest( Images::jpeg() );
		add_filter(
			'pre_unschedule_hook',
			static function () {
				throw new \RuntimeException( 'A scheduling plugin failed.' );
			}
		);

		kilobyte_io_deactivate();

		$this->assertNotNull( $this->seconds_until_worker_runs(), 'The worker stays scheduled when WordPress could not remove it.' );
	}

	public function test_an_admin_page_visit_restarts_a_worker_that_wordpress_lost(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		// Another plugin rewrote the cron option and dropped the event.
		wp_unschedule_hook( AutomaticUploadOptimization::EVENT );

		$this->open_an_admin_page();

		$this->assertNotNull( $this->seconds_until_worker_runs() );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	public function test_admin_page_visits_check_for_a_lost_worker_at_most_once_a_minute(): void {
		$this->upload_through_rest( Images::jpeg() );
		wp_unschedule_hook( AutomaticUploadOptimization::EVENT );
		$this->open_an_admin_page();
		wp_unschedule_hook( AutomaticUploadOptimization::EVENT );

		$this->open_an_admin_page();

		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_an_admin_page_visit_with_nothing_queued_schedules_no_worker(): void {
		$this->open_an_admin_page();

		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_a_broken_transient_store_does_not_break_admin_pages(): void {
		$this->upload_through_rest( Images::jpeg() );
		wp_unschedule_hook( AutomaticUploadOptimization::EVENT );
		add_filter(
			'pre_transient_kilobyte_io_auto_recovery_checked',
			static function () {
				throw new \RuntimeException( 'The object cache is down.' );
			}
		);

		$this->open_an_admin_page();

		$this->assertNull( $this->seconds_until_worker_runs(), 'The next admin visit retries.' );
	}

	/**
	 * Runs what Kilobyte hooks to admin_init. Core's own admin_init callbacks send
	 * HTTP headers, which a command-line test run cannot do.
	 *
	 * @return void
	 */
	private function open_an_admin_page(): void {
		$callback = array( AutomaticUploadOptimization::class, 'resume_if_needed' );
		$this->assertSame( 10, has_action( 'admin_init', $callback ), 'Kilobyte no longer checks for a lost worker on admin pages.' );
		call_user_func( $callback );
	}
}
