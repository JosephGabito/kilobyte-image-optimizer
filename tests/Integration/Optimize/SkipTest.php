<?php
/**
 * Recording that the browser found no smaller copy, through the skip endpoint.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Tests\Support\Images;
use WP_REST_Response;

final class SkipTest extends OptimizeTestCase {

	public function test_skipping_records_the_image_as_already_optimal_and_takes_it_out_of_the_queue(): void {
		$id   = $this->upload( Images::jpeg( 320, 240 ) );
		$size = filesize( (string) get_attached_file( $id ) );
		$this->assertContains( $id, $this->optimize_queue() );

		$result = $this->assertRestOk( $this->skip( $id ) );

		$this->assertSame( 'already_optimal', $result['status'] );
		$this->assertTrue( $result['skipped'] );
		$this->assertNotContains( $id, $this->optimize_queue() );
		$this->assertSame( 'The new copy was no smaller than the original.', $this->media_library_status( $id ) );
		$stats = $this->stats();
		$this->assertSame( 1, $stats['optimized_count'] );
		$this->assertSame( $size, $stats['total_original'] );
		$this->assertSame( $size, $stats['total_optimized'] );
	}

	public function test_a_skipped_image_can_still_be_optimized_on_the_server_later(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->skip( $id ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
	}

	public function test_a_skip_for_an_older_version_of_the_original_is_refused(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertRestFailure( 'source_changed', 409, $this->skip( $id, hash( 'sha256', 'an earlier version of the photo' ) ) );
		$this->assertContains( $id, $this->optimize_queue() );
	}

	public function test_a_skip_without_a_source_fingerprint_is_refused(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertRestFailure( 'source_changed', 409, $this->rest( 'POST', "/skip/{$id}" ) );
	}

	public function test_a_skip_keeps_an_existing_optimized_copy(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$copies = $this->delivered_copies( $id );

		$result = $this->assertRestOk( $this->skip( $id ) );

		$this->assertSame( 'preserved_existing', $result['status'] );
		$this->assertSame( $copies, $this->delivered_copies( $id ) );
	}

	public function test_a_skip_after_the_original_changed_replaces_the_old_copy_and_tells_caches(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		$this->replace_original_with( $id, Images::jpeg( 300, 200 ) );
		$changed = array();
		add_action(
			'kilobyte_io_variant_changed',
			static function ( int $attachment_id ) use ( &$changed ): void {
				$changed[] = $attachment_id;
			}
		);

		$result = $this->assertRestOk( $this->skip( $id ) );

		$this->assertSame( 'already_optimal', $result['status'] );
		$this->assertSame( array( $id ), $changed );
		$this->assertSame( array(), $this->delivered_copies( $id ) );
	}

	public function test_an_image_excluded_by_a_site_rule_is_not_recorded(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'kilobyte_io_should_optimize', '__return_false' );

		$result = $this->assertRestOk( $this->skip( $id ) );

		$this->assertSame( 'excluded', $result['status'] );
		$this->assertSame( 0, $this->stats()['optimized_count'] );
	}

	public function test_a_skip_for_a_missing_original_is_refused(): void {
		$id   = $this->upload( Images::jpeg( 320, 240 ) );
		$hash = hash_file( 'sha256', (string) get_attached_file( $id ) );
		unlink( (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertRestFailure( 'missing_source', 404, $this->skip( $id, $hash ) );
	}

	public function test_a_skip_for_an_image_type_the_plugin_does_not_handle_is_refused(): void {
		$id = $this->upload( $this->icon() );

		$this->assertRestFailure( 'invalid_mime', 415, $this->skip( $id ) );
	}

	public function test_another_request_working_on_the_same_image_makes_the_skip_wait(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertRestFailure( 'locked', 409, $this->skip( $id ) );
	}

	/**
	 * Tells the plugin the browser found no smaller copy of the current original.
	 *
	 * @param int         $id          Attachment ID.
	 * @param string|null $source_hash Fingerprint of the original the browser read. Defaults to the current one.
	 * @return WP_REST_Response
	 */
	private function skip( int $id, ?string $source_hash = null ): WP_REST_Response {
		return $this->rest( 'POST', "/skip/{$id}", array( 'source_hash' => $source_hash ?? hash_file( 'sha256', (string) get_attached_file( $id ) ) ) );
	}
}
