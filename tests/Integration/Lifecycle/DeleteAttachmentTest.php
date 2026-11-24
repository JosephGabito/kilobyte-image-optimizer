<?php
/**
 * Deleting an image from the Media Library and removing the copies Kilobyte made for it.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LifecycleTestCase.php';

final class DeleteAttachmentTest extends LifecycleTestCase {

	public function test_deleting_an_optimized_image_leaves_its_copies_until_the_cleanup_runs(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_attachment( $id, true ) );

		$this->assertNull( get_post( $id ) );
		$this->assertFilesExist( $copies, 'Copies are removed by the cleanup event, not during the deletion request.' );
		$this->assertNotFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
	}

	public function test_the_cleanup_removes_the_copies_of_a_deleted_image_once_due(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		wp_delete_attachment( $id, true );
		$this->assertSame( 1, $this->make_cleanup_batches_due() );

		$this->run_cleanup_cron();

		$this->assertFilesGone( $copies );
		$this->assertSame( array(), $this->cleanup_batches(), 'Finished cleanup work must be removed.' );
	}

	public function test_the_cleanup_waits_for_the_short_delay_after_a_deletion(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		wp_delete_attachment( $id, true );

		$this->run_cleanup_cron();

		$this->assertFilesExist( $copies );
		$this->assertCount( 1, $this->cleanup_batches() );
	}

	public function test_deleting_an_optimized_image_updates_the_dashboard_counters(): void {
		$id = $this->optimized_jpeg();

		wp_delete_attachment( $id, true );

		$stats = $this->stats();
		$this->assertSame( 0, $stats['optimized_count'] );
		$this->assertSame( 0, $stats['total_original'] );
		$this->assertFalse( $stats['needs_reconcile'] );
	}

	public function test_deleting_a_restored_image_removes_the_copies_still_waiting_for_their_day(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$this->assertRestOk( $this->restore( $id ) );

		wp_delete_attachment( $id, true );
		$this->make_cleanup_batches_due();
		$this->run_cleanup_cron();

		$this->assertFilesGone( $copies );
	}

	public function test_deleting_an_image_another_request_is_working_on_is_refused(): void {
		$id       = $this->optimized_jpeg();
		$original = (string) get_attached_file( $id );
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertFalse( wp_delete_attachment( $id, true ) );

		$this->assertInstanceOf( \WP_Post::class, get_post( $id ) );
		$this->assertFileExists( $original );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_deleting_an_optimized_image_while_the_counters_are_being_written_is_refused(): void {
		$id = $this->optimized_jpeg();
		$this->hold_lock_in_other_request( 'stats' );

		$this->assertFalse( wp_delete_attachment( $id, true ) );

		$this->assertInstanceOf( \WP_Post::class, get_post( $id ) );
		$copies = $this->copies_on_disk( $id );
		$this->make_cleanup_batches_due();
		$this->run_cleanup_cron();
		$this->assertFilesExist( $copies, 'The cleanup saved before the refusal must not touch copies the image still uses.' );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_deleting_an_image_is_refused_when_its_cleanup_cannot_be_saved(): void {
		$this->skip_on_multisite();
		$id       = $this->optimized_jpeg();
		$original = (string) get_attached_file( $id );
		$this->fail_to_store_cleanup_batches();
		add_filter( 'pre_schedule_event', '__return_false' );

		$this->assertFalse( wp_delete_attachment( $id, true ) );

		$this->assertFileExists( $original );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_a_deletion_whose_cleanup_could_not_be_stored_retries_through_a_one_time_event(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$block  = $this->fail_to_store_cleanup_batches();

		wp_delete_attachment( $id, true );

		$this->assertNull( get_post( $id ) );
		$event = $this->scheduled_retry();
		$this->assertNotNull( $event, 'A one-time retry should hold the paths.' );
		remove_filter( 'query', $block );
		do_action_ref_array( 'kilobyte_io_retry_variant_cleanup', $event['args'] );
		$this->make_cleanup_batches_due();
		$this->run_cleanup_cron();
		$this->assertFilesGone( $copies );
	}

	public function test_without_database_locks_the_image_is_deleted_and_the_counters_are_flagged_for_repair(): void {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		add_filter( 'query', static fn ( $sql ) => false !== strpos( (string) $sql, 'GET_LOCK(' ) ? 'SELECT NULL, NULL' : $sql );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_attachment( $id, true ) );

		$this->assertTrue( $this->stats()['needs_reconcile'] );
		$this->assertFilesExist( $copies, 'Without a lock the plugin must leave its files alone.' );
		$this->assertSame( array(), $this->cleanup_batches() );
	}

	public function test_deleting_an_image_that_was_never_optimized_needs_no_cleanup(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_attachment( $id, true ) );

		$this->assertSame( array(), $this->cleanup_batches() );
		$this->assertFalse( $this->stats()['needs_reconcile'] );
	}

	public function test_a_document_is_deleted_even_while_its_id_is_locked(): void {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => 'report.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_attachment( $id, true ) );
	}

	public function test_a_cleanup_job_for_an_image_whose_deletion_did_not_finish_keeps_its_copies(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$paths  = array_map( '_wp_relative_upload_path', $copies );
		// An older version stored cleanup jobs as one-time events. This one names an image that still exists.
		do_action( 'kilobyte_io_retry_variant_cleanup', $paths, 0, $id );
		$this->make_cleanup_batches_due();

		$this->run_cleanup_cron();

		$this->assertFilesExist( $copies );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
		$this->assertSame( array(), $this->cleanup_batches(), 'The job is finished because the image still owns its copies.' );
	}

	public function test_a_cleanup_job_for_an_existing_image_is_retried_when_the_image_no_longer_tracks_the_files(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$paths  = array_map( '_wp_relative_upload_path', $copies );
		$this->assertRestOk( $this->restore( $id ) );
		delete_post_meta( $id, self::RETIRED_META );
		do_action( 'kilobyte_io_retry_variant_cleanup', $paths, 0, $id );
		$this->make_cleanup_batches_due();

		$this->run_cleanup_cron();

		$this->assertFilesExist( $copies );
		$batches = $this->cleanup_batches();
		$this->assertCount( 1, $batches );
		$this->assertGreaterThan( time() + 50 * MINUTE_IN_SECONDS, (int) substr( $batches[0], strlen( self::BATCH_PREFIX ), 12 ), 'The job should be retried in about an hour.' );
	}

	public function test_a_restore_started_while_wordpress_deletes_the_image_is_refused(): void {
		$id       = $this->optimized_jpeg();
		$response = null;
		add_action(
			'delete_attachment',
			function ( int $deleting ) use ( $id, &$response ): void {
				if ( $deleting === $id ) {
					$response = $this->restore( $id );
				}
			}
		);

		wp_delete_attachment( $id, true );

		$this->assertNotNull( $response );
		$this->assertRestFailure( 'locked', 409, $response );
	}

	public function test_deleting_an_optimized_image_is_refused_when_the_counter_repair_marker_cannot_be_saved(): void {
		$id = $this->optimized_jpeg();
		global $wpdb;
		$table = $wpdb->options;
		add_filter(
			'query',
			static fn ( $sql ) => is_string( $sql ) && 0 === strpos( $sql, "INSERT INTO `{$table}`" ) && false !== strpos( $sql, 'kilobyte_io_counter_write_pending' ) ? 'DO 0' : $sql
		);

		$this->assertFalse( wp_delete_attachment( $id, true ) );

		$this->assertInstanceOf( \WP_Post::class, get_post( $id ) );
		$this->assertSame( 1, $this->stats()['optimized_count'] );
	}

	public function test_deleting_while_the_scheduler_is_broken_still_stores_the_cleanup(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$broken = static function () {
			throw new \RuntimeException( 'Cron storage is unavailable.' );
		};
		add_filter( 'pre_schedule_event', $broken );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_attachment( $id, true ) );

		$this->assertFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
		remove_filter( 'pre_schedule_event', $broken );
		kilobyte_io_activate();
		$this->assertNotFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
		$this->make_cleanup_batches_due();
		$this->run_cleanup_cron();
		$this->assertFilesGone( $copies );
	}

	public function test_deleting_is_refused_when_neither_storage_nor_the_scheduler_works(): void {
		$this->skip_on_multisite();
		$id = $this->optimized_jpeg();
		$this->fail_to_store_cleanup_batches();
		add_filter(
			'pre_schedule_event',
			static function () {
				throw new \RuntimeException( 'Cron storage is unavailable.' );
			}
		);

		$this->assertFalse( wp_delete_attachment( $id, true ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $id ) );
	}

	/**
	 * Makes the database refuse new cleanup batches, as a full disk or a read-only table would.
	 *
	 * @return callable The query filter, for removing it again.
	 */
	private function fail_to_store_cleanup_batches(): callable {
		global $wpdb;
		$table  = $wpdb->options;
		$filter = static function ( $sql ) use ( $table ) {
			return is_string( $sql ) && 0 === strpos( $sql, "INSERT INTO `{$table}`" ) && false !== strpos( $sql, self::BATCH_PREFIX ) ? 'DO 0' : $sql;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	/**
	 * The one-time cleanup retry event, if one is scheduled.
	 *
	 * @return array{timestamp: int, args: array<int, mixed>}|null
	 */
	private function scheduled_retry(): ?array {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks['kilobyte_io_retry_variant_cleanup'] ?? array() as $event ) {
				return array(
					'timestamp' => (int) $timestamp,
					'args'      => $event['args'],
				);
			}
		}
		return null;
	}
}
