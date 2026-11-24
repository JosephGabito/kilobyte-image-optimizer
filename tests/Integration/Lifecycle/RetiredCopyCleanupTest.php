<?php
/**
 * The hourly cleanup that removes copies a day after a restore made them unused.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LifecycleTestCase.php';

final class RetiredCopyCleanupTest extends LifecycleTestCase {

	/**
	 * Optimizes and restores a JPEG.
	 *
	 * @return array{id: int, copies: string[]}
	 */
	private function restored_jpeg(): array {
		$id     = $this->optimized_jpeg();
		$copies = $this->copies_on_disk( $id );
		$this->assertFalse( $this->assertRestOk( $this->restore( $id ) )['copy_retained'] );
		return array(
			'id'     => $id,
			'copies' => $copies,
		);
	}

	public function test_a_restore_schedules_the_hourly_cleanup(): void {
		$this->skip_on_multisite();
		$this->restored_jpeg();

		$this->assertNotFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
		$this->assertSame( 'hourly', wp_get_schedule( WpVariantRetirement::EVENT ) );
	}

	public function test_the_cleanup_keeps_copies_until_a_day_has_passed(): void {
		$this->skip_on_multisite();
		$image = $this->restored_jpeg();

		$this->run_cleanup_cron();

		$this->assertFilesExist( $image['copies'] );
		$this->assertNotFalse( wp_next_scheduled( WpVariantRetirement::EVENT ), 'The cleanup must stay scheduled while copies wait.' );
	}

	public function test_the_cleanup_deletes_copies_once_their_day_has_passed(): void {
		$this->skip_on_multisite();
		$image = $this->restored_jpeg();
		$this->make_retired_copies_due( $image['id'] );

		$this->run_cleanup_cron();

		$this->assertFilesGone( $image['copies'] );
		$this->assertFileExists( (string) get_attached_file( $image['id'] ), 'The original must never be deleted.' );
	}

	public function test_the_cleanup_keeps_the_copies_of_a_second_optimization(): void {
		$this->skip_on_multisite();
		$image = $this->restored_jpeg();
		$this->assertRestOk( $this->optimize_on_server( $image['id'] ) );
		$new = array_values( array_diff( $this->copies_on_disk( $image['id'] ), $image['copies'] ) );
		$this->make_retired_copies_due( $image['id'] );

		$this->run_cleanup_cron();

		$this->assertFilesGone( $image['copies'] );
		$this->assertFilesExist( $new );
		$this->assertNotEmpty( $this->delivered_copies( $image['id'] ) );
	}

	public function test_a_copy_that_another_media_library_item_uses_is_kept(): void {
		$this->skip_on_multisite();
		$image    = $this->restored_jpeg();
		$imported = $image['copies'][0];
		self::factory()->attachment->create_object(
			array(
				'file'           => _wp_relative_upload_path( $imported ),
				'post_mime_type' => 'image/webp',
			)
		);
		$this->make_retired_copies_due( $image['id'] );

		$this->run_cleanup_cron();

		$this->assertFileExists( $imported );
		$this->assertFilesGone( array_slice( $image['copies'], 1 ) );
	}

	public function test_a_copy_that_became_a_thumbnail_of_the_same_image_is_kept(): void {
		$this->skip_on_multisite();
		$image     = $this->restored_jpeg();
		$thumbnail = $image['copies'][0];
		$this->assertStringContainsString( '-150x150.', $thumbnail );
		$metadata                               = wp_get_attachment_metadata( $image['id'] );
		$metadata['sizes']['thumbnail']['file'] = wp_basename( $thumbnail );
		wp_update_attachment_metadata( $image['id'], $metadata );
		$this->make_retired_copies_due( $image['id'] );

		$this->run_cleanup_cron();

		$this->assertFileExists( $thumbnail );
		$this->assertFilesGone( array_slice( $image['copies'], 1 ) );
	}

	public function test_a_copy_that_the_wordpress_image_editor_kept_as_a_backup_size_is_kept(): void {
		$this->skip_on_multisite();
		$image  = $this->restored_jpeg();
		$backup = $image['copies'][0];
		update_post_meta( $image['id'], '_wp_attachment_backup_sizes', array( 'thumbnail-orig' => array( 'file' => wp_basename( $backup ) ) ) );
		$this->make_retired_copies_due( $image['id'] );

		$this->run_cleanup_cron();

		$this->assertFileExists( $backup );
		$this->assertFilesGone( array_slice( $image['copies'], 1 ) );
	}

	public function test_copies_are_kept_when_the_original_changed_after_the_restore(): void {
		$this->skip_on_multisite();
		$image = $this->restored_jpeg();
		copy( Images::jpeg( 310, 230 ), (string) get_attached_file( $image['id'] ) );
		$this->make_retired_copies_due( $image['id'] );

		$this->run_cleanup_cron();

		$this->assertFilesExist( $image['copies'], 'The copies may be the only version of the replaced original.' );
	}

	public function test_copies_retired_by_an_older_version_without_a_fingerprint_are_never_deleted(): void {
		$this->skip_on_multisite();
		$image   = $this->restored_jpeg();
		$uploads = wp_upload_dir( null, false );
		$legacy  = array();
		foreach ( $image['copies'] as $copy ) {
			$legacy[ substr( $copy, strlen( $uploads['basedir'] ) + 1 ) ] = time() - DAY_IN_SECONDS;
		}
		update_post_meta( $image['id'], self::RETIRED_META, $legacy );

		$this->run_cleanup_cron();
		$this->run_cleanup_cron();

		$this->assertFilesExist( $image['copies'] );
	}

	public function test_a_large_media_library_pauses_cleanup_for_a_day_and_keeps_the_copies(): void {
		$this->skip_on_multisite();
		$image = $this->restored_jpeg();
		$this->make_retired_copies_due( $image['id'] );
		$this->add_attachment_meta_rows( 10001 );

		$this->run_cleanup_cron_in_new_request();
		$this->assertNotFalse( get_transient( 'kilobyte_io_cleanup_reference_pause' ) );
		$this->delete_added_attachment_meta_rows();
		$this->run_cleanup_cron_in_new_request();

		$this->assertFilesExist( $image['copies'], 'Cleanup should wait a day before checking the library again.' );
	}

	public function test_cleanup_does_nothing_while_another_request_runs_it(): void {
		$this->skip_on_multisite();
		$image = $this->restored_jpeg();
		$this->make_retired_copies_due( $image['id'] );
		$this->hold_lock_in_other_request( 'variant_cleanup' );

		$this->run_cleanup_cron();

		$this->assertFilesExist( $image['copies'] );
	}

	public function test_cleanup_skips_an_image_another_request_is_working_on_and_retries_later(): void {
		$this->skip_on_multisite();
		$image = $this->restored_jpeg();
		$this->make_retired_copies_due( $image['id'] );
		$this->hold_lock_in_other_request( "image:{$image['id']}" );

		$this->run_cleanup_cron();

		$this->assertFilesExist( $image['copies'] );
	}

	public function test_an_admin_visit_reschedules_a_lost_cleanup_event(): void {
		$this->skip_on_multisite();
		$this->restored_jpeg();
		wp_clear_scheduled_hook( WpVariantRetirement::EVENT );

		WpVariantRetirement::resume_if_needed();

		$this->assertNotFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
	}

	public function test_admin_visits_check_for_a_lost_cleanup_event_at_most_once_a_minute(): void {
		$this->skip_on_multisite();
		$this->restored_jpeg();
		WpVariantRetirement::resume_if_needed();
		wp_clear_scheduled_hook( WpVariantRetirement::EVENT );

		WpVariantRetirement::resume_if_needed();

		$this->assertFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
	}

	public function test_activating_the_plugin_reschedules_a_lost_cleanup_event(): void {
		$this->skip_on_multisite();
		$this->restored_jpeg();
		WpVariantRetirement::resume_if_needed();
		wp_clear_scheduled_hook( WpVariantRetirement::EVENT );

		kilobyte_io_activate();

		$this->assertNotFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
	}

	public function test_no_cleanup_event_is_scheduled_when_there_is_no_work(): void {
		$this->optimized_jpeg();

		kilobyte_io_activate();

		$this->assertFalse( wp_next_scheduled( WpVariantRetirement::EVENT ) );
	}

	/**
	 * Adds attachment file rows to postmeta so the library is larger than cleanup checks in one pass.
	 *
	 * @param int $count Rows to add.
	 * @return void
	 */
	private function add_attachment_meta_rows( int $count ): void {
		global $wpdb;
		$digits = 'SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT 999999, '_wp_attached_file', CONCAT('bulk/', a.i, b.i, c.i, d.i, e.i, '.jpg') FROM ({$digits}) a, ({$digits}) b, ({$digits}) c, ({$digits}) d, ({$digits}) e LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count
			)
		);
		$this->assertSame( '', $wpdb->last_error );
	}

	/**
	 * Removes the rows added by add_attachment_meta_rows().
	 *
	 * @return void
	 */
	private function delete_added_attachment_meta_rows(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id = 999999" );
	}
}
