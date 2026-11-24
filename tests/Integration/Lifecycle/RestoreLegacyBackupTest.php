<?php
/**
 * Restoring an image that an older plugin version compressed in place, keeping the original in a backup folder.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Lifecycle;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LifecycleTestCase.php';

final class RestoreLegacyBackupTest extends LifecycleTestCase {

	/**
	 * Sets up what an older version left behind: the Media Library file overwritten with a smaller JPEG,
	 * the original kept under uploads/kilobyte-backups, and a record pointing at the backup.
	 *
	 * @param string   $backup_source Image to keep as the backed-up original.
	 * @param string   $backup_name   File name inside the backup folder.
	 * @param int|null $backup_bytes  Size stored in the record. Defaults to the backup's real size.
	 * @param int|null $original_size Original size stored in the record. Defaults to the backup's real size.
	 * @return array{id: int, backup: string, current: string}
	 */
	private function image_compressed_in_place( string $backup_source, string $backup_name = 'photo.jpg', ?int $backup_bytes = null, ?int $original_size = null ): array {
		$id      = $this->upload( Images::jpeg( 320, 240 ) );
		$current = (string) get_attached_file( $id );
		$editor  = wp_get_image_editor( $current );
		$this->assertNotWPError( $editor );
		$editor->set_quality( 40 );
		$this->assertNotWPError( $editor->save( $current, 'image/jpeg' ) );

		$uploads  = wp_upload_dir( null, false );
		$relative = 'kilobyte-backups/' . dirname( _wp_relative_upload_path( $current ) ) . '/' . $backup_name;
		$backup   = $uploads['basedir'] . '/' . $relative;
		wp_mkdir_p( dirname( $backup ) );
		copy( $backup_source, $backup );

		update_post_meta(
			$id,
			'_kilobyte_io_meta',
			array(
				'original_size'  => $original_size ?? filesize( $backup ),
				'optimized_size' => filesize( $current ),
				'format'         => 'jpg',
				'original_name'  => $backup_name,
				'backup_path'    => $relative,
				'backup_bytes'   => $backup_bytes ?? filesize( $backup ),
				'delivery_mode'  => 'in_place',
				'optimized_at'   => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'status'         => 'optimized',
				'engine'         => 'browser',
			)
		);
		return array(
			'id'      => $id,
			'backup'  => $backup,
			'current' => $current,
		);
	}

	public function test_the_backed_up_original_becomes_the_media_library_file_with_fresh_thumbnails(): void {
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );

		$restored = $this->assertRestOk( $this->restore( $image['id'] ) );

		$this->assertSame( 'in_place', $restored['mode'] );
		$this->assertTrue( $restored['thumbnails_ready'] );
		$this->assertFalse( $restored['backup_removed'] );
		$file = (string) get_attached_file( $image['id'] );
		$this->assertMatchesRegularExpression( '~/kilobyte-restored-' . $image['id'] . '-[a-f0-9-]{36}\.jpg$~', $file );
		$this->assertSame( hash_file( 'sha256', $image['backup'] ), hash_file( 'sha256', $file ) );
		$this->assertFileExists( $image['backup'], 'The legacy backup must be kept.' );
		$this->assertFileExists( $image['current'], 'Pages that link the old file must keep working.' );
		$metadata = wp_get_attachment_metadata( $image['id'] );
		$this->assertSame( array( 320, 240 ), array( $metadata['width'], $metadata['height'] ) );
		foreach ( array( 'thumbnail', 'medium' ) as $size ) {
			$this->assertFileExists( dirname( $file ) . '/' . $metadata['sizes'][ $size ]['file'], "The {$size} thumbnail was not written." );
		}
	}

	public function test_a_restored_image_has_nothing_left_to_restore(): void {
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->restore( $image['id'] ) );

		$this->assertRestFailure( 'no_backup', 404, $this->restore( $image['id'] ) );
		$this->assertSame( 'Not optimized', $this->media_column( $image['id'] ) );
	}

	public function test_a_png_backup_makes_the_attachment_a_png_again(): void {
		$image = $this->image_compressed_in_place( Images::png(), 'graphic.png' );

		$this->assertRestOk( $this->restore( $image['id'] ) );

		$this->assertSame( 'image/png', get_post_mime_type( $image['id'] ) );
		$this->assertStringEndsWith( '.png', (string) get_attached_file( $image['id'] ) );
	}

	public function test_the_original_comes_back_into_uploads_when_the_current_file_is_outside_uploads(): void {
		$image   = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );
		$outside = Images::jpeg( 320, 240 );
		update_attached_file( $image['id'], $outside );

		$this->assertRestOk( $this->restore( $image['id'] ) );

		$file = (string) get_attached_file( $image['id'] );
		$this->assertSame( hash_file( 'sha256', $image['backup'] ), hash_file( 'sha256', $file ) );
		$this->assertSame( realpath( wp_upload_dir()['path'] ), dirname( $file ) );
		$this->assertFileExists( $outside );
	}

	public function test_a_missing_backup_file_is_reported(): void {
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );
		unlink( $image['backup'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertRestFailure( 'missing_backup', 404, $this->restore( $image['id'] ) );
		$this->assertSame( $image['current'], get_attached_file( $image['id'] ) );
	}

	public function test_a_backup_of_a_different_size_than_recorded_is_not_restored(): void {
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ), 'photo.jpg', 1234 );

		$this->assertRestFailure( 'backup_unverified', 422, $this->restore( $image['id'] ) );
		$this->assertSame( $image['current'], get_attached_file( $image['id'] ) );
	}

	public function test_a_backup_that_is_not_an_image_is_not_restored(): void {
		$image = $this->image_compressed_in_place( Images::not_an_image() );

		$this->assertRestFailure( 'backup_unverified', 422, $this->restore( $image['id'] ) );
		$this->assertSame( $image['current'], get_attached_file( $image['id'] ) );
	}

	public function test_without_an_image_editor_the_original_still_comes_back_but_thumbnails_are_not_ready(): void {
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$restored = $this->assertRestOk( $this->restore( $image['id'] ) );

		$this->assertFalse( $restored['thumbnails_ready'] );
		$this->assertSame( hash_file( 'sha256', $image['backup'] ), hash_file( 'sha256', (string) get_attached_file( $image['id'] ) ) );
		$this->assertSame( array(), wp_get_attachment_metadata( $image['id'] )['sizes'] );
	}

	public function test_without_an_image_editor_a_backup_with_no_recorded_size_cannot_be_verified(): void {
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ), 'photo.jpg', 0, 0 );
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$this->assertRestFailure( 'backup_unverified', 422, $this->restore( $image['id'] ) );

		$this->assertSame( $image['current'], get_attached_file( $image['id'] ) );
		$this->assertSame( array(), glob( dirname( $image['current'] ) . '/kilobyte-restored-*' ), 'The unverified copy must be removed.' );
	}

	public function test_a_restore_that_cannot_save_the_file_path_keeps_the_current_image_and_cleans_up_its_thumbnails(): void {
		$this->skip_on_multisite();
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );
		add_filter(
			'update_post_metadata',
			static fn ( $check, $object_id, $key ) => '_wp_attached_file' === $key ? false : $check,
			10,
			3
		);

		$this->assertRestFailure( 'restore_failed', 500, $this->restore( $image['id'] ) );

		$this->assertSame( $image['current'], get_attached_file( $image['id'] ) );
		$thumbnails = $this->copies_on_disk( $image['id'], 'jpg' );
		$this->assertCount( 2, $thumbnails, 'Expected the new medium and thumbnail files.' );
		$this->assertSame( 1, $this->make_cleanup_batches_due() );
		$this->run_cleanup_cron();
		$this->assertFilesGone( $thumbnails );
	}

	public function test_a_restore_that_cannot_save_the_metadata_puts_the_previous_file_back(): void {
		$image = $this->image_compressed_in_place( Images::png(), 'graphic.png' );
		add_filter(
			'update_post_metadata',
			static fn ( $check, $object_id, $key ) => '_wp_attachment_metadata' === $key ? false : $check,
			10,
			3
		);

		$this->assertRestFailure( 'restore_failed', 500, $this->restore( $image['id'] ) );

		$this->assertSame( $image['current'], get_attached_file( $image['id'] ) );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $image['id'] ) );
	}

	public function test_retrying_an_unfinished_restore_finishes_it_without_another_copy(): void {
		$image  = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );
		$record = static fn ( $check, $object_id, $key ) => '_kilobyte_io_meta' === $key ? false : $check;
		add_filter( 'delete_post_metadata', $record, 10, 3 );
		$this->assertRestFailure( 'restore_unfinished', 500, $this->restore( $image['id'] ) );
		$file = (string) get_attached_file( $image['id'] );
		$this->assertSame( hash_file( 'sha256', $image['backup'] ), hash_file( 'sha256', $file ), 'The original should already be back.' );
		remove_filter( 'delete_post_metadata', $record, 10 );

		$retried = $this->assertRestOk( $this->restore( $image['id'] ) );

		$this->assertTrue( $retried['thumbnails_ready'] );
		$this->assertSame( $file, get_attached_file( $image['id'] ) );
		$this->assertCount( 1, glob( dirname( $file ) . '/kilobyte-restored-*' ) );
		$this->assertRestFailure( 'no_backup', 404, $this->restore( $image['id'] ) );
	}

	public function test_another_request_using_the_backup_folder_makes_the_restore_wait(): void {
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );
		$this->hold_lock_in_other_request( 'backups' );

		$this->assertRestFailure( 'locked', 409, $this->restore( $image['id'] ) );
		$this->assertSame( $image['current'], get_attached_file( $image['id'] ) );
	}

	/**
	 * Known bug: only the new thumbnails are queued for cleanup. The full-size copy of the
	 * backup stays, and its name is not one the cleanup recognises.
	 *
	 * @group known-bug
	 */
	public function test_a_restore_that_cannot_save_the_file_path_leaves_no_copy_of_the_backup_behind(): void {
		$this->skip_on_multisite();
		$image = $this->image_compressed_in_place( Images::jpeg( 320, 240 ) );
		add_filter(
			'update_post_metadata',
			static fn ( $check, $object_id, $key ) => '_wp_attached_file' === $key ? false : $check,
			10,
			3
		);

		$this->assertRestFailure( 'restore_failed', 500, $this->restore( $image['id'] ) );
		$this->make_cleanup_batches_due();
		$this->run_cleanup_cron();

		$this->assertSame( array(), glob( dirname( $image['current'] ) . '/kilobyte-restored-*' ) );
	}
}
