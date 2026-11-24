<?php
/**
 * The background worker that WP-Cron runs to make copies of uploaded images.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class BackgroundWorkerTest extends UploadTestCase {

	private const ENCODE_FAILED = 'Original saved. The server could not compress this image.';

	public function test_a_job_that_fails_is_tried_three_times_then_shown_as_a_failure_with_its_reason(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->break_image_editors();

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$this->make_upload_job_due( $id );
			$this->run_background_worker();
			$this->assertSame( self::QUEUED, $this->media_library_status( $id ), "Attempt {$attempt} must leave the job queued for another try." );
		}
		$this->make_upload_job_due( $id );
		$this->run_background_worker();

		$this->assertStringStartsWith( self::ENCODE_FAILED, $this->media_library_status( $id ) );
		$this->assertStringContainsString( 'Optimize now', $this->media_library_status( $id ) );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_a_failure_message_is_dropped_once_the_original_file_changes(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->break_image_editors();
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$this->make_upload_job_due( $id );
			$this->run_background_worker();
		}
		$this->assertStringStartsWith( self::ENCODE_FAILED, $this->media_library_status( $id ) );

		// Another media tool writes a new file over the original.
		copy( Images::jpeg( 320, 240 ), (string) get_attached_file( $id ) );
		clearstatcache();

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
	}

	public function test_an_error_thrown_while_encoding_counts_as_an_interrupted_attempt_and_is_retried(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		add_filter(
			'wp_image_editors',
			static function () {
				throw new \RuntimeException( 'The image library crashed.' );
			}
		);

		$this->make_upload_job_due( $id );
		$this->run_background_worker();

		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	public function test_a_job_cut_short_three_times_by_a_php_timeout_ends_as_interrupted(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		// A PHP timeout kills the request after the attempt is recorded, three times over.
		update_post_meta( $id, WpAttachmentRepository::AUTO_ATTEMPTS, 3 );
		$this->make_upload_job_due( $id );

		$this->run_background_worker();

		$this->assertStringStartsWith(
			'Original saved. Background optimization was interrupted.',
			$this->media_library_status( $id )
		);
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_an_upload_whose_original_file_is_gone_fails_at_once(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		wp_delete_file( (string) get_attached_file( $id ) );

		$this->make_upload_job_due( $id );
		$this->run_background_worker();

		$this->assertStringStartsWith(
			'Original saved. WordPress could not open the source file.',
			$this->media_library_status( $id )
		);
	}

	public function test_an_image_excluded_by_a_site_rule_shows_why_it_was_left_alone(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		add_filter( 'kilobyte_io_should_optimize', '__return_false' );

		$this->make_upload_job_due( $id );
		$this->run_background_worker();

		$this->assertSame( 'A rule on this site excludes this image from optimization.', $this->media_library_status( $id ) );
	}

	public function test_turning_optimization_off_drops_jobs_that_have_not_run_yet(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->set_preferences( array( 'enabled' => false ) );

		$this->make_upload_job_due( $id );
		$this->run_background_worker();

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_an_image_moved_to_the_trash_is_not_optimized(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		wp_trash_post( $id );

		$this->make_upload_job_due( $id );
		$this->run_background_worker();

		$this->assertSame( self::NOT_OPTIMIZED, $this->media_library_status( $id ) );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_with_nothing_queued_the_worker_does_not_schedule_itself_again(): void {
		$this->run_background_worker();

		$this->assertNull( $this->seconds_until_worker_runs() );
	}

	public function test_a_worker_already_running_in_another_request_makes_this_one_try_again_in_a_minute(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $id );
		$this->hold_lock_in_other_request( 'automatic_uploads' );

		$this->run_background_worker();

		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertEqualsWithDelta( MINUTE_IN_SECONDS, $this->seconds_until_worker_runs(), 2 );
	}

	public function test_an_image_locked_by_another_request_keeps_its_job_for_the_next_run(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $id );
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->run_background_worker();

		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
		$this->assertNotNull( $this->seconds_until_worker_runs() );
	}

	public function test_jobs_are_marked_not_queued_when_the_worker_cannot_schedule_its_next_run(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $id );
		add_filter( 'pre_schedule_event', '__return_false' );

		$this->run_background_worker();

		$this->assertStringStartsWith(
			'Original saved. WordPress could not schedule optimization.',
			$this->media_library_status( $id )
		);
		$this->assertSame( array(), $this->delivered_copies( $id ), 'Without a next run in place, no encode may start.' );
	}

	public function test_a_job_is_marked_not_queued_when_wordpress_cannot_record_the_attempt(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $id );
		add_filter(
			'update_post_metadata',
			static fn ( $check, $object_id, $meta_key ) => WpAttachmentRepository::AUTO_PENDING === $meta_key ? false : $check,
			10,
			3
		);

		$this->run_background_worker();

		$this->assertStringStartsWith(
			'Original saved. WordPress could not schedule optimization.',
			$this->media_library_status( $id )
		);
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_a_job_overdue_by_more_than_five_minutes_tells_the_site_owner_to_check_scheduled_tasks(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		// WP-Cron has not run for ten minutes since the job became due.
		update_post_meta( $id, WpAttachmentRepository::AUTO_PENDING, time() - 10 * MINUTE_IN_SECONDS );

		$this->assertStringStartsWith(
			'Original saved. Background optimization is overdue.',
			$this->media_library_status( $id )
		);
	}

	public function test_a_failure_saved_by_an_older_plugin_version_is_still_shown(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		wp_unschedule_hook( 'kilobyte_io_optimize_pending_uploads' );
		delete_post_meta( $id, WpAttachmentRepository::AUTO_PENDING );
		// Older versions stored the bare reason, with no file stamp.
		update_post_meta( $id, '_kilobyte_io_auto_failure', 'job_interrupted' );

		$this->assertStringStartsWith(
			'Original saved. Background optimization was interrupted.',
			$this->media_library_status( $id )
		);
	}

	public function test_the_worker_leaves_jobs_alone_after_the_database_reconnects(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $id );
		$this->reconnect_database();

		$this->run_background_worker();

		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	public function test_an_encode_that_finishes_after_the_database_reconnects_keeps_its_job_queued(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $id );
		add_filter(
			'wp_image_editors',
			function ( $editors ) {
				$this->reconnect_database();
				return $editors;
			}
		);

		$this->run_background_worker();

		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}

	public function test_a_job_reached_after_the_time_budget_is_spent_is_left_for_the_next_run(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $id );
		// The worker read its start time ten seconds ago, so its 4-second budget is
		// already spent when the encoder checks the real clock.
		WorkerClock::$budget_offset = -10.0;

		$this->run_background_worker();
		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );

		WorkerClock::$budget_offset = 0.0;
		$this->run_background_worker();
		$this->assertNotEmpty( $this->delivered_copies( $id ), 'The deferred job must keep its original due time.' );
	}

	public function test_the_worker_starts_no_new_job_once_its_time_budget_is_spent(): void {
		$first  = $this->upload_through_rest( Images::jpeg() );
		$second = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $first );
		$this->make_upload_job_due( $second );
		add_action(
			'kilobyte_io_after_optimize',
			static function (): void {
				// The first copy took five seconds.
				WorkerClock::$budget_offset += 5.0;
			}
		);

		$this->run_background_worker();

		$this->assertNotEmpty( $this->delivered_copies( $first ) );
		$this->assertSame( array(), $this->delivered_copies( $second ) );
		$this->assertSame( self::QUEUED, $this->media_library_status( $second ) );
		$this->assertEqualsWithDelta( MINUTE_IN_SECONDS, $this->seconds_until_worker_runs(), 2, 'The next run must already be scheduled.' );
	}

	public function test_the_worker_stops_before_an_attempt_when_the_budget_runs_out_during_its_checks(): void {
		$id = $this->upload_through_rest( Images::jpeg() );
		$this->make_upload_job_due( $id );
		$slow_read = static function ( $settings ) {
			// Reading the preferences took five seconds on a slow database.
			WorkerClock::$budget_offset = 5.0;
			return $settings;
		};
		add_filter( 'kilobyte_io_settings', $slow_read );

		$this->run_background_worker();
		remove_filter( 'kilobyte_io_settings', $slow_read );
		WorkerClock::$budget_offset = 0.0;

		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
		// Three more failed runs are still allowed, so the skipped run was not counted.
		$this->break_image_editors();
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$this->make_upload_job_due( $id );
			$this->run_background_worker();
		}
		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
	}
}
