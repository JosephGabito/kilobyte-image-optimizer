<?php
/**
 * Restoring an image whose optimized copies sit next to the untouched original.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LifecycleTestCase.php';

final class RestoreCopyTest extends LifecycleTestCase {

	public function test_restoring_stops_offering_the_copy_and_keeps_its_files_for_a_day(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertSame( 'sibling', $restored['mode'] );
		$this->assertFalse( $restored['copy_retained'], 'The copies should be scheduled for removal.' );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertCount( 3, $copies, 'Expected copies of the full size, medium and thumbnail.' );
		$this->assertFilesExist( $copies, 'Cached pages may still link a copy, so it must stay for a day.' );
		$this->assertSame( 'Not optimized', $this->media_column( $id ) );
	}

	public function test_restoring_updates_the_dashboard_counters(): void {
		$id = $this->optimized_jpeg();
		$this->assertSame( 1, $this->stats()['optimized_count'] );

		$this->assertRestOk( $this->restore( $id ) );

		$stats = $this->stats();
		$this->assertSame( 0, $stats['optimized_count'] );
		$this->assertSame( 0, $stats['restorable_count'] );
	}

	public function test_an_image_can_be_optimized_again_after_a_restore(): void {
		$id  = $this->optimized_jpeg();
		$old = $this->copies_on_disk( $id );
		$this->assertRestOk( $this->restore( $id ) );

		$again = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $again['skipped'] );
		$new = array_values( array_diff( $this->copies_on_disk( $id ), $old ) );
		$this->assertCount( 3, $new, 'The second optimization should write three new copies.' );
		$this->assertContains( $this->path_of( $this->delivered_copies( $id )[0] ), $new );
	}

	public function test_restoring_an_image_with_no_copy_reports_there_is_nothing_to_restore(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertRestFailure( 'no_backup', 404, $this->restore( $id ) );
	}

	public function test_an_image_whose_copy_was_not_smaller_has_nothing_to_restore(): void {
		update_option( 'kilobyte_io_settings', array( 'quality' => 95 ) );
		add_filter( 'wp_editor_set_quality', static fn () => 100 );
		$id = $this->upload( Images::webp( 64, 64 ) );
		$this->assertSame( 'already_optimal', $this->assertRestOk( $this->optimize_on_server( $id ) )['status'] );

		$this->assertRestFailure( 'no_backup', 404, $this->restore( $id ) );
	}

	public function test_a_missing_original_keeps_the_copy_in_use(): void {
		$id       = $this->optimized_jpeg();
		$original = (string) get_attached_file( $id );
		unlink( $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertRestFailure( 'original_missing', 409, $this->restore( $id ) );

		$this->assertNotEmpty( $this->delivered_copies( $id ), 'Visitors should keep getting the copy while the original is missing.' );
		$this->assertFilesExist( $this->copies_on_disk( $id ) );
	}

	public function test_an_original_that_no_longer_opens_as_an_image_keeps_the_copy_in_use(): void {
		$id = $this->optimized_jpeg();
		copy( Images::not_an_image(), (string) get_attached_file( $id ) );

		$this->assertRestFailure( 'original_missing', 409, $this->restore( $id ) );
	}

	public function test_restore_refuses_when_another_tool_made_the_copy_the_media_library_file(): void {
		$id   = $this->optimized_jpeg();
		$copy = $this->path_of( $this->delivered_copies( $id )[0] );
		update_attached_file( $id, $copy );

		$this->assertRestFailure( 'original_missing', 409, $this->restore( $id ) );
		$this->assertFileExists( $copy );
	}

	public function test_a_replaced_original_keeps_the_copies_made_from_the_old_one(): void {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		copy( Images::jpeg( 310, 230 ), (string) get_attached_file( $id ) );

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertTrue( $restored['copy_retained'] );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->run_cleanup_cron();
		$this->assertFilesExist( $copies, 'The copies are the only remaining version of the old original.' );
	}

	public function test_an_original_replaced_by_an_animated_gif_can_still_be_restored(): void {
		$id        = $this->optimized_jpeg();
		$animation = dirname( (string) get_attached_file( $id ) ) . '/animated.gif';
		copy( Images::animated_gif(), $animation );
		update_attached_file( $id, $animation );

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertTrue( $restored['copy_retained'] );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_another_request_working_on_the_image_makes_the_restore_wait(): void {
		$id = $this->optimized_jpeg();
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertRestFailure( 'locked', 409, $this->restore( $id ) );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_a_restore_that_cannot_clear_the_record_keeps_serving_the_copy_and_its_files(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		add_filter(
			'delete_post_metadata',
			static fn ( $check, $object_id, $key ) => '_kilobyte_io_meta' === $key ? false : $check,
			10,
			3
		);

		$this->assertRestFailure( 'restore_failed', 500, $this->restore( $id ) );

		$this->assertNotEmpty( $this->delivered_copies( $id ) );
		$this->make_retired_copies_due( $id );
		$this->run_cleanup_cron();
		$this->assertFilesExist( $copies, 'Cleanup must not delete copies the record still serves.' );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_an_author_cannot_restore_someone_elses_image(): void {
		$id = $this->optimized_jpeg();
		$this->sign_in_as( 'author' );

		$this->assertSame( 403, $this->restore( $id )->get_status() );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}
	public function test_a_copy_recorded_without_a_fingerprint_of_the_original_is_kept_after_a_restore(): void {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$this->rewrite_record( $id, array( 'source_revision' => '' ) );

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertTrue( $restored['copy_retained'], 'Without a fingerprint the plugin cannot prove the copy matches the original.' );
		$this->assertFalse( metadata_exists( 'post', $id, self::RETIRED_META ) );
	}

	public function test_a_copy_with_a_short_name_from_an_older_version_is_kept_after_a_restore(): void {
		$id       = $this->optimized_jpeg();
		$original = (string) get_attached_file( $id );
		$copy     = dirname( $original ) . '/photo-1a2b3c4d.webp';
		rename( $this->copies_on_disk( $id )[2], $copy ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		$this->rewrite_record(
			$id,
			array(
				'variant_path' => _wp_relative_upload_path( $copy ),
				'variants'     => array( _wp_relative_upload_path( $original ) => _wp_relative_upload_path( $copy ) ),
			)
		);

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertTrue( $restored['copy_retained'], 'Short names may belong to another media tool.' );
		$this->assertFalse( metadata_exists( 'post', $id, self::RETIRED_META ) );
		$this->assertFileExists( $copy );
	}

	public function test_a_copy_recorded_without_a_list_of_sizes_is_kept_after_a_restore(): void {
		$id       = $this->optimized_jpeg();
		$original = (string) get_attached_file( $id );
		rename( $this->copies_on_disk( $id )[2], $original . '.webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		$this->rewrite_record(
			$id,
			array(
				'variant_path' => _wp_relative_upload_path( $original ) . '.webp',
				'variants'     => array(),
			)
		);

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertTrue( $restored['copy_retained'] );
		$this->assertFileExists( $original . '.webp' );
	}

	public function test_copies_are_kept_when_the_cleanup_list_cannot_be_saved(): void {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		add_filter(
			'update_post_metadata',
			static fn ( $check, $object_id, $key ) => self::RETIRED_META === $key ? false : $check,
			10,
			3
		);

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertTrue( $restored['copy_retained'] );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertFilesExist( $copies );
	}

	public function test_a_broken_scheduler_does_not_lose_the_cleanup_and_activation_schedules_it_later(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$broken = static function () {
			throw new \RuntimeException( 'Cron storage is unavailable.' );
		};
		add_filter( 'pre_schedule_event', $broken );

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertFalse( $restored['copy_retained'], 'The copies are recorded for cleanup even though no event could be scheduled.' );
		$this->assertFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
		WpVariantRetirement::resume_if_needed();
		$this->assertFalse( wp_next_scheduled( WpVariantRetirement::EVENT ), 'An admin visit while cron is broken must not fail.' );
		remove_filter( 'pre_schedule_event', $broken );
		kilobyte_io_activate();
		$this->assertNotFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
		$this->make_retired_copies_due( $id );
		$this->run_cleanup_cron();
		$this->assertFilesGone( $copies );
	}

	public function test_restoring_again_after_the_original_was_replaced_stops_the_cleanup_of_copies_the_record_still_named(): void {
		$this->skip_on_multisite();
		$id       = $this->optimized_jpeg();
		$copies   = $this->copies_on_disk( $id );
		$original = (string) get_attached_file( $id );
		$old      = (string) file_get_contents( $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->fail_to_clear_the_record_once( $id );
		copy( Images::jpeg( 310, 230 ), $original );

		$this->assertTrue( $this->assertRestOk( $this->restore( $id ) )['copy_retained'] );

		// Even if the old original comes back, the copies are no longer on the cleanup list.
		file_put_contents( $original, $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->run_cleanup_cron();
		$this->assertFilesExist( $copies );
		$this->assertFalse( metadata_exists( 'post', $id, self::RETIRED_META ) );
	}

	public function test_a_restore_fails_when_the_cleanup_list_cannot_be_updated_for_a_replaced_original(): void {
		$this->skip_on_multisite();
		$id = $this->optimized_jpeg();
		$this->fail_to_clear_the_record_once( $id );
		copy( Images::jpeg( 310, 230 ), (string) get_attached_file( $id ) );
		$stuck = static fn ( $check, $object_id, $key ) => self::RETIRED_META === $key ? false : $check;
		add_filter( 'delete_post_metadata', $stuck, 10, 3 );

		$this->assertRestFailure( 'restore_failed', 500, $this->restore( $id ) );

		remove_filter( 'delete_post_metadata', $stuck, 10 );
		$this->assertTrue( $this->assertRestOk( $this->restore( $id ) )['copy_retained'], 'The record should have been kept for a retry.' );
	}

	public function test_restoring_cancels_a_queued_background_optimization(): void {
		$id = $this->optimized_jpeg();
		$this->make_upload_job_due( $id );

		$this->assertRestOk( $this->restore( $id ) );
		do_action( 'kilobyte_io_optimize_pending_uploads' );

		$this->assertSame( array(), $this->delivered_copies( $id ), 'The background job must not optimize a restored image again.' );
	}

	public function test_a_hook_that_throws_while_saving_the_cleanup_list_keeps_the_copies(): void {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $key ) {
				if ( self::RETIRED_META === $key ) {
					throw new \RuntimeException( 'A third-party hook failed.' );
				}
				return $check;
			},
			10,
			3
		);

		$restored = $this->assertRestOk( $this->restore( $id ) );

		$this->assertTrue( $restored['copy_retained'] );
		$this->assertFilesExist( $copies );
	}

	/**
	 * Runs one restore that retires the copies but cannot clear the record, leaving both in place.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function fail_to_clear_the_record_once( int $id ): void {
		$keep = static fn ( $check, $object_id, $key ) => '_kilobyte_io_meta' === $key ? false : $check;
		add_filter( 'delete_post_metadata', $keep, 10, 3 );
		$this->assertRestFailure( 'restore_failed', 500, $this->restore( $id ) );
		remove_filter( 'delete_post_metadata', $keep, 10 );
		$this->assertTrue( metadata_exists( 'post', $id, self::RETIRED_META ) );
	}

	/**
	 * Rewrites fields of the stored record the way an older plugin version stored them.
	 *
	 * @param int                  $id      Attachment ID.
	 * @param array<string, mixed> $changes Record fields to replace.
	 * @return void
	 */
	private function rewrite_record( int $id, array $changes ): void {
		$record = get_post_meta( $id, '_kilobyte_io_meta', true );
		$this->assertIsArray( $record );
		update_post_meta( $id, '_kilobyte_io_meta', array_merge( $record, $changes ) );
	}
}
