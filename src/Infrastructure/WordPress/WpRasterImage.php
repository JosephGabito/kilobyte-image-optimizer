<?php
/**
 * Bounds image size before asking WordPress to decode it.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

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
		return null === self::limit_failure( $path, $mime, $apply_upload_limit );
	}

	/**
	 * Describe why an image cannot be decoded within this request's limits.
	 *
	 * @param string $path               Local image path.
	 * @param string $mime               Expected raster MIME.
	 * @param bool   $apply_upload_limit Whether this file needs to fit the upload limit.
	 * @return string|null
	 */
	public static function limit_failure( string $path, string $mime, bool $apply_upload_limit = false ): ?string {
		$bytes = wp_filesize( $path );
		if ( $bytes <= 0 ) {
			return OperationFailure::INVALID_IMAGE;
		}
		if ( $apply_upload_limit && $bytes > wp_max_upload_size() ) {
			return OperationFailure::UPLOAD_TOO_LARGE;
		}
		if ( $bytes > self::MAX_BYTES ) {
			return OperationFailure::IMAGE_TOO_LARGE;
		}

		$size = wp_getimagesize( $path );
		if ( ! is_array( $size ) || ( $size['mime'] ?? '' ) !== $mime || empty( $size[0] ) || empty( $size[1] ) ) {
			return OperationFailure::INVALID_IMAGE;
		}
		if ( $size[0] * $size[1] > self::MAX_PIXELS ) {
			return OperationFailure::IMAGE_TOO_LARGE;
		}

		// Leave room for the decoded image, the resized copy, and the encoder.
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit > 0 && memory_get_usage( true ) + $bytes * 2 + $size[0] * $size[1] * 8 + 2 * MB_IN_BYTES >= $limit ) {
			return OperationFailure::SERVER_IMAGE_LIMIT;
		}
		return null;
	}
	/**
	 * Check formats that can store a sequence of frames.
	 *
	 * @param string $path Local image path.
	 * @param string $mime Verified MIME type.
	 * @return bool
	 */
	public static function is_animated( string $path, string $mime ): bool {
		if ( ! in_array( $mime, array( 'image/png', 'image/webp', 'image/avif', 'image/gif' ), true ) ) {
			return false;
		}

		$contents = self::animation_contents( $path );
		if ( null === $contents ) {
			return true;
		}
		if ( 'image/gif' === $mime ) {
			return 1 !== self::gif_frame_count( $contents );
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
	 * Read bounded compressed bytes without invoking an image editor.
	 *
	 * @param string $path Validated local file.
	 * @return string|null
	 */
	private static function animation_contents( string $path ): ?string {
		$bytes = wp_filesize( $path );
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $bytes <= 0 || $bytes > self::MAX_BYTES
			|| ( $limit > 0 && memory_get_usage( true ) + $bytes + 2 * MB_IN_BYTES >= $limit )
		) {
			return null;
		}
		$contents = WpLocalFilesystem::get()->get_contents( $path );
		return is_string( $contents ) ? $contents : null;
	}

	/**
	 * Count complete GIF frames without decoding their pixels.
	 *
	 * @param string $contents Bounded file contents.
	 * @param bool   $full_canvas Require the frame to fill the logical canvas.
	 * @return int Zero for an unsupported or incomplete image.
	 */
	private static function gif_frame_count( string $contents, bool $full_canvas = false ): int {
		$total = strlen( $contents );
		if ( $total < 13 || ! in_array( substr( $contents, 0, 6 ), array( 'GIF87a', 'GIF89a' ), true ) ) {
			return 0;
		}

		$canvas = unpack( 'vwidth/vheight', $contents, 6 );
		if ( empty( $canvas['width'] ) || empty( $canvas['height'] ) || $canvas['width'] * $canvas['height'] > self::MAX_PIXELS ) {
			return 0;
		}
		$color_table_size = static function ( int $flags ): int {
			return ( $flags & 0x80 ) ? 3 * ( 2 << ( $flags & 7 ) ) : 0;
		};
		$offset           = 13 + $color_table_size( ord( $contents[10] ) );
		$frames           = 0;
		while ( $offset < $total ) {
			$block = ord( $contents[ $offset++ ] );
			if ( 0x3b === $block ) {
				return $offset === $total ? $frames : 0;
			}
			if ( 0x2c === $block ) {
				++$frames;
				if ( $offset + 9 >= $total ) {
					return 0;
				}
				$frame = unpack( 'vleft/vtop/vwidth/vheight', $contents, $offset );
				if ( empty( $frame['width'] ) || empty( $frame['height'] )
					|| $frame['left'] + $frame['width'] > $canvas['width'] || $frame['top'] + $frame['height'] > $canvas['height']
				) {
					return 0;
				}
				if ( $full_canvas && ( $frame['left'] || $frame['top'] || $frame['width'] !== $canvas['width'] || $frame['height'] !== $canvas['height'] ) ) {
					return 0;
				}
				// Image descriptor, local color table, and LZW minimum code size.
				$offset += 10 + $color_table_size( ord( $contents[ $offset + 8 ] ) );
			} elseif ( 0x21 === $block && $offset < $total && 0x01 !== ord( $contents[ $offset ] ) ) {
				++$offset;
			} else {
				// Plain text is also a rendering block. Keep the original if present.
				return 0;
			}

			while ( $offset < $total ) {
				$length = ord( $contents[ $offset++ ] );
				if ( 0 === $length ) {
					break;
				}
				$offset += $length;
			}
		}
		return 0;
	}

	/**
	 * Recognize a complete animated GIF without allocating its decoded frames.
	 *
	 * @param string $path Validated file path.
	 * @return bool
	 */
	public static function has_gif_animation( string $path ): bool {
		$contents = self::animation_contents( $path );
		return null !== $contents && self::gif_frame_count( $contents ) > 1;
	}

	/**
	 * Verify the editor decoded the whole GIF canvas and prepare GD's truecolor output.
	 *
	 * @param string           $path   Local image path.
	 * @param \WP_Image_Editor $editor Loaded image editor.
	 * @return bool
	 */
	public static function prepare_still_gif( string $path, \WP_Image_Editor $editor ): bool {
		$contents = self::animation_contents( $path );
		$frame    = $editor->get_size();
		if ( null === $contents || 1 !== self::gif_frame_count( $contents, true ) || ! is_array( $frame ) ) {
			return false;
		}
		// GD's crop copies palette pixels into a truecolor buffer with alpha.
		return ! $editor instanceof \WP_Image_Editor_GD || ! is_wp_error( $editor->crop( 0, 0, $frame['width'], $frame['height'] ) );
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
