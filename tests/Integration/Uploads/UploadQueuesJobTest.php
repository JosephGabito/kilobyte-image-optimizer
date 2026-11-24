<?php
/**
 * Uploading an image queues a background job that makes the optimized copy.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class UploadQueuesJobTest extends UploadTestCase {

	public function test_an_upload_is_queued_and_the_worker_makes_the_copy_once_the_job_is_due(): void {
		$id = $this->upload_through_rest( Images::jpeg() );

		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
		$this->assertNotNull( $this->seconds_until_worker_runs(), 'The upload must schedule the background worker.' );
		$this->assertSame( array(), $this->delivered_copies( $id ) );

		$this->make_upload_job_due( $id );
		$this->run_background_worker();

		$this->assertNotEmpty( $this->delivered_copies( $id ) );
		$this->assertStringContainsString( '(WEBP)', $this->media_library_status( $id ) );
	}

	public function test_the_worker_leaves_a_job_alone_until_its_delay_has_passed(): void {
		$id = $this->upload_through_rest( Images::jpeg() );

		$this->run_background_worker();

		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
		$this->assertNotNull( $this->seconds_until_worker_runs(), 'The worker must stay scheduled for the waiting job.' );
	}

	public function test_a_server_side_upload_waits_ten_seconds_before_the_copy_is_made(): void {
		$id = $this->upload_through_rest( Images::jpeg() );

		WorkerClock::$wall_offset = 11;
		$this->run_background_worker();

		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_an_upload_whose_browser_makes_the_copy_gives_the_browser_five_minutes(): void {
		$id = $this->upload_through_rest( Images::jpeg(), array(), array( 'X-Kilobyte-Browser-Optimize' => '1' ) );

		WorkerClock::$wall_offset = 4 * MINUTE_IN_SECONDS;
		$this->run_background_worker();
		$this->assertSame( array(), $this->delivered_copies( $id ), 'The server must not make the copy while the browser may still send one.' );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );

		WorkerClock::$wall_offset = 5 * MINUTE_IN_SECONDS + 1;
		$this->run_background_worker();
		$this->assertNotEmpty( $this->delivered_copies( $id ), 'The server must make the copy if the browser tab closed.' );
	}

	public function test_an_upload_added_by_code_with_media_handle_sideload_is_queued(): void {
		$file = Images::jpeg( 640, 480, 'sideloaded.jpg' );
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_sideload(
			array(
				'name'     => 'sideloaded.jpg',
				'tmp_name' => $file,
			)
		);

		$this->assertIsInt( $id );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	public function test_nothing_is_queued_while_automatic_optimization_is_turned_off(): void {
		$this->set_preferences( array( 'enabled' => false ) );

		$id = $this->upload_through_rest( Images::jpeg() );

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_regenerating_thumbnails_of_an_existing_image_queues_nothing(): void {
		$this->set_preferences( array( 'enabled' => false ) );
		$id = $this->upload( Images::jpeg() );
		$this->set_preferences( array( 'enabled' => true ) );

		wp_generate_attachment_metadata( $id, (string) get_attached_file( $id ) );

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_an_upload_shows_a_scheduling_failure_when_wordpress_cron_refuses_the_worker(): void {
		add_filter( 'pre_schedule_event', '__return_false' );

		$id = $this->upload_through_rest( Images::jpeg() );

		$this->assertStringStartsWith(
			'Original saved. WordPress could not schedule optimization.',
			$this->media_library_status( $id )
		);
		$this->assertStringContainsString( 'Optimize now', $this->media_library_status( $id ) );
	}

	public function test_a_scheduling_filter_that_throws_does_not_break_the_upload(): void {
		add_filter(
			'pre_schedule_event',
			static function () {
				throw new \RuntimeException( 'A scheduling plugin failed.' );
			}
		);

		$id = $this->upload_through_rest( Images::jpeg() );

		$this->assertSame( 'attachment', get_post_type( $id ) );
		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_an_upload_queues_nothing_after_the_database_reconnects(): void {
		$this->reconnect_database();

		$id = $this->upload_through_rest( Images::jpeg() );

		$this->assertNull( $this->seconds_until_worker_runs() );
		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
	}
}
