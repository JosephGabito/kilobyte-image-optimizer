<?php
/**
 * What happens when a finished copy cannot be saved or the original changes during the save.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Tests\Support\Images;

final class SaveFailureTest extends OptimizeTestCase {

	public function test_a_server_copy_that_cannot_be_written_to_uploads_fails_and_leaves_no_files(): void {
		$id     = $this->upload( Images::jpeg( 320, 240 ) );
		$before = $this->files_next_to( $id );
		$this->truncate_writes_to_uploads();

		$this->assertRestFailure( 'write_failed', 500, $this->optimize_on_server( $id ) );

		$this->assertSame( $before, $this->files_next_to( $id ) );
		$this->assertContains( $id, $this->optimize_queue() );
	}

	public function test_a_browser_copy_that_cannot_be_written_to_uploads_fails_and_leaves_no_files(): void {
		$id     = $this->upload( Images::jpeg( 320, 240 ) );
		$copy   = $this->browser_webp_of( $id );
		$before = $this->files_next_to( $id );
		$this->truncate_writes_to_uploads();

		$this->assertRestFailure( 'write_failed', 500, $this->send_browser_copy( $id, $copy ) );

		$this->assertSame( $before, $this->files_next_to( $id ) );
	}

	public function test_copies_whose_record_cannot_be_saved_are_not_served_and_are_cleaned_up_later(): void {
		$this->skip_on_multisite();
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->fail_record_writes();

		$this->assertRestFailure( 'write_failed', 500, $this->optimize_on_server( $id ) );

		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertNotEmpty( $this->copies_next_to( $id ), 'The written copies wait for the scheduled cleanup.' );
		$this->assertSame( 0, $this->stats()['optimized_count'] );
		$this->run_due_cleanup();
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_an_already_optimal_result_whose_record_cannot_be_saved_fails(): void {
		$id = $this->upload( Images::jpeg( 64, 64 ) );
		$this->fail_record_writes();

		$this->assertRestFailure( 'write_failed', 500, $this->send_browser_copy( $id, Images::png() ) );
		$this->assertContains( $id, $this->optimize_queue() );
	}

	public function test_a_skip_whose_record_cannot_be_saved_fails(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->fail_record_writes();

		$response = $this->rest( 'POST', "/skip/{$id}", array( 'source_hash' => hash_file( 'sha256', (string) get_attached_file( $id ) ) ) );

		$this->assertRestFailure( 'write_failed', 500, $response );
	}

	public function test_an_original_replaced_while_its_copy_is_written_discards_the_copy(): void {
		$this->skip_on_multisite();
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->replace_original_when_the_copy_is_named( $id );

		$this->assertRestFailure( 'source_changed', 409, $this->optimize_on_server( $id ) );

		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertContains( $id, $this->optimize_queue() );
		$this->run_due_cleanup();
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_an_original_replaced_while_the_browser_copy_is_saved_discards_the_copy(): void {
		$id   = $this->upload( Images::jpeg( 320, 240 ) );
		$copy = $this->browser_webp_of( $id );
		$this->replace_original_when_the_copy_is_named( $id );

		$this->assertRestFailure( 'source_changed', 409, $this->send_browser_copy( $id, $copy ) );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_an_original_replaced_during_the_encode_is_not_recorded_as_already_optimal(): void {
		$this->use_settings( array( 'quality' => 95 ) );
		$id       = $this->upload( Images::webp( 64, 64 ) );
		$original = (string) get_attached_file( $id );
		$edited   = Images::webp( 48, 48 );
		add_filter(
			'wp_editor_set_quality',
			function () use ( $id, $edited ): int {
				$this->replace_original_with( $id, $edited );
				return 100;
			}
		);

		$this->assertRestFailure( 'source_changed', 409, $this->optimize_on_server( $id ) );

		$this->assertSame( hash_file( 'sha256', $edited ), hash_file( 'sha256', $original ), 'The test must have replaced the original during the encode.' );
		$this->assertContains( $id, $this->optimize_queue() );
	}

	/**
	 * Makes every file staged in the uploads folder come out empty, as on a full disk.
	 *
	 * @return void
	 */
	private function truncate_writes_to_uploads(): void {
		$uploads = wp_upload_dir( null, false )['basedir'];
		add_filter(
			'wp_filesize',
			static fn ( int $size, string $path ): int => 0 === strpos( $path, $uploads ) && '.tmp' === substr( $path, -4 ) ? 0 : $size,
			10,
			2
		);
	}

	/**
	 * Makes the database refuse to store the plugin's record for an image.
	 *
	 * @return void
	 */
	private function fail_record_writes(): void {
		add_filter(
			'update_post_metadata',
			static fn ( $check, int $object_id, string $key ) => WpAttachmentRepository::META_KEY === $key ? false : $check,
			10,
			3
		);
	}

	/**
	 * Replaces the original with a different photo when the plugin names the full-size copy,
	 * as another media tool working at the same moment would.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function replace_original_when_the_copy_is_named( int $id ): void {
		$edited = Images::jpeg( 600, 450 );
		add_filter(
			'kilobyte_io_variant_filename',
			function ( string $stem, array $context ) use ( $id, $edited ): string {
				if ( ! $context['is_thumbnail'] ) {
					$this->replace_original_with( $id, $edited );
				}
				return $stem;
			},
			10,
			2
		);
	}
}
