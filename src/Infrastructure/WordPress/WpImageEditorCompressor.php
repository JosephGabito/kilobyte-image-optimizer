<?php
/**
 * WordPress image editor adapter for server-side compression.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

use Kilobyte\ImageOptimizer\Domain\Repository\ServerCompressorInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\ImageFormat;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;

/**
 * Encodes a bounded, static raster using the WordPress image editor.
 */
final class WpImageEditorCompressor implements ServerCompressorInterface {

	/**
	 * Compress a source file using a format allowed by the site.
	 *
	 * @param string               $source_file Local source path.
	 * @param string               $source_mime Source MIME.
	 * @param OptimizationSettings $settings    Active settings.
	 * @return array{tmp_path: string, mime: string, size: int}|array{error: string}
	 */
	public function compress_to_temp( string $source_file, string $source_mime, OptimizationSettings $settings ): array {
		if ( ! WpRasterImage::is_bounded( $source_file, $source_mime ) ) {
			return array( 'error' => __( 'The image is unreadable or exceeds the server image size limit.', 'kilobyte-image-optimizer' ) );
		}

		if ( WpRasterImage::is_animated( $source_file, $source_mime ) ) {
			return array( 'error' => __( 'This image contains animation that the server cannot preserve.', 'kilobyte-image-optimizer' ) );
		}

		$editor = wp_get_image_editor( $source_file );
		if ( is_wp_error( $editor ) ) {
			return array( 'error' => $editor->get_error_message() );
		}

		$mime = $this->target_mime( $source_mime, $settings, $editor );
		if ( null === $mime ) {
			return array( 'error' => __( 'The server cannot encode an allowed version of this image.', 'kilobyte-image-optimizer' ) );
		}

		$rotation = $editor->maybe_exif_rotate();
		if ( is_wp_error( $rotation ) ) {
			return array( 'error' => $rotation->get_error_message() );
		}

		$size  = $editor->get_size();
		$max_w = $settings->max_width();
		$max_h = $settings->max_height();
		if ( is_array( $size ) && ( ( $max_w > 0 && $size['width'] > $max_w ) || ( $max_h > 0 && $size['height'] > $max_h ) ) ) {
			$resize = $editor->resize( $max_w > 0 ? $max_w : null, $max_h > 0 ? $max_h : null, false );
			if ( is_wp_error( $resize ) ) {
				return array( 'error' => $resize->get_error_message() );
			}
		}

		$editor->set_quality( $settings->quality() );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp_file = wp_tempnam( 'kilobyte-image' );
		if ( ! is_string( $tmp_file ) || '' === $tmp_file ) {
			return array( 'error' => __( 'The server could not prepare the optimized image.', 'kilobyte-image-optimizer' ) );
		}

		$saved = $editor->save( $tmp_file, $mime );
		$path  = is_array( $saved ) ? (string) ( $saved['path'] ?? '' ) : '';
		if ( $tmp_file !== $path ) {
			wp_delete_file( $tmp_file );
		}

		// An image editor filter can change the extension of the temporary file.
		$actual_mime = is_array( $saved ) ? (string) ( $saved['mime-type'] ?? '' ) : '';
		if ( '' === $path || dirname( $path ) !== dirname( $tmp_file )
			|| pathinfo( $path, PATHINFO_FILENAME ) !== pathinfo( $tmp_file, PATHINFO_FILENAME )
			|| ! ImageFormat::is_allowed_output_mime( $actual_mime ) || ! in_array( $actual_mime, get_allowed_mime_types(), true )
			|| ! WpRasterImage::is_bounded( $path, $actual_mime )
		) {
			if ( '' !== $path && dirname( $path ) === dirname( $tmp_file ) && pathinfo( $path, PATHINFO_FILENAME ) === pathinfo( $tmp_file, PATHINFO_FILENAME ) ) {
				wp_delete_file( $path );
			}
			return array( 'error' => is_wp_error( $saved ) ? $saved->get_error_message() : __( 'The server could not save a valid optimized image.', 'kilobyte-image-optimizer' ) );
		}

		return array(
			'tmp_path' => $path,
			'mime'     => $actual_mime,
			'size'     => (int) wp_filesize( $path ),
		);
	}

	/**
	 * Remove a temporary file created by this encoder.
	 *
	 * @param string $path Temporary file.
	 * @return void
	 */
	public function release_temp( string $path ): void {
		wp_delete_file( $path );
	}

	/**
	 * Select the first format that both the site and its editor support.
	 *
	 * @param string               $source   Input MIME.
	 * @param OptimizationSettings $settings Active settings.
	 * @param \WP_Image_Editor      $editor   WordPress image editor.
	 * @return string|null
	 */
	private function target_mime( string $source, OptimizationSettings $settings, \WP_Image_Editor $editor ): ?string {
		$formats = array( $source );
		$format  = $settings->format()->value();
		if ( ImageFormat::AVIF === $format ) {
			$formats = array( 'image/avif', 'image/webp', $source );
		} elseif ( ImageFormat::WEBP === $format ) {
			$formats = array( 'image/webp', $source );
		}
		$allowed = get_allowed_mime_types();
		foreach ( $formats as $mime ) {
			if ( ImageFormat::is_allowed_output_mime( $mime ) && in_array( $mime, $allowed, true ) && $editor->supports_mime_type( $mime ) ) {
				return $mime;
			}
		}
		return null;
	}
}
