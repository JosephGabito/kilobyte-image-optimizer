<?php
/**
 * The actions and filters other plugins use around making a copy.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Optimize;

require_once __DIR__ . '/OptimizeTestCase.php';

use Kilobyte\ImageOptimizer\Tests\Support\Images;

final class HooksTest extends OptimizeTestCase {

	public function test_after_optimize_receives_the_image_and_its_saved_record(): void {
		$id    = $this->upload( Images::jpeg( 320, 240 ) );
		$calls = array();
		add_action(
			'kilobyte_io_after_optimize',
			static function ( int $attachment_id, array $record ) use ( &$calls ): void {
				$calls[] = array( $attachment_id, $record );
			},
			10,
			2
		);

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertCount( 1, $calls );
		[ $attachment_id, $record ] = $calls[0];
		$this->assertSame( $id, $attachment_id );
		$this->assertSame( 'optimized', $record['status'] );
		$this->assertSame( 'webp', $record['format'] );
		$this->assertSame( $result['optimized_size'], $record['optimized_size'] );
		$this->assertFileExists( wp_upload_dir( null, false )['basedir'] . '/' . $record['variant_path'] );
	}

	public function test_variant_changed_fires_once_when_a_browser_copy_is_saved(): void {
		$id      = $this->upload( Images::jpeg( 320, 240 ) );
		$changed = array();
		add_action(
			'kilobyte_io_variant_changed',
			static function ( int $attachment_id ) use ( &$changed ): void {
				$changed[] = $attachment_id;
			}
		);

		$this->assertRestOk( $this->send_browser_copy( $id, $this->browser_webp_of( $id ) ) );

		$this->assertSame( array( $id ), $changed );
	}

	public function test_no_hook_fires_when_nothing_is_saved(): void {
		$id    = $this->upload( Images::animated_gif() );
		$fired = 0;
		add_action(
			'kilobyte_io_after_optimize',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		add_action(
			'kilobyte_io_variant_changed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( 0, $fired );
	}

	public function test_a_failing_after_optimize_callback_does_not_undo_the_copy_or_skip_later_notices(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_action(
			'kilobyte_io_after_optimize',
			static function (): void {
				throw new \RuntimeException( 'A cache plugin failed.' );
			}
		);
		$changed = 0;
		add_action(
			'kilobyte_io_variant_changed',
			static function () use ( &$changed ): void {
				++$changed;
			}
		);

		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertFalse( $result['skipped'] );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );
		$this->assertSame( 1, $changed );
		$this->assertFalse( doing_action( 'kilobyte_io_after_optimize' ), 'WordPress must not think the failed action is still running.' );
	}

	public function test_should_optimize_is_asked_about_the_image_being_optimized(): void {
		$id    = $this->upload( Images::jpeg( 320, 240 ) );
		$asked = array();
		add_filter(
			'kilobyte_io_should_optimize',
			static function ( bool $optimize, int $attachment_id ) use ( &$asked ): bool {
				$asked[] = $attachment_id;
				return $optimize;
			},
			10,
			2
		);

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertSame( array( $id ), $asked );
	}

	public function test_the_variant_filename_filter_renames_copies_but_keeps_the_plugin_marker(): void {
		$id      = $this->upload( Images::jpeg( 320, 240 ) );
		$context = array();
		add_filter(
			'kilobyte_io_variant_filename',
			static function ( string $stem, array $details ) use ( &$context ): string {
				$context[] = $details;
				return 'Café ' . $stem;
			},
			10,
			2
		);

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$copies = $this->copies_next_to( $id );
		$this->assertCount( 3, $copies );
		foreach ( $copies as $copy ) {
			$this->assertMatchesRegularExpression( "/^Cafe-photo(-150x150|-300x225)?\\.kilobyte-{$id}-[a-f0-9-]{36}\\.webp$/", $copy );
		}
		$this->assertSame( array( false, true, true ), array_column( $context, 'is_thumbnail' ) );
		$this->assertSame( array( $id, $id, $id ), array_column( $context, 'attachment_id' ) );
		$this->assertSame( 'photo', $context[0]['source_stem'] );
		$this->assertSame( 'webp', $context[0]['extension'] );
		$this->assertTrue( wp_is_uuid( $context[0]['token'], 4 ) );
		$this->assertStringContainsString( $context[0]['token'], implode( ' ', $copies ) );
	}

	public function test_a_variant_filename_with_no_usable_characters_falls_back_to_the_original_name(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'kilobyte_io_variant_filename', static fn (): string => '***' );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertCount( 1, preg_grep( '/^photo\.kilobyte-/', $this->copies_next_to( $id ) ) );
	}

	public function test_a_variant_filename_filter_that_returns_no_text_falls_back_to_the_original_name(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'kilobyte_io_variant_filename', '__return_null' );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$this->assertCount( 1, preg_grep( '/^photo\.kilobyte-/', $this->copies_next_to( $id ) ) );
	}

	public function test_a_very_long_variant_filename_is_shortened(): void {
		$id = $this->upload( Images::jpeg( 320, 240 ) );
		add_filter( 'kilobyte_io_variant_filename', static fn (): string => str_repeat( 'a', 300 ) );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		foreach ( $this->copies_next_to( $id ) as $copy ) {
			$this->assertSame( str_repeat( 'a', 175 ) . '.kilobyte-', substr( $copy, 0, 185 ) );
		}
	}

	public function test_the_media_screen_tells_the_browser_which_copy_formats_the_site_accepts(): void {
		set_current_screen( 'upload' );
		add_filter( 'upload_mimes', static fn ( array $mimes ): array => array_diff( $mimes, array( 'image/avif' ) ) );
		$scripts               = $GLOBALS['wp_scripts'] ?? null;
		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		try {
			do_action( 'wp_enqueue_media' );
			$data = (string) wp_scripts()->get_data( 'kilobyte-io-upload-interceptor', 'data' );
		} finally {
			$GLOBALS['wp_scripts'] = $scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$this->assertStringContainsString( '"outputMimes":["image/webp","image/jpeg","image/png"]', $data );
	}
}
