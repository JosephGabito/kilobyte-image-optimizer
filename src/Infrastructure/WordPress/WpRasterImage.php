<?php
/**
 * Bounds image size before asking WordPress to decode it.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

/**
 * Checks that a local file has the expected raster type and safe dimensions.
 */
final class WpRasterImage {

	public const MAX_BYTES  = 24 * MB_IN_BYTES;
	public const MAX_PIXELS = 40000000;

	/**
	 * Check file type, length and dimensions before starting an image editor.
	 *
	 * @param string $path               Local image path.
	 * @param string $mime               Expected raster MIME.
	 * @param bool   $apply_upload_limit Whether this file needs to fit the upload limit.
	 * @return bool
	 */
	public static function is_bounded( string $path, string $mime, bool $apply_upload_limit = false ): bool {
		$bytes     = wp_filesize( $path );
		$max_bytes = $apply_upload_limit ? min( self::MAX_BYTES, wp_max_upload_size() ) : self::MAX_BYTES;
		if ( $bytes <= 0 || $bytes > $max_bytes ) {
			return false;
		}

		$size = wp_getimagesize( $path );
		if ( ! is_array( $size ) || ( $size['mime'] ?? '' ) !== $mime || empty( $size[0] ) || empty( $size[1] ) || $size[0] * $size[1] > self::MAX_PIXELS ) {
			return false;
		}

		// Leave room for the decoded image, the resized copy, and the encoder.
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit <= 0 || memory_get_usage( true ) + $bytes * 2 + $size[0] * $size[1] * 8 + 2 * MB_IN_BYTES < $limit;
	}
	/**
	 * Check formats that can store a sequence of frames.
	 *
	 * @param string $path Local image path.
	 * @param string $mime Verified MIME type.
	 * @return bool
	 */
	public static function is_animated( string $path, string $mime ): bool {
		if ( ! in_array( $mime, array( 'image/png', 'image/webp', 'image/avif' ), true ) ) {
			return false;
		}

		// Called after is_bounded(). Keep animation data out of the image editor.
		$contents = WpLocalFilesystem::get()->get_contents( $path );
		if ( ! is_string( $contents ) ) {
			return true;
		}
		if ( 'image/webp' === $mime ) {
			return strlen( $contents ) > 20 && 'VP8X' === substr( $contents, 12, 4 ) && (bool) ( ord( $contents[20] ) & 0x02 );
		}
		if ( 'image/avif' === $mime ) {
			return false !== strpos( substr( $contents, 8, 120 ), 'avis' );
		}

		$length = strlen( $contents );
		for ( $offset = 8; $offset + 12 <= $length; ) {
			$type = substr( $contents, $offset + 4, 4 );
			if ( 'acTL' === $type ) {
				return true;
			}
			if ( 'IDAT' === $type || 'IEND' === $type ) {
				break;
			}
			$chunk   = unpack( 'Nlength', substr( $contents, $offset, 4 ) );
			$offset += 12 + $chunk['length'];
		}
		return false;
	}

	/**
	 * Return formats that the site allows and can edit.
	 *
	 * @return string[]
	 */
	public static function output_mimes(): array {
		$allowed = get_allowed_mime_types();
		$output  = array();
		foreach ( array( 'image/webp', 'image/avif', 'image/jpeg', 'image/png' ) as $mime ) {
			if ( in_array( $mime, $allowed, true ) && wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
				$output[] = $mime;
			}
		}
		return $output;
	}
}
