<?php
/**
 * Copies of the thumbnail sizes WordPress made for an image.
 *
 * Each test sends a browser copy of the full-size image. The server then makes the
 * thumbnail copies itself.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Tests\Support\Images;

final class ThumbnailCopyTest extends OptimizeTestCase {

	public function test_thumbnails_larger_than_the_maximum_size_get_scaled_copies(): void {
		$this->use_settings( array( 'max_width' => 200 ) );
		$id = $this->upload( Images::jpeg( 320, 240 ) );

		$this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );

		$medium = (string) current( preg_grep( '/^photo-300x225\.kilobyte-/', $this->copies_next_to( $id ) ) );
		$size   = wp_getimagesize( dirname( (string) get_attached_file( $id ) ) . '/' . $medium );
		$this->assertSame( array( 200, 150 ), array( $size[0], $size[1] ) );
	}

	public function test_a_thumbnail_that_cannot_be_resized_gets_no_copy(): void {
		$this->use_settings( array( 'max_width' => 200 ) );
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'image_resize_dimensions', '__return_false' );

		$this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );

		$this->assertSame( array(), preg_grep( '/^photo-300x225\.kilobyte-/', $this->copies_next_to( $id ) ) );
		$this->assertCount( 1, $this->delivered_copies( $id, 'thumbnail' ), 'The 150px thumbnail is under the limit and keeps its copy.' );
	}

	public function test_an_editor_filter_that_changes_the_thumbnail_format_leaves_no_stray_thumbnail_files(): void {
		$id     = $this->upload( Images::jpeg( 320, 240 ) );
		$copy   = $this->browser_webp_of( $id );
		$before = $this->files_next_to( $id );
		add_filter( 'image_editor_output_format', static fn (): array => array( 'image/webp' => 'image/png' ) );

		$this->assertRestOk( $this->send_browser_copy( $id, $copy ) );

		$added = array_values( array_diff( $this->files_next_to( $id ), $before ) );
		$this->assertCount( 1, $added, 'Only the full-size copy is written: ' . implode( ', ', $added ) );
		$this->assertMatchesRegularExpression( '/^photo\.kilobyte-\d+-[a-f0-9-]{36}\.webp$/', $added[0] );
	}

	public function test_thumbnail_entries_outside_the_image_folder_are_ignored(): void {
		$id                          = $this->upload( Images::jpeg( 320, 240 ) );
		$metadata                    = wp_get_attachment_metadata( $id );
		$original                    = wp_basename( (string) get_attached_file( $id ) );
		$metadata['sizes']['escape'] = array( 'file' => '../photo-150x150.jpg' );
		$metadata['sizes']['self']   = array( 'file' => $original );
		$metadata['sizes']['blank']  = array( 'file' => '' );
		wp_update_attachment_metadata( $id, $metadata );

		$this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );

		$this->assertCount( 3, $this->copies_next_to( $id ), 'The original, thumbnail and medium size each get exactly one copy.' );
	}

	public function test_thumbnail_files_that_are_no_longer_readable_images_get_no_copy(): void {
		$id     = $this->upload( Images::jpeg( 320, 240 ) );
		$folder = dirname( (string) get_attached_file( $id ) );
		$copy   = $this->browser_webp_of( $id );
		$this->replace_file( $folder . '/photo-150x150.jpg', Images::not_an_image() );
		$this->replace_file( $folder . '/photo-300x225.jpg', $this->png_header_only( 300, 225, 'corrupt.png' ) );

		$this->assertRestOk( $this->send_browser_copy( $id, $copy ) );

		$this->assertCount( 1, $this->copies_next_to( $id ), 'Only the full-size copy is written.' );
	}

	public function test_a_gif_thumbnail_whose_frame_does_not_fill_it_gets_no_copy(): void {
		$this->use_settings( array( 'skip_smaller' => false ) );
		$id     = $this->upload( Images::still_gif( 320, 240 ) );
		$folder = dirname( (string) get_attached_file( $id ) );
		$this->replace_file( $folder . '/still-150x150.gif', $this->gif_with_partial_frame() );

		$this->assertRestOk( $this->send_browser_copy( $id, Images::webp( 64, 48 ) ) );

		$this->assertSame( array(), preg_grep( '/^still-150x150\.kilobyte-/', $this->copies_next_to( $id ) ) );
		$this->assertNotEmpty( preg_grep( '/^still-300x225\.kilobyte-/', $this->copies_next_to( $id ) ) );
	}

	public function test_a_thumbnail_copy_that_is_not_smaller_is_deleted(): void {
		$this->use_settings( array( 'quality' => 95 ) );
		$id   = $this->upload( Images::jpeg( 320, 240 ) );
		$copy = Images::webp( 64, 48 );
		add_filter( 'wp_editor_set_quality', static fn (): int => 99 );
		$before = $this->files_next_to( $id );

		$this->assertRestOk( $this->send_browser_copy( $id, $copy ) );

		$added = array_values( array_diff( $this->files_next_to( $id ), $before ) );
		$this->assertCount( 1, $added, 'Only the full-size copy is kept: ' . implode( ', ', $added ) );
		$this->assertSame( array(), $this->delivered_copies( $id, 'thumbnail' ) );
	}

	/**
	 * Overwrites a file in the uploads folder with another file's contents.
	 *
	 * @param string $path   File to overwrite.
	 * @param string $source File whose contents to use.
	 * @return void
	 */
	private function replace_file( string $path, string $source ): void {
		file_put_contents( $path, file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
