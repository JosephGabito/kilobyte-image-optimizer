<?php
/**
 * Helpers shared by the tests that make optimized copies.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Tests\Support\Images;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;

/**
 * Signs in an administrator and offers fixtures and checks for copy-making tests.
 */
abstract class OptimizeTestCase extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	/**
	 * Saves the plugin preferences the way the settings screen stores them.
	 *
	 * @param array<string, mixed> $settings Preferences to change.
	 * @return void
	 */
	protected function use_settings( array $settings ): void {
		update_option( 'kilobyte_io_settings', $settings );
	}

	/**
	 * Every file in the folder that holds the attachment's original, by name.
	 *
	 * @param int $id Attachment ID.
	 * @return string[]
	 */
	protected function files_next_to( int $id ): array {
		$names = array_map( 'basename', (array) glob( dirname( (string) get_attached_file( $id ) ) . '/*' ) );
		sort( $names );
		return $names;
	}

	/**
	 * Names of the optimized copies in the attachment's folder.
	 *
	 * @param int $id Attachment ID.
	 * @return string[]
	 */
	protected function copies_next_to( int $id ): array {
		return array_values( array_filter( $this->files_next_to( $id ), static fn ( string $name ): bool => false !== strpos( $name, '.kilobyte-' ) ) );
	}

	/**
	 * The text a site admin sees in the Media Library's Optimization column.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	protected function media_library_status( int $id ): string {
		ob_start();
		do_action( 'manage_media_custom_column', 'kilobyte_io', $id );
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ob_get_clean() ) ) );
	}

	/**
	 * Library totals from the stats endpoint.
	 *
	 * @return array<string, mixed>
	 */
	protected function stats(): array {
		return $this->assertRestOk( $this->rest( 'GET', '/stats' ) );
	}

	/**
	 * Image IDs the bulk optimizer still offers to optimize.
	 *
	 * @return int[]
	 */
	protected function optimize_queue(): array {
		$page = $this->assertRestOk( $this->rest( 'GET', '/queue', array( 'mode' => 'optimize' ) ) );
		return array_map( static fn ( array $item ): int => (int) $item['id'], $page['items'] );
	}

	/**
	 * Runs the scheduled cleanup of copies as if its delay had passed.
	 *
	 * Scheduled cleanup batches store their due time in their option name.
	 *
	 * @return void
	 */
	protected function run_due_cleanup(): void {
		global $wpdb;
		$prefix = 'kilobyte_io_cleanup_batch_';
		$names  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		$this->assertNotEmpty( $names, 'No cleanup was scheduled.' );
		foreach ( $names as $name ) {
			$job = get_option( $name );
			delete_option( $name );
			add_option( $prefix . sprintf( '%012d', time() - 1 ) . substr( $name, strlen( $prefix ) + 12 ), $job, '', false );
		}
		do_action( WpVariantRetirement::EVENT );
	}

	/**
	 * Replaces the original file on disk, as another media tool would, keeping its path.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $file New image contents.
	 * @return void
	 */
	protected function replace_original_with( int $id, string $file ): void {
		$original = (string) get_attached_file( $id );
		file_put_contents( $original, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		clearstatcache( true, $original );
	}

	/**
	 * A small BMP drawn with GD.
	 *
	 * @return string
	 */
	protected function bmp(): string {
		$image = imagecreatetruecolor( 64, 48 );
		for ( $x = 0; $x < 64; $x += 8 ) {
			imagefilledrectangle( $image, $x, 0, $x + 7, 47, imagecolorallocate( $image, $x * 4, 255 - $x * 4, 128 ) );
		}
		$path = dirname( Images::png( 1, 1, 'unused.png' ) ) . '/bitmap.bmp';
		imagebmp( $image, $path, false );
		return $path;
	}

	/**
	 * A file with a valid image header that claims the given size, followed by no real pixels.
	 *
	 * PHP reads the size from the header alone, so the file is tiny.
	 *
	 * @param int    $width  Claimed width.
	 * @param int    $height Claimed height.
	 * @param string $name   File name.
	 * @return string
	 */
	protected function png_header_only( int $width, int $height, string $name = 'huge.png' ): string {
		$ihdr = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
		$data = "\x89PNG\r\n\x1a\n"
			. pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) )
			. pack( 'N', 4 ) . 'IDAT' . 'junk' . pack( 'N', crc32( 'IDATjunk' ) )
			. pack( 'N', 0 ) . 'IEND' . pack( 'N', crc32( 'IEND' ) );
		return $this->write_fixture( $name, $data );
	}

	/**
	 * A WebP header that declares an animation.
	 *
	 * @return string
	 */
	protected function animated_webp(): string {
		$vp8x = "\x02\x00\x00\x00" . "\x0F\x00\x00" . "\x0F\x00\x00"; // Animation flag, 16x16 canvas.
		$anim = "\xFF\xFF\xFF\xFF\x00\x00";
		$body = 'WEBP' . 'VP8X' . pack( 'V', strlen( $vp8x ) ) . $vp8x . 'ANIM' . pack( 'V', strlen( $anim ) ) . $anim;
		return $this->write_fixture( 'animated.webp', 'RIFF' . pack( 'V', strlen( $body ) ) . $body );
	}

	/**
	 * An AVIF whose file type box names the image-sequence brand used for animations.
	 *
	 * @return string
	 */
	protected function animated_avif(): string {
		ob_start();
		imageavif( imagecreatetruecolor( 16, 16 ) );
		$avif = (string) ob_get_clean();
		return $this->write_fixture( 'animated.avif', substr_replace( $avif, 'avis', (int) strpos( $avif, 'avif' ), 4 ) );
	}

	/**
	 * A Windows icon, an image type the plugin does not optimize.
	 *
	 * @return string
	 */
	protected function icon(): string {
		$png = (string) file_get_contents( Images::png( 16, 16 ) );
		return $this->write_fixture(
			'favicon.ico',
			"\x00\x00\x01\x00\x01\x00" . "\x10\x10\x00\x00\x01\x00\x20\x00" . pack( 'VV', strlen( $png ), 22 ) . $png
		);
	}

	/**
	 * A one-frame GIF whose frame covers only the top-left quarter of its 4x4 canvas.
	 *
	 * @return string
	 */
	protected function gif_with_partial_frame(): string {
		return $this->write_fixture(
			'partial.gif',
			"GIF89a\x04\x00\x04\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00"
				. "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00\x02\x02\x84\x51\x00"
				. "\x3B"
		);
	}

	/**
	 * A one-frame GIF that also holds a plain-text block, which the plugin cannot copy faithfully.
	 *
	 * @return string
	 */
	protected function gif_with_text_block(): string {
		return $this->write_fixture(
			'caption.gif',
			"GIF89a\x02\x00\x02\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00"
				. "\x21\x01\x0C" . str_repeat( "\x00", 12 ) . "\x01A\x00"
				. "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00\x02\x02\x84\x51\x00"
				. "\x3B"
		);
	}

	/**
	 * Writes bytes to a fresh fixture path that the shared fixture cleanup removes.
	 *
	 * @param string $name  File name.
	 * @param string $bytes Contents.
	 * @return string
	 */
	protected function write_fixture( string $name, string $bytes ): string {
		$path = dirname( Images::png( 1, 1, 'unused.png' ) ) . '/' . $name;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $path;
	}
}
