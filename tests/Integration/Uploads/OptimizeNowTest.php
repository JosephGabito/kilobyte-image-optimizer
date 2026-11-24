<?php
/**
 * The "Optimize now" button the Media Library shows next to a queued or failed upload.
 *
 * The button sends POST /kilobyte-io/v1/optimize-server/{id} with retry_upload=true.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class OptimizeNowTest extends UploadTestCase {

	public function test_optimize_now_makes_the_copy_and_clears_the_failure_message(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		add_filter( 'wp_image_editors', '__return_empty_array' );
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$this->make_upload_job_due( $id );
			$this->run_background_worker();
		}
		remove_filter( 'wp_image_editors', '__return_empty_array' );

		$result = $this->assertRestOk( $this->optimize_now( $id ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
		$this->assertStringContainsString( '(WEBP)', $this->media_library_status( $id ) );
	}

	public function test_optimize_now_on_a_queued_upload_cancels_the_background_job(): void {
		$id = $this->upload_through_rest( Images::jpeg() );

		$this->assertRestOk( $this->optimize_now( $id ) );
		$copies = $this->delivered_copies( $id );
		// The upload's ten-second delay passes and WP-Cron runs the worker.
		WorkerClock::$wall_offset = 11;
		$this->run_background_worker();

		$this->assertNotEmpty( $copies );
		$this->assertSame( $copies, $this->delivered_copies( $id ), 'The worker must not process the image again.' );
		$this->assertNull( $this->seconds_until_worker_runs(), 'With the job gone, the worker has nothing left to schedule.' );
	}

	public function test_optimize_now_on_an_excluded_image_says_why_it_was_left_alone(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		add_filter( 'kilobyte_io_should_optimize', '__return_false' );

		$result = $this->assertRestOk( $this->optimize_now( $id ) );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'excluded', $result['status'] );
		$this->assertSame( 'A rule on this site excludes this image from optimization.', $this->media_library_status( $id ) );
	}

	public function test_optimize_now_reports_a_missing_original_and_keeps_the_job(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		wp_delete_file( (string) get_attached_file( $id ) );

		$this->assertRestFailure( 'missing_source', 404, $this->optimize_now( $id ) );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	public function test_optimize_now_waits_while_another_request_works_on_the_image(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertRestFailure( 'locked', 409, $this->optimize_now( $id ) );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	public function test_a_skip_is_not_recorded_after_the_database_reconnects(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		add_filter( 'kilobyte_io_should_optimize', '__return_false' );
		$this->reconnect_database();

		$this->optimize_now( $id );

		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	/**
	 * Presses "Optimize now" in the Media Library.
	 *
	 * @param int $id Attachment ID.
	 * @return \WP_REST_Response
	 */
	private function optimize_now( int $id ): \WP_REST_Response {
		return $this->rest( 'POST', "/optimize-server/{$id}", array( 'retry_upload' => true ) );
	}
}
