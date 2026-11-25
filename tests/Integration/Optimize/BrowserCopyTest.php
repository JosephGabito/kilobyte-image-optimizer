<?php
/**
 * Saving a copy that the site admin's browser made.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Tests\Support\Images;

final class BrowserCopyTest extends OptimizeTestCase {

	public function test_the_browser_copy_is_saved_unchanged_and_served_to_visitors(): void {
		$id   = $this->upload( Images::jpeg( 320, 240 ) );
		$copy = $this->browser_webp_of( $id );
		$hash = hash_file( 'sha256', $copy );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, $copy ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( 'browser', $result['engine'] );
		$this->assertSame( 'webp', $result['format'] );
		$this->assertSame( filesize( $copy ), $result['optimized_size'] );
		$served = array_map( fn ( string $url ): string => hash_file( 'sha256', $this->path_of( $url ) ), $this->delivered_copies( $id ) );
		$this->assertContains( $hash, $served, 'Visitors must get the exact file the browser made.' );
		$this->assertCount( 3, $this->copies_next_to( $id ), 'The full size and both thumbnails get a copy.' );
	}

	public function test_a_copy_made_from_an_older_version_of_the_original_is_refused(): void {
		$id   = $this->upload( Images::jpeg( 320, 240 ) );
		$copy = $this->browser_webp_of( $id );

		$response = $this->send_browser_copy( $id, $copy, hash( 'sha256', 'an earlier version of the photo' ) );

		$this->assertRestFailure( 'source_changed', 409, $response );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_a_copy_without_a_well_formed_source_fingerprint_is_refused(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertRestFailure( 'source_changed', 409, $this->send_browser_copy( $id, $this->browser_webp_of( $id ), 'not-a-sha256' ) );
	}

	public function test_a_bmp_original_gets_the_browser_copy(): void {
		$id   = $this->upload( $this->bmp() );
		$copy = Images::webp( 64, 48 );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, $copy ) );

		$this->assertSame( 'webp', $result['format'] );
		$this->assertSame( 'image/webp', wp_get_image_mime( $this->path_of( $this->delivered_copies( $id )[0] ) ) );
	}

	public function test_a_png_original_gets_the_browser_copy(): void {
		$id = $this->upload( Images::png() );
		add_filter( 'wp_editor_set_quality', static fn (): int => 60 );
		$copy = $this->browser_webp_of( $id );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, $copy ) );

		$this->assertSame( 'webp', $result['format'] );
		$this->assertSame( hash_file( 'sha256', $copy ), hash_file( 'sha256', $this->path_of( $this->delivered_copies( $id )[0] ) ) );
	}

	public function test_a_gif_kept_by_the_original_format_preference_still_accepts_a_preview_copy(): void {
		$this->use_settings( array( 'format' => 'original' ) );
		$id = $this->upload( Images::still_gif( 320, 240 ) );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, Images::webp( 64, 48 ), null, array( 'preview' => '1' ) ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( 'webp', $result['format'] );
	}

	public function test_a_gif_kept_by_the_original_format_preference_ignores_a_normal_browser_copy(): void {
		$this->use_settings( array( 'format' => 'original' ) );
		$id = $this->upload( Images::still_gif( 320, 240 ) );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, Images::webp( 320, 240 ) ) );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'original_format_kept', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_a_gif_only_accepts_a_webp_or_avif_copy(): void {
		$id = $this->upload( Images::still_gif( 320, 240 ) );

		$this->assertRestFailure( 'encode_failed', 422, $this->send_browser_copy( $id, Images::png( 64, 64 ) ) );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_an_animated_original_is_kept_even_when_the_browser_sends_a_copy(): void {
		$id = $this->upload( Images::animated_gif() );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, Images::webp( 2, 2 ) ) );

		$this->assertSame( 'animated', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_a_browser_copy_that_is_not_smaller_is_recorded_as_already_optimal(): void {
		$id = $this->upload( Images::jpeg( 64, 64 ) );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, Images::png() ) );

		$this->assertSame( 'already_optimal', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
		$this->assertSame( 'The new copy was no smaller than the original.', $this->media_library_status( $id ) );
	}

	public function test_a_second_browser_copy_for_an_optimized_image_is_ignored(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );
		$copies = $this->copies_next_to( $id );

		$again = $this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );

		$this->assertSame( 'already_processed', $again['status'] );
		$this->assertSame( $copies, $this->copies_next_to( $id ) );
	}

	public function test_an_image_excluded_by_a_site_rule_ignores_the_browser_copy(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'kilobyte_io_should_optimize', '__return_false' );

		$result = $this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );

		$this->assertSame( 'excluded', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_a_browser_copy_for_a_missing_original_is_refused(): void {
		$id   = $this->upload( Images::jpeg( 320, 240 ) );
		$copy = $this->browser_webp_of( $id );
		$hash = hash_file( 'sha256', (string) get_attached_file( $id ) );
		unlink( (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertRestFailure( 'missing_source', 404, $this->send_browser_copy( $id, $copy, $hash ) );
	}

	public function test_a_browser_copy_for_an_image_type_the_plugin_does_not_handle_is_refused(): void {
		$id = $this->upload( $this->icon() );

		$this->assertRestFailure( 'invalid_mime', 415, $this->send_browser_copy( $id, Images::webp( 16, 16 ) ) );
	}

	public function test_another_request_working_on_the_same_image_makes_the_browser_copy_wait(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertRestFailure( 'locked', 409, $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );
	}
}
