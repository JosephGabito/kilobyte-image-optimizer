<?php
/**
 * Optimizing images that an older plugin version already handled.
 *
 * Older versions stored records without a source fingerprint, named copies differently,
 * or replaced the original in place and kept a backup. These states have no public path
 * in the current version, so each test writes the old record directly.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

final class OlderVersionRecordTest extends OptimizeTestCase {

	public function test_an_old_copy_is_kept_while_the_original_is_unchanged(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->record_old_copy( $id );

		$again = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'already_processed', $again['status'] );
		$this->assertNotContains( $id, $this->optimize_queue() );
	}

	public function test_an_old_copy_is_replaced_after_the_original_changes_but_its_file_stays_for_now(): void {
		$id  = $this->upload( Images::jpeg( 320, 240 ) );
		$old = $this->record_old_copy( $id );
		$this->replace_original_with( $id, Images::jpeg( 300, 200 ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertNotContains( wp_basename( $old ), array_map( 'wp_basename', $this->delivered_copies( $id ) ) );
		$this->assertFileExists( $old, 'Cached pages may still link to the old copy.' );
	}

	public function test_an_old_record_whose_copy_name_does_not_match_the_original_is_optimized_again(): void {
		$id       = $this->upload( Images::jpeg( 320, 240 ) );
		$relative = _wp_relative_upload_path( (string) get_attached_file( $id ) );
		$this->record_old_copy( $id, array( 'variant_path' => $relative . '.backup.webp' ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
	}

	public function test_an_old_record_in_a_format_the_plugin_no_longer_writes_is_optimized_again(): void {
		$id       = $this->upload( Images::jpeg( 320, 240 ) );
		$relative = _wp_relative_upload_path( (string) get_attached_file( $id ) );
		$this->record_old_copy(
			$id,
			array(
				'format'       => 'gif',
				'variant_path' => $relative . '.gif',
			)
		);

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( 'webp', $result['format'] );
	}

	public function test_an_image_an_older_version_optimized_in_place_is_not_optimized_again(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->record_old_copy(
			$id,
			array(
				'delivery_mode' => 'in_place',
				'variant_path'  => '',
				'backup_path'   => 'kilobyte-backups/photo.jpg',
			)
		);

		$again = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'already_processed', $again['status'] );
		$this->assertTrue( $again['has_backup'] );
	}

	public function test_short_named_copies_from_an_earlier_version_keep_being_served_and_stray_entries_are_ignored(): void {
		$id     = $this->upload( Images::jpeg( 320, 240 ) );
		$folder = dirname( _wp_relative_upload_path( (string) get_attached_file( $id ) ) );
		$base   = wp_upload_dir( null, false )['basedir'] . '/' . $folder . '/';
		copy( Images::webp( 320, 240 ), $base . 'photo-1a2b3c4d.webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		copy( Images::webp( 300, 225 ), $base . 'photo-300x225-1a2b3c4e.webp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->record_old_copy(
			$id,
			array(
				'variant_path' => "{$folder}/photo-1a2b3c4d.webp",
				'variants'     => array(
					"{$folder}/photo.jpg"         => "{$folder}/photo-1a2b3c4d.webp",
					"{$folder}/photo-300x225.jpg" => "{$folder}/photo-300x225-1a2b3c4e.webp",
					"{$folder}/photo-150x150.jpg" => 'elsewhere/photo-150x150-1a2b3c4f.webp',
				),
			)
		);

		$this->assertSame(
			array( 'photo-1a2b3c4d.webp', 'photo-300x225-1a2b3c4e.webp' ),
			array_map( 'wp_basename', $this->delivered_copies( $id ) )
		);
		$this->assertSame( array(), $this->delivered_copies( $id, 'thumbnail' ), 'A copy recorded in another folder is never served.' );
	}

	/**
	 * Stores a copy the way plugin versions before source fingerprints did: named after the
	 * original plus the new extension, with no fingerprint and no list of thumbnail copies.
	 *
	 * @param int                  $id        Attachment ID.
	 * @param array<string, mixed> $overrides Record fields that differ from that default.
	 * @return string Path of the old copy.
	 */
	private function record_old_copy( int $id, array $overrides = array() ): string {
		$original = (string) get_attached_file( $id );
		$copy     = $original . '.webp';
		copy( Images::webp( 320, 240 ), $copy ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_post_meta(
			$id,
			WpAttachmentRepository::META_KEY,
			array_merge(
				array(
					'original_size'  => (int) filesize( $original ),
					'optimized_size' => (int) filesize( $copy ),
					'format'         => 'webp',
					'original_name'  => wp_basename( $original ),
					'variant_path'   => _wp_relative_upload_path( $original ) . '.webp',
					'delivery_mode'  => 'sibling',
					'optimized_at'   => gmdate( 'Y-m-d H:i:s', time() + 1 ),
					'status'         => 'optimized',
					'engine'         => 'browser',
				),
				$overrides
			)
		);
		update_option( WpAttachmentRepository::STATS_OPTION, array( 'optimized_count' => 1 ) );
		return $copy;
	}
}
