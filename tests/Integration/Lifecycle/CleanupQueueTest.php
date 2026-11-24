<?php
/**
 * Stored cleanup work for files that no Media Library record owns any more, including jobs left by older versions.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LifecycleTestCase.php';

final class CleanupQueueTest extends LifecycleTestCase {

	private const LEGACY_EVENT = 'kilobyte_io_retry_variant_cleanup';

	/**
	 * Copies of an image that was optimized and then deleted from the Media Library by an older version,
	 * which kept the paths in a one-time cron event.
	 *
	 * @return array{copies: string[], paths: string[]}
	 */
	private function copies_of_a_deleted_image(): array {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$paths  = array_map( '_wp_relative_upload_path', $copies );
		delete_post_meta( $id, '_kilobyte_io_meta' );
		wp_delete_attachment( $id, true );
		$this->assertSame( array(), $this->cleanup_batches(), 'Without a record the deletion itself queues nothing.' );
		return array(
			'copies' => $copies,
			'paths'  => $paths,
		);
	}

	public function test_an_admin_visit_moves_an_older_versions_cleanup_event_into_storage(): void {
		$this->skip_on_multisite();
		$deleted = $this->copies_of_a_deleted_image();
		wp_schedule_single_event( time() - 5, self::LEGACY_EVENT, array( $deleted['paths'], 1 ) );

		WpVariantRetirement::resume_if_needed();

		$this->assertFalse( wp_next_scheduled( self::LEGACY_EVENT, array( $deleted['paths'], 1 ) ) );
		$this->assertNotFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
		$this->run_cleanup_cron();
		$this->assertFilesGone( $deleted['copies'] );
	}

	public function test_an_older_versions_cleanup_event_that_fires_is_stored_and_run(): void {
		$this->skip_on_multisite();
		$deleted = $this->copies_of_a_deleted_image();

		do_action( self::LEGACY_EVENT, $deleted['paths'], 2 );
		$this->assertCount( 1, $this->cleanup_batches() );
		$this->make_cleanup_batches_due();
		$this->run_cleanup_cron();

		$this->assertFilesGone( $deleted['copies'] );
	}

	public function test_an_admin_visit_ignores_a_malformed_older_event(): void {
		wp_schedule_single_event( time() - 5, self::LEGACY_EVENT, array( 'not a list', 0 ) );

		WpVariantRetirement::resume_if_needed();

		$this->assertNotFalse( wp_next_scheduled( self::LEGACY_EVENT, array( 'not a list', 0 ) ) );
		$this->assertSame( array(), $this->cleanup_batches() );
	}

	public function test_an_admin_visit_moves_at_most_five_older_events(): void {
		$deleted = $this->copies_of_a_deleted_image();
		foreach ( range( 1, 6 ) as $attempt ) {
			wp_schedule_single_event( time() - 5, self::LEGACY_EVENT, array( array( $deleted['paths'][0] ), $attempt ) );
		}

		WpVariantRetirement::resume_if_needed();

		$this->assertNotFalse( wp_next_scheduled( self::LEGACY_EVENT, array( array( $deleted['paths'][0] ), 6 ) ) );
		$this->assertFalse( wp_next_scheduled( self::LEGACY_EVENT, array( array( $deleted['paths'][0] ), 5 ) ) );
	}

	public function test_activation_moves_every_older_event(): void {
		$deleted = $this->copies_of_a_deleted_image();
		foreach ( range( 1, 6 ) as $attempt ) {
			wp_schedule_single_event( time() - 5, self::LEGACY_EVENT, array( array( $deleted['paths'][0] ), $attempt ) );
		}

		kilobyte_io_activate();

		$this->assertFalse( wp_next_scheduled( self::LEGACY_EVENT, array( array( $deleted['paths'][0] ), 6 ) ) );
	}

	public function test_deactivating_keeps_older_jobs_in_storage_and_stops_the_worker(): void {
		$deleted = $this->copies_of_a_deleted_image();
		wp_schedule_single_event( time() + 60, self::LEGACY_EVENT, array( $deleted['paths'], 0 ) );

		kilobyte_io_deactivate();

		$this->assertFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
		$this->assertFalse( wp_next_scheduled( self::LEGACY_EVENT, array( $deleted['paths'], 0 ) ) );
		$this->assertCount( 1, $this->cleanup_batches() );
	}

	public function test_deactivating_turns_an_older_job_that_cannot_be_stored_into_a_weekly_event_until_it_can(): void {
		$this->skip_on_multisite();
		$deleted = $this->copies_of_a_deleted_image();
		wp_schedule_single_event( time() + 60, self::LEGACY_EVENT, array( $deleted['paths'], 0 ) );
		$block = $this->fail_to_store_cleanup_batches();

		kilobyte_io_deactivate();

		$weekly = $this->scheduled_legacy_event();
		$this->assertNotNull( $weekly );
		$this->assertSame( 'weekly', wp_get_schedule( self::LEGACY_EVENT, $weekly['args'] ) );
		$this->assertSame( array( $deleted['paths'], 0, 0 ), array_slice( $weekly['args'], 0, 3 ) );

		do_action_ref_array( self::LEGACY_EVENT, $weekly['args'] );
		$this->assertNotNull( $this->scheduled_legacy_event(), 'The weekly event must stay while storage still fails.' );

		remove_filter( 'query', $block );
		do_action_ref_array( self::LEGACY_EVENT, $weekly['args'] );
		$this->assertNull( $this->scheduled_legacy_event() );
		$this->make_cleanup_batches_due();
		$this->run_cleanup_cron();
		$this->assertFilesGone( $deleted['copies'] );
	}

	public function test_cleanup_never_deletes_a_file_kilobyte_did_not_name(): void {
		$this->skip_on_multisite();
		$id       = $this->upload( Images::jpeg( 320, 240 ) );
		$original = (string) get_attached_file( $id );
		do_action( self::LEGACY_EVENT, array( _wp_relative_upload_path( $original ) ), 0 );
		$this->make_cleanup_batches_due();

		$this->run_cleanup_cron();

		$this->assertFileExists( $original );
		$this->assertSame( array(), $this->cleanup_batches() );
	}

	public function test_a_job_whose_files_are_already_gone_is_finished(): void {
		$this->skip_on_multisite();
		do_action( self::LEGACY_EVENT, array( '2026/11/photo.kilobyte-7-0b8f1c9e-3f4a-4c55-9a1e-2d3c4b5a6f70.webp' ), 0 );
		$this->make_cleanup_batches_due();

		$this->run_cleanup_cron();

		$this->assertSame( array(), $this->cleanup_batches() );
	}

	public function test_cleanup_never_follows_a_symbolic_link_named_like_a_copy(): void {
		$target  = Images::jpeg( 320, 240 );
		$uploads = wp_upload_dir();
		$link    = $uploads['path'] . '/photo.kilobyte-7-0b8f1c9e-3f4a-4c55-9a1e-2d3c4b5a6f70.webp';
		wp_mkdir_p( $uploads['path'] );
		symlink( $target, $link );
		do_action( self::LEGACY_EVENT, array( _wp_relative_upload_path( $link ) ), 0 );
		$this->make_cleanup_batches_due();

		$this->run_cleanup_cron();

		$this->assertFileExists( $target );
		$this->assertTrue( is_link( $link ) );
		$this->assertCount( 1, $this->cleanup_batches(), 'The job should be kept for a later retry.' );
		unlink( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_a_damaged_job_does_not_block_the_others(): void {
		$this->skip_on_multisite();
		$deleted = $this->copies_of_a_deleted_image();
		add_option( self::BATCH_PREFIX . sprintf( '%012d', time() - 100 ) . '_damaged', 'not a job', '', false );
		do_action( self::LEGACY_EVENT, $deleted['paths'], 0 );
		$this->make_cleanup_batches_due();

		$this->run_cleanup_cron();

		$this->assertFilesGone( $deleted['copies'] );
	}

	public function test_cleanup_that_loses_its_database_connection_deletes_nothing(): void {
		$deleted = $this->copies_of_a_deleted_image();
		do_action( self::LEGACY_EVENT, $deleted['paths'], 0 );
		$this->make_cleanup_batches_due();
		$this->reconnect_database();

		$this->run_cleanup_cron();

		$this->assertFilesExist( $deleted['copies'] );
		$this->assertCount( 1, $this->cleanup_batches() );
	}

	public function test_retired_copies_wait_when_the_cleanup_lost_its_database_connection(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$this->assertRestOk( $this->restore( $id ) );
		$this->make_retired_copies_due( $id );
		$this->reconnect_database();

		$this->run_cleanup_cron();

		$this->assertFilesExist( $copies );
	}

	public function test_a_cleanup_pass_that_stopped_part_way_continues_below_the_last_image_it_reached(): void {
		$this->skip_on_multisite();
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$this->assertRestOk( $this->restore( $id ) );
		$this->make_retired_copies_due( $id );
		// The previous pass stopped at this image, so this pass only looks at older ones.
		update_option( 'kilobyte_io_cleanup_before', $id, false );

		$this->run_cleanup_cron();
		$this->assertFilesExist( $copies );
		$this->run_cleanup_cron();

		$this->assertFilesGone( $copies, 'After reaching the end, the next pass starts again from the newest image.' );
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
	 * The older versions' cleanup event, if one is scheduled.
	 *
	 * @return array{timestamp: int, args: array<int, mixed>}|null
	 */
	private function scheduled_legacy_event(): ?array {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ self::LEGACY_EVENT ] ?? array() as $event ) {
				return array(
					'timestamp' => (int) $timestamp,
					'args'      => $event['args'],
				);
			}
		}
		return null;
	}
}
