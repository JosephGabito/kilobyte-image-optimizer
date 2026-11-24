<?php
/**
 * Image fixtures built at test time, so the repository holds no binary test files.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Support;

/**
 * Writes small, deterministic image files to a scratch folder.
 *
 * Every method returns the path of a new file the caller may move or delete.
 */
final class Images {

	/**
	 * Encoded bytes by fixture key, so each fixture is drawn once per run.
	 *
	 * @var array<string, string>
	 */
	private static array $bytes = array();

	/**
	 * A photo-like JPEG with enough detail that WebP and AVIF copies come out smaller.
	 *
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 * @param string $name   File name to use.
	 * @return string
	 */
	public static function jpeg( int $width = 640, int $height = 480, string $name = 'photo.jpg' ): string {
		return self::write(
			$name,
			self::encode(
				"jpeg:{$width}x{$height}",
				static function () use ( $width, $height ): string {
					return self::capture( static fn () => imagejpeg( self::draw( $width, $height ), null, 95 ) );
				}
			)
		);
	}

	/**
	 * A photo-like PNG.
	 *
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 * @param string $name   File name to use.
	 * @return string
	 */
	public static function png( int $width = 640, int $height = 480, string $name = 'graphic.png' ): string {
		return self::write(
			$name,
			self::encode(
				"png:{$width}x{$height}",
				static function () use ( $width, $height ): string {
					return self::capture( static fn () => imagepng( self::draw( $width, $height ) ) );
				}
			)
		);
	}

	/**
	 * A single-frame GIF.
	 *
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 * @param string $name   File name to use.
	 * @return string
	 */
	public static function still_gif( int $width = 640, int $height = 480, string $name = 'still.gif' ): string {
		return self::write(
			$name,
			self::encode(
				"gif:{$width}x{$height}",
				static function () use ( $width, $height ): string {
					return self::capture( static fn () => imagegif( self::draw( $width, $height ) ) );
				}
			)
		);
	}

	/**
	 * A WebP image.
	 *
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 * @param string $name   File name to use.
	 * @return string
	 */
	public static function webp( int $width = 640, int $height = 480, string $name = 'picture.webp' ): string {
		return self::write(
			$name,
			self::encode(
				"webp:{$width}x{$height}",
				static function () use ( $width, $height ): string {
					return self::capture( static fn () => imagewebp( self::draw( $width, $height ), null, 90 ) );
				}
			)
		);
	}

	/**
	 * A two-frame animated GIF on a 2x2 canvas.
	 *
	 * @param string $name File name to use.
	 * @return string
	 */
	public static function animated_gif( string $name = 'animated.gif' ): string {
		$frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00"           // Graphic control extension: 0.1 s delay.
			. "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00"       // Image descriptor: full 2x2 canvas.
			. "\x02\x02\x84\x51\x00";                           // LZW data for four pixels.
		$gif   = "GIF89a\x02\x00\x02\x00\x80\x00\x00"           // Header, 2x2 canvas, 2-colour table.
			. "\xFF\xFF\xFF\x00\x00\x00"                         // Colour table: white, black.
			. "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00"     // Loop forever.
			. $frame . $frame
			. "\x3B";
		return self::write( $name, $gif );
	}

	/**
	 * An animated PNG (APNG): a PNG with an animation control chunk before its pixel data.
	 *
	 * @param string $name File name to use.
	 * @return string
	 */
	public static function animated_png( string $name = 'animated.png' ): string {
		$png  = file_get_contents( self::png( 64, 64, 'apng-source.png' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = pack( 'NN', 2, 0 );
		$actl = pack( 'N', strlen( $data ) ) . 'acTL' . $data . pack( 'N', crc32( 'acTL' . $data ) );
		// The signature (8 bytes) and IHDR chunk (25 bytes) come first.
		return self::write( $name, substr( $png, 0, 33 ) . $actl . substr( $png, 33 ) );
	}

	/**
	 * A file named like an image that holds no image data.
	 *
	 * @param string $name File name to use.
	 * @return string
	 */
	public static function not_an_image( string $name = 'broken.jpg' ): string {
		return self::write( $name, 'This is plain text, not a JPEG.' );
	}

	/**
	 * Removes every file this class wrote.
	 *
	 * @return void
	 */
	public static function clean(): void {
		$dir = self::dir();
		foreach ( (array) glob( $dir . '/*/*' ) as $file ) {
			unlink( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $folder ) {
			rmdir( (string) $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	/**
	 * Draws a deterministic scene of gradients and shapes.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return \GdImage
	 */
	private static function draw( int $width, int $height ) {
		$image = imagecreatetruecolor( $width, $height );
		for ( $y = 0; $y < $height; $y += 4 ) {
			$shade = (int) ( 255 * $y / max( 1, $height ) );
			imagefilledrectangle( $image, 0, $y, $width, $y + 3, imagecolorallocate( $image, $shade, 120, 255 - $shade ) );
		}
		mt_srand( $width * 7919 + $height );
		for ( $i = 0; $i < 400; $i++ ) {
			$color = imagecolorallocate( $image, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
			imagefilledellipse( $image, mt_rand( 0, $width ), mt_rand( 0, $height ), mt_rand( 4, 60 ), mt_rand( 4, 60 ), $color );
		}
		mt_srand();
		return $image;
	}

	/**
	 * Returns cached bytes for a fixture key, drawing them on first use.
	 *
	 * @param string   $key   Fixture key.
	 * @param callable $build Builds the bytes.
	 * @return string
	 */
	private static function encode( string $key, callable $build ): string {
		if ( ! isset( self::$bytes[ $key ] ) ) {
			self::$bytes[ $key ] = $build();
		}
		return self::$bytes[ $key ];
	}

	/**
	 * Captures what a GD output function prints.
	 *
	 * @param callable $output GD call that prints image bytes.
	 * @return string
	 */
	private static function capture( callable $output ): string {
		ob_start();
		$output();
		return (string) ob_get_clean();
	}

	/**
	 * Writes bytes under a unique folder so two fixtures never share a path.
	 *
	 * @param string $name  File name.
	 * @param string $bytes File contents.
	 * @return string
	 */
	private static function write( string $name, string $bytes ): string {
		$folder = self::dir() . '/' . uniqid( '', true );
		mkdir( $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$path = $folder . '/' . $name;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	}

	/**
	 * Scratch folder for this test run.
	 *
	 * @return string
	 */
	private static function dir(): string {
		$dir = sys_get_temp_dir() . '/kilobyte-tests-' . getmypid();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		return $dir;
	}
}
