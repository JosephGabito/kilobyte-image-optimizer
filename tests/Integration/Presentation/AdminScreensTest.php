<?php
/**
 * What the plugin adds to wp-admin: the Media > Optimization screen, the scripts that compress
 * uploads in the browser, the Plugins list link, and image hints on the front end.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Tests fire WordPress core hooks the way WordPress does.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Each test starts from empty script, style and admin menu registries.

namespace Kilobyte\ImageOptimizer\Tests\Integration\Presentation;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\OptionSettingsRepository;
use Kilobyte\ImageOptimizer\Presentation\Admin\OptimizerAdminPage;
use Kilobyte\ImageOptimizer\Presentation\Hooks\FrontendImageDelivery;
use Kilobyte\ImageOptimizer\Tests\Support\Images;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;
use WPDieException;

final class AdminScreensTest extends TestCase {

	private const SCREEN     = 'media_page_kilobyte-image-optimizer';
	private const COMPRESSOR = 'kilobyte-io-upload-interceptor';
	private const SCREEN_APP = 'kilobyte-io-admin';
	private const SCREEN_CSS = 'kilobyte-io-fine-tuning';
	private const OPTIMIZER  = 'http://example.org/wp-admin/upload.php?page=kilobyte-image-optimizer';

	public function set_up(): void {
		parent::set_up();
		$this->reset_assets();
	}

	public function tear_down(): void {
		$this->reset_assets();
		parent::tear_down();
	}

	/**
	 * @dataProvider screens_with_uploads
	 *
	 * @param string $hook_suffix Admin screen hook suffix.
	 */
	public function test_the_upload_compressor_loads_on_screens_where_people_upload_images( string $hook_suffix ): void {
		$this->open_admin_screen_as( 'author', $hook_suffix );

		$this->assertTrue( wp_script_is( self::COMPRESSOR, 'enqueued' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function screens_with_uploads(): array {
		return array(
			'Media Library'        => array( 'upload.php' ),
			'Add new media file'   => array( 'media-new.php' ),
			'Edit post'            => array( 'post.php' ),
			'Add new post'         => array( 'post-new.php' ),
			'Site editor'          => array( 'site-editor.php' ),
			'Media > Optimization' => array( self::SCREEN ),
		);
	}

	public function test_the_upload_compressor_is_not_loaded_on_other_admin_screens(): void {
		$this->open_admin_screen_as( 'administrator', 'index.php' );

		$this->assertFalse( wp_script_is( self::COMPRESSOR, 'enqueued' ) );
	}

	public function test_the_upload_compressor_is_not_loaded_for_people_who_cannot_upload(): void {
		$this->open_admin_screen_as( 'subscriber', 'upload.php' );

		$this->assertFalse( wp_script_is( self::COMPRESSOR, 'enqueued' ) );
	}

	public function test_the_upload_compressor_receives_the_saved_preferences_and_addresses(): void {
		update_option(
			'kilobyte_io_settings',
			array(
				'format'  => 'avif',
				'quality' => 70,
			) 
		);

		$this->open_admin_screen_as( 'author', 'upload.php' );

		$config = $this->localized( self::COMPRESSOR, 'KilobyteIOUploadConfig' );
		$this->assertSame( 'avif', $config['settings']['format'] );
		$this->assertSame( 70, $config['settings']['quality'] );
		$this->assertSame( 'http://example.org/index.php?rest_route=/wp/v2/media', $config['mediaUrl'] );
		$this->assertSame( self::OPTIMIZER, $config['optimizerUrl'] );
		$this->assertSame( KILOBYTE_IO_URL . 'build/image-worker.js?ver=' . KILOBYTE_IO_VERSION, $config['workerUrl'] );
		$this->assertContains( 'image/webp', $config['outputMimes'] );
		$this->assertSame( KILOBYTE_IO_URL . 'build/upload-interceptor.js', wp_scripts()->registered[ self::COMPRESSOR ]->src );
	}

	public function test_opening_the_media_window_on_an_edit_screen_loads_the_compressor_once(): void {
		$this->open_admin_screen_as( 'author', 'post.php' );

		wp_enqueue_media();

		$this->assertSame( 1, substr_count( (string) wp_scripts()->get_data( self::COMPRESSOR, 'data' ), 'var KilobyteIOUploadConfig' ) );
	}

	public function test_opening_the_media_window_on_any_admin_screen_loads_the_compressor(): void {
		$this->open_admin_screen_as( 'author', 'index.php' );

		wp_enqueue_media();

		$this->assertTrue( wp_script_is( self::COMPRESSOR, 'enqueued' ) );
	}

	public function test_opening_the_media_window_on_the_front_end_does_not_load_the_compressor(): void {
		$this->sign_in_as( 'administrator' );

		wp_enqueue_media();

		$this->assertFalse( wp_script_is( self::COMPRESSOR, 'enqueued' ) );
	}

	public function test_media_gets_an_optimization_screen_for_people_who_can_upload(): void {
		global $submenu;
		$this->sign_in_as( 'author' );
		set_current_screen( 'dashboard' );

		do_action( 'admin_menu' );

		$entries = wp_list_filter( $submenu['upload.php'] ?? array(), array( 2 => 'kilobyte-image-optimizer' ) );
		$this->assertSame( array( 'Optimization', 'upload_files', 'kilobyte-image-optimizer', 'Image optimization' ), current( $entries ) );
	}

	public function test_media_gets_no_optimization_screen_for_people_who_cannot_upload(): void {
		global $submenu;
		$this->sign_in_as( 'subscriber' );
		set_current_screen( 'dashboard' );

		do_action( 'admin_menu' );

		$this->assertSame( array(), wp_list_filter( $submenu['upload.php'] ?? array(), array( 2 => 'kilobyte-image-optimizer' ) ) );
	}

	public function test_the_optimization_screen_prints_the_mount_point_for_its_app(): void {
		$this->sign_in_as( 'author' );
		$this->build_admin_menu();

		ob_start();
		do_action( self::SCREEN );

		$this->assertSame( '<div id="kilobyte-io-root"></div>', ob_get_clean() );
	}

	public function test_the_optimization_screen_refuses_people_who_cannot_upload(): void {
		$this->sign_in_as( 'author' );
		$this->build_admin_menu();
		$this->sign_in_as( 'subscriber' );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You do not have permission to access this page.' );

		do_action( self::SCREEN );
	}

	public function test_the_optimization_screen_loads_its_app_styles_and_preferences(): void {
		update_option( 'kilobyte_io_settings', array( 'format' => 'avif' ) );

		$this->open_admin_screen_as( 'administrator', self::SCREEN );

		$this->assertTrue( wp_script_is( self::SCREEN_APP, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::SCREEN_CSS, 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wp-components', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'media-editor', 'enqueued' ), 'The screen opens the Media Library window, so it needs its scripts.' );
		$this->assertSame( KILOBYTE_IO_URL . 'assets/css/fine-tuning.css', wp_styles()->registered[ self::SCREEN_CSS ]->src );
		$config = $this->localized( self::SCREEN_APP, 'KilobyteIOAdmin' );
		$this->assertSame( '1', $config['canManage'], 'WordPress passes true to the script as "1".' );
		$this->assertSame( 'avif', $config['settings']['format'] );
		$this->assertSame( KILOBYTE_IO_URL . 'build/image-worker.js?ver=' . KILOBYTE_IO_VERSION, $config['workerUrl'] );
		$this->assertContains( 'image/webp', $config['outputMimes'] );
	}

	public function test_the_optimization_screen_tells_its_app_when_the_person_cannot_change_preferences(): void {
		$this->open_admin_screen_as( 'editor', self::SCREEN );

		$this->assertSame( '', $this->localized( self::SCREEN_APP, 'KilobyteIOAdmin' )['canManage'], 'WordPress passes false to the script as "".' );
	}

	public function test_other_admin_screens_do_not_load_the_optimization_app(): void {
		$this->open_admin_screen_as( 'administrator', 'upload.php' );

		$this->assertFalse( wp_script_is( self::SCREEN_APP, 'enqueued' ) );
		$this->assertFalse( wp_style_is( self::SCREEN_CSS, 'enqueued' ) );
	}

	public function test_a_copy_of_the_plugin_without_built_scripts_still_loads_the_app_with_core_components(): void {
		// A plugin folder checked out from source has no build/admin.asset.php until someone runs the build.
		$source_checkout = dirname( Images::png( 1, 1 ) ) . '/';
		$this->remove_admin_page_hooks();
		( new OptimizerAdminPage( new OptionSettingsRepository(), KILOBYTE_IO_URL, $source_checkout, '9.9.9' ) )->register();

		$this->open_admin_screen_as( 'administrator', self::SCREEN );

		$app = wp_scripts()->registered[ self::SCREEN_APP ];
		$this->assertSame( array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ), $app->deps );
		$this->assertSame( '9.9.9', $app->ver );
	}

	public function test_the_plugins_list_links_to_the_optimization_screen_first(): void {
		$links = apply_filters( 'plugin_action_links_' . plugin_basename( KILOBYTE_IO_FILE ), array( '<a href="#">Deactivate</a>' ) );

		$this->assertSame( array( '<a href="' . self::OPTIMIZER . '">Optimize images</a>', '<a href="#">Deactivate</a>' ), $links );
	}

	public function test_front_end_images_get_async_decoding_when_the_web_vitals_hints_are_on(): void {
		update_option( 'kilobyte_io_settings', array( 'cwv_enhancements' => true ) );

		$this->assertSame(
			array(
				'src'      => 'a.jpg',
				'decoding' => 'async',
			),
			apply_filters( 'wp_get_attachment_image_attributes', array( 'src' => 'a.jpg' ) ) 
		);
		$this->assertSame( array( 'decoding' => 'sync' ), apply_filters( 'wp_get_attachment_image_attributes', array( 'decoding' => 'sync' ) ), 'A decoding choice the theme made must stay.' );
	}

	public function test_images_get_no_decoding_hint_when_the_web_vitals_hints_are_off(): void {
		update_option( 'kilobyte_io_settings', array( 'cwv_enhancements' => false ) );

		$this->assertSame( array( 'src' => 'a.jpg' ), apply_filters( 'wp_get_attachment_image_attributes', array( 'src' => 'a.jpg' ) ) );
	}

	public function test_images_in_wp_admin_get_no_decoding_hint(): void {
		update_option( 'kilobyte_io_settings', array( 'cwv_enhancements' => true ) );
		set_current_screen( 'upload' );

		$this->assertSame( array( 'src' => 'a.jpg' ), apply_filters( 'wp_get_attachment_image_attributes', array( 'src' => 'a.jpg' ) ) );
	}

	public function test_loading_the_plugin_on_the_front_end_offers_copies_in_post_content(): void {
		$id = $this->optimized_image();
		$this->remove_delivery_hooks();

		kilobyte_io_bootstrap();

		$this->assertStringContainsString( '<source type="image/webp"', apply_filters( 'the_content', $this->image_html( $id ) ) );
	}

	public function test_loading_the_plugin_in_wp_admin_adds_its_screen_but_leaves_post_content_alone(): void {
		global $submenu;
		$id = $this->optimized_image();
		$this->remove_delivery_hooks();
		remove_all_actions( 'admin_menu' );
		set_current_screen( 'dashboard' );

		kilobyte_io_bootstrap();
		do_action( 'admin_menu' );

		$this->assertNotSame( array(), wp_list_filter( $submenu['upload.php'] ?? array(), array( 2 => 'kilobyte-image-optimizer' ) ) );
		$this->assertStringNotContainsString( '<picture>', apply_filters( 'the_content', $this->image_html( $id ) ) );
	}

	/**
	 * Builds the admin menu the way wp-admin does, with Media as the parent of upload.php.
	 *
	 * @return void
	 */
	private function build_admin_menu(): void {
		$GLOBALS['admin_page_hooks']['upload.php'] = 'media';
		set_current_screen( 'dashboard' );
		do_action( 'admin_menu' );
	}

	/**
	 * Signs in a new user with this role and fires the scripts hook of an admin screen.
	 *
	 * @param string $role        WordPress role.
	 * @param string $hook_suffix Admin screen hook suffix.
	 * @return void
	 */
	private function open_admin_screen_as( string $role, string $hook_suffix ): void {
		$this->sign_in_as( $role );
		set_current_screen( 'dashboard' );
		do_action( 'admin_enqueue_scripts', $hook_suffix );
	}

	/**
	 * The object a script receives from wp_localize_script.
	 *
	 * @param string $handle Script handle.
	 * @param string $name   JavaScript variable name.
	 * @return array<string, mixed>
	 */
	private function localized( string $handle, string $name ): array {
		$data = (string) wp_scripts()->get_data( $handle, 'data' );
		$this->assertMatchesRegularExpression( '/^var ' . $name . ' = (.*);$/s', $data );
		preg_match( '/^var ' . $name . ' = (.*);$/s', $data, $match );
		return json_decode( $match[1], true );
	}

	/**
	 * Uploads a JPEG and makes its WebP copy on the server.
	 *
	 * @return int Attachment ID.
	 */
	private function optimized_image(): int {
		$this->sign_in_as( 'administrator' );
		$id = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		return $id;
	}

	/**
	 * Post content holding a full-size image.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function image_html( int $id ): string {
		return '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" alt="">';
	}

	/**
	 * Removes the delivery hooks the plugin added when it loaded.
	 *
	 * @return void
	 */
	private function remove_delivery_hooks(): void {
		global $wp_filter;
		foreach ( array( 'the_content', 'wp_get_attachment_image', 'post_thumbnail_html' ) as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof FrontendImageDelivery ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * Removes the Media > Optimization hooks the plugin added when it loaded.
	 *
	 * @return void
	 */
	private function remove_admin_page_hooks(): void {
		global $wp_filter;
		foreach ( array( 'admin_menu', 'admin_enqueue_scripts' ) as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof OptimizerAdminPage ) {
						remove_action( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * Starts each test with no scripts, styles or admin menu entries registered.
	 *
	 * @return void
	 */
	private function reset_assets(): void {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		$GLOBALS['submenu']    = array();
		unset( $GLOBALS['admin_page_hooks']['upload.php'] );
	}
}
