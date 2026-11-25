<?php
/**
 * Making optimized copies with the server's image editor, beyond the common cases.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Tests\Support\Images;

final class ServerEncodeTest extends OptimizeTestCase {

	public function test_a_still_gif_gets_a_webp_copy_for_the_original_and_its_thumbnails(): void {
		$this->use_settings( array( 'quality' => 60 ) );
		$id = $this->upload( Images::still_gif( 320, 240 ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( 'webp', $result['format'] );
		foreach ( array( 'full', 'thumbnail' ) as $size ) {
			$copies = $this->delivered_copies( $id, $size );
			$this->assertNotEmpty( $copies, "No copy is offered for the {$size} size." );
			$this->assertSame( 'image/webp', wp_get_image_mime( $this->path_of( $copies[0] ) ) );
		}
	}

	public function test_the_original_format_preference_keeps_a_gif_and_records_why(): void {
		$this->use_settings( array( 'format' => 'original' ) );
		$id = $this->upload( Images::still_gif( 320, 240 ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'original_format_kept', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
		$this->assertSame( 'The Original format preference keeps this GIF unchanged.', $this->media_library_status( $id ) );
	}

	public function test_an_unknown_format_preference_falls_back_to_webp(): void {
		$this->use_settings( array( 'format' => 'jpeg-xl' ) );
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'webp', $result['format'] );
	}

	public function test_a_png_gets_a_smaller_webp_copy(): void {
		$this->use_settings( array( 'quality' => 60 ) );
		$id = $this->upload( Images::png( 320, 240 ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'webp', $result['format'] );
		$this->assertLessThan( $result['original_size'], $result['optimized_size'] );
		$this->assertSame( 'image/webp', wp_get_image_mime( $this->path_of( $this->delivered_copies( $id )[0] ) ) );
	}

	public function test_the_copy_is_scaled_down_to_the_maximum_width(): void {
		$this->use_settings( array( 'max_width' => 200 ) );
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$size = wp_getimagesize( $this->path_of( $this->delivered_copies( $id, 'thumbnail' )[0] ) );
		$this->assertSame( array( 150, 150 ), array( $size[0], $size[1] ), 'A thumbnail smaller than the limit keeps its size.' );
		$full = $this->copies_next_to( $id );
		$main = (string) current( preg_grep( '/^photo\.kilobyte-/', $full ) );
		$size = wp_getimagesize( dirname( (string) get_attached_file( $id ) ) . '/' . $main );
		$this->assertSame( array( 200, 150 ), array( $size[0], $size[1] ) );
	}

	public function test_the_copy_is_scaled_down_to_the_maximum_height(): void {
		$this->use_settings( array( 'max_height' => 120 ) );
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$main = (string) current( preg_grep( '/^photo\.kilobyte-/', $this->copies_next_to( $id ) ) );
		$size = wp_getimagesize( dirname( (string) get_attached_file( $id ) ) . '/' . $main );
		$this->assertSame( array( 160, 120 ), array( $size[0], $size[1] ) );
	}

	public function test_the_avif_preference_falls_back_to_webp_when_the_site_does_not_allow_avif_uploads(): void {
		$this->use_settings( array( 'format' => 'avif' ) );
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'upload_mimes', static fn ( array $mimes ): array => array_diff( $mimes, array( 'image/avif' ) ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'webp', $result['format'] );
	}

	public function test_a_jpeg_is_recompressed_as_jpeg_when_the_site_allows_no_modern_format(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'upload_mimes', static fn ( array $mimes ): array => array_diff( $mimes, array( 'image/webp', 'image/avif' ) ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'jpg', $result['format'] );
		$this->assertLessThan( $result['original_size'], $result['optimized_size'] );
		$this->assertSame( 'image/jpeg', wp_get_image_mime( $this->path_of( $this->delivered_copies( $id )[0] ) ) );
	}

	public function test_a_gif_cannot_be_encoded_when_the_site_allows_no_modern_format(): void {
		$id = $this->upload( Images::still_gif( 320, 240 ) );
		add_filter( 'upload_mimes', static fn ( array $mimes ): array => array_diff( $mimes, array( 'image/webp', 'image/avif' ) ) );

		$this->assertRestFailure( 'encode_failed', 422, $this->optimize_on_server( $id ) );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_a_server_without_an_image_editor_reports_that_it_cannot_encode(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$response = $this->optimize_on_server( $id );

		$this->assertRestFailure( 'encode_failed', 422, $response );
		$this->assertSame( 'No editor could be selected.', $response->get_data()['data']['technical'] );
	}

	public function test_a_bmp_cannot_be_encoded_on_a_server_whose_editor_cannot_read_bmp(): void {
		$id = $this->upload( $this->bmp() );
		add_filter( 'wp_image_editors', static fn (): array => array( 'WP_Image_Editor_GD' ) );

		$this->assertRestFailure( 'encode_failed', 422, $this->optimize_on_server( $id ) );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_an_image_editor_filter_that_changes_the_output_format_fails_the_encode_without_leaving_a_temporary_file(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'image_editor_output_format', static fn (): array => array( 'image/webp' => 'image/gif' ) );
		$temp_files_before = glob( get_temp_dir() . 'kilobyte-image*' );

		$this->assertRestFailure( 'encode_failed', 422, $this->optimize_on_server( $id ) );

		$this->assertSame( $temp_files_before, glob( get_temp_dir() . 'kilobyte-image*' ) );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_a_failed_resize_fails_the_encode(): void {
		$this->use_settings( array( 'max_width' => 100 ) );
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'image_resize_dimensions', '__return_false' );

		$this->assertRestFailure( 'encode_failed', 422, $this->optimize_on_server( $id ) );
	}

	public function test_an_original_that_cannot_be_decoded_is_reported_as_unreadable(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->replace_original_with( $id, $this->write_fixture( 'truncated.jpg', "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 200 ) ) );

		$this->assertRestFailure( 'invalid_image', 422, $this->optimize_on_server( $id ) );
	}

	public function test_an_original_with_too_many_pixels_is_not_decoded(): void {
		$id = $this->upload( Images::png( 64, 64 ) );
		$this->replace_original_with( $id, $this->png_header_only( 8000, 6000 ) );

		$this->assertRestFailure( 'image_too_large', 422, $this->optimize_on_server( $id ) );
	}

	public function test_an_original_that_would_not_fit_in_memory_is_not_decoded(): void {
		$id = $this->upload( Images::png( 64, 64 ) );
		$this->replace_original_with( $id, $this->png_header_only( 5000, 5000 ) );
		$limit = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 64 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

		try {
			$response = $this->optimize_on_server( $id );
		} finally {
			ini_set( 'memory_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		}

		$this->assertRestFailure( 'server_image_limit', 422, $response );
	}

	public function test_an_original_over_the_size_limit_is_not_read(): void {
		$id    = $this->upload( Images::png( 64, 64 ) );
		$small = (string) file_get_contents( $this->png_header_only( 4000, 3000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( (string) get_attached_file( $id ), $small . str_repeat( "\0", 25 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertRestFailure( 'image_too_large', 422, $this->optimize_on_server( $id ) );
	}

	public function test_an_original_replaced_by_a_file_that_is_not_an_image_is_reported_as_missing(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->replace_original_with( $id, Images::not_an_image() );

		$this->assertRestFailure( 'missing_source', 404, $this->optimize_on_server( $id ) );
	}

	public function test_a_missing_original_is_reported(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		unlink( (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertRestFailure( 'missing_source', 404, $this->optimize_on_server( $id ) );
	}

	public function test_an_image_type_the_plugin_does_not_handle_is_refused(): void {
		$id = $this->upload( $this->icon() );

		$this->assertRestFailure( 'invalid_mime', 415, $this->optimize_on_server( $id ) );
	}

	public function test_an_animated_png_is_kept_and_recorded_as_animated(): void {
		$id = $this->upload( Images::animated_png() );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'animated', $result['status'] );
		$this->assertSame( 'The original was kept to preserve its animation.', $this->media_library_status( $id ) );
	}

	public function test_a_gif_whose_frame_does_not_fill_the_canvas_is_kept(): void {
		$id = $this->upload( $this->gif_with_partial_frame() );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'gif_canvas_kept', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_a_gif_with_a_text_block_is_kept(): void {
		$id = $this->upload( $this->gif_with_text_block() );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'gif_canvas_kept', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	/**
	 * GIF files whose structure the plugin cannot follow to the end.
	 *
	 * @return array<string, array{string}>
	 */
	public function incomplete_gifs(): array {
		$header = "GIF89a\x02\x00\x02\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00";
		$frame  = "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00\x02\x02\x84\x51\x00";
		return array(
			'frame cut off'           => array( $header . "\x2C\x00\x00\x00" ),
			'frame larger than image' => array( $header . "\x2C\x00\x00\x00\x00\x04\x00\x04\x00\x00\x02\x02\x84\x51\x00\x3B" ),
			'no end marker'           => array( $header . $frame ),
		);
	}

	/**
	 * @dataProvider incomplete_gifs
	 *
	 * @param string $bytes GIF file contents.
	 */
	public function test_a_gif_the_plugin_cannot_read_to_the_end_is_kept( string $bytes ): void {
		$id = $this->upload( $this->write_fixture( 'incomplete.gif', $bytes ) );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'gif_canvas_kept', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_an_image_excluded_by_a_site_rule_is_left_alone(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'kilobyte_io_should_optimize', static fn ( bool $optimize, int $attachment_id ): bool => $attachment_id !== $id, 10, 2 );

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'excluded', $result['status'] );
		$this->assertSame( array(), $this->copies_next_to( $id ) );
	}

	public function test_retrying_a_background_upload_job_shows_the_outcome_in_the_media_library(): void {
		$id = $this->upload( $this->gif_with_partial_frame() );
		$this->assertSame( 'Original saved. Optimization is queued. Optimize now', $this->media_library_status( $id ) );

		$result = $this->assertRestOk( $this->rest( 'POST', "/optimize-server/{$id}", array( 'retry_upload' => true ) ) );

		$this->assertSame( 'gif_canvas_kept', $result['status'] );
		$this->assertSame( 'The full GIF image could not be preserved in a smaller copy.', $this->media_library_status( $id ) );
	}

	public function test_a_plain_optimize_leaves_the_background_upload_job_queued_when_nothing_is_recorded(): void {
		$id = $this->upload( $this->gif_with_partial_frame() );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 'Original saved. Optimization is queued. Optimize now', $this->media_library_status( $id ) );
	}

	public function test_retrying_a_background_upload_job_waits_for_another_request_working_on_the_image(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		$this->hold_lock_in_other_request( "image:{$id}" );

		$this->assertRestFailure( 'locked', 409, $this->rest( 'POST', "/optimize-server/{$id}", array( 'retry_upload' => true ) ) );
	}

	public function test_a_lower_quality_preference_makes_a_smaller_server_copy(): void {
		$this->use_settings( array( 'quality' => 95 ) );
		$high = $this->assertRestOk( $this->optimize_on_server( $this->upload( Images::jpeg() ) ) );
		$this->use_settings( array( 'quality' => 40 ) );
		$low = $this->assertRestOk( $this->optimize_on_server( $this->upload( Images::jpeg() ) ) );

		$this->assertLessThan( $high['optimized_size'], $low['optimized_size'], 'Quality 40 must give a smaller WebP copy than quality 95.' );
	}

	public function test_a_lower_quality_preference_makes_smaller_thumbnail_copies(): void {
		$this->use_settings(
			array(
				'quality'      => 95,
				'skip_smaller' => false,
			)
		);
		$high = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $high ) );
		$this->use_settings(
			array(
				'quality'      => 40,
				'skip_smaller' => false,
			)
		);
		$low = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $low ) );

		$this->assertLessThan(
			filesize( $this->path_of( $this->delivered_copies( $high, 'thumbnail' )[0] ) ),
			filesize( $this->path_of( $this->delivered_copies( $low, 'thumbnail' )[0] ) ),
			'Quality 40 must give a smaller thumbnail copy than quality 95.'
		);
	}
}
