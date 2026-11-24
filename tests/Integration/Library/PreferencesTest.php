<?php
/**
 * Saving the optimization preferences from the admin screen.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Library;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LibraryTestCase.php';

final class PreferencesTest extends LibraryTestCase {

	/**
	 * The preferences a new site starts with.
	 */
	private const DEFAULTS = array(
		'enabled'          => true,
		'format'           => 'webp',
		'quality'          => 82,
		'max_width'        => 2560,
		'max_height'       => 2560,
		'keep_backups'     => true,
		'skip_smaller'     => true,
		'auto_alt_text'    => false,
		'cwv_enhancements' => false,
		'concurrency'      => 3,
	);

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	public function test_an_administrator_saves_every_preference_and_gets_them_back(): void {
		$wanted = array(
			'enabled'          => false,
			'format'           => 'avif',
			'quality'          => 60,
			'max_width'        => 1920,
			'max_height'       => 1080,
			'keep_backups'     => false,
			'skip_smaller'     => false,
			'auto_alt_text'    => true,
			'cwv_enhancements' => true,
			'concurrency'      => 5,
		);

		$saved = $this->assertRestOk( $this->save_preferences( (string) wp_json_encode( $wanted ) ) );

		$this->assertSame( $wanted, $saved );
	}

	public function test_saving_one_preference_keeps_the_others_saved_earlier(): void {
		$this->assertRestOk( $this->save_preferences( '{"format":"avif","quality":70}' ) );

		$saved = $this->assertRestOk( $this->save_preferences( '{"max_width":1200}' ) );

		$this->assertSame( 'avif', $saved['format'] );
		$this->assertSame( 70, $saved['quality'] );
		$this->assertSame( 1200, $saved['max_width'] );
	}

	public function test_an_empty_save_on_a_new_site_returns_the_defaults(): void {
		$this->assertSame( self::DEFAULTS, $this->assertRestOk( $this->save_preferences( '{}' ) ) );
	}

	public function test_saving_the_same_preferences_twice_succeeds(): void {
		$this->assertRestOk( $this->save_preferences( '{"quality":75}' ) );

		$again = $this->assertRestOk( $this->save_preferences( '{"quality":75}' ) );

		$this->assertSame( 75, $again['quality'] );
	}

	public function test_values_below_the_allowed_range_are_raised_to_the_minimum(): void {
		$saved = $this->assertRestOk( $this->save_preferences( '{"quality":5,"max_width":-10,"max_height":-1,"concurrency":0}' ) );

		$this->assertSame( 40, $saved['quality'] );
		$this->assertSame( 0, $saved['max_width'] );
		$this->assertSame( 0, $saved['max_height'] );
		$this->assertSame( 1, $saved['concurrency'] );
	}

	public function test_values_above_the_allowed_range_are_lowered_to_the_maximum(): void {
		$saved = $this->assertRestOk( $this->save_preferences( '{"quality":500,"max_width":20000,"max_height":9000,"concurrency":50}' ) );

		$this->assertSame( 95, $saved['quality'] );
		$this->assertSame( 8000, $saved['max_width'] );
		$this->assertSame( 8000, $saved['max_height'] );
		$this->assertSame( 6, $saved['concurrency'] );
	}

	public function test_an_unknown_format_falls_back_to_webp(): void {
		$this->assertRestOk( $this->save_preferences( '{"format":"avif"}' ) );

		$this->assertSame( 'webp', $this->assertRestOk( $this->save_preferences( '{"format":"jpeg-xl"}' ) )['format'] );
	}

	public function test_a_format_name_is_read_without_regard_to_case_or_spaces(): void {
		$this->assertSame( 'original', $this->assertRestOk( $this->save_preferences( '{"format":" Original "}' ) )['format'] );
	}

	public function test_a_format_that_is_not_text_keeps_the_default(): void {
		$this->assertSame( 'webp', $this->assertRestOk( $this->save_preferences( '{"format":["avif"]}' ) )['format'] );
	}

	public function test_unknown_fields_are_not_saved(): void {
		$saved = $this->assertRestOk( $this->save_preferences( '{"quality":70,"api_key":"secret"}' ) );

		$this->assertArrayNotHasKey( 'api_key', $saved );
		$this->assertArrayNotHasKey( 'api_key', $this->assertRestOk( $this->save_preferences( '{}' ) ) );
	}

	public function test_preferences_sent_as_a_form_instead_of_json_are_rejected(): void {
		$this->assertRestOk( $this->save_preferences( '{"quality":70}' ) );

		$response = $this->rest( 'POST', '/settings', array( 'quality' => 50 ) );

		$this->assertSame( 'kilobyte_io_invalid_settings', $response->get_data()['code'] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 70, $this->assertRestOk( $this->save_preferences( '{}' ) )['quality'], 'The earlier preferences must stay.' );
	}

	public function test_a_json_value_that_is_not_an_object_is_rejected(): void {
		$response = $this->save_preferences( '"avif"' );

		$this->assertSame( 'kilobyte_io_invalid_settings', $response->get_data()['code'] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_failed_save_is_reported_and_the_earlier_preferences_stay(): void {
		$this->assertRestOk( $this->save_preferences( '{"quality":70}' ) );
		// The database refuses the write, so WordPress keeps the stored value.
		$refuse = static fn ( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_kilobyte_io_settings', $refuse, 10, 2 );

		$response = $this->save_preferences( '{"quality":50}' );
		remove_filter( 'pre_update_option_kilobyte_io_settings', $refuse, 10 );

		$this->assertSame( 'kilobyte_io_settings_save_failed', $response->get_data()['code'] );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 70, $this->assertRestOk( $this->save_preferences( '{}' ) )['quality'] );
	}

	public function test_an_editor_cannot_change_the_preferences(): void {
		$this->sign_in_as( 'editor' );

		$this->assertSame( 403, $this->save_preferences( '{"quality":50}' )->get_status() );
	}

	public function test_a_site_filter_locks_preferences_without_overwriting_the_saved_ones(): void {
		$this->assertRestOk( $this->save_preferences( '{"format":"webp","quality":70}' ) );
		$lock = static fn ( array $settings ): array => array_replace( $settings, array( 'format' => 'avif' ) );
		add_filter( 'kilobyte_io_settings', $lock );

		$locked = $this->assertRestOk( $this->save_preferences( '{"quality":60}' ) );
		remove_filter( 'kilobyte_io_settings', $lock );
		$unlocked = $this->assertRestOk( $this->save_preferences( '{}' ) );

		$this->assertSame( 'avif', $locked['format'], 'The response shows the preferences in effect.' );
		$this->assertSame( 60, $locked['quality'] );
		$this->assertSame( 'webp', $unlocked['format'], 'The locked value must not replace the saved one.' );
		$this->assertSame( 60, $unlocked['quality'] );
	}

	public function test_a_site_filter_that_returns_something_other_than_a_list_is_ignored(): void {
		add_filter( 'kilobyte_io_settings', static fn () => 'avif' );

		$saved = $this->assertRestOk( $this->save_preferences( '{"quality":65}' ) );

		$this->assertSame( 'webp', $saved['format'] );
		$this->assertSame( 65, $saved['quality'] );
	}

	public function test_a_locked_format_decides_what_the_server_encodes(): void {
		$this->requires_avif();
		add_filter( 'kilobyte_io_settings', static fn ( array $settings ): array => array_replace( $settings, array( 'format' => 'avif' ) ) );
		$id = $this->upload( Images::jpeg() );

		$this->assertSame( 'avif', $this->assertRestOk( $this->optimize_on_server( $id ) )['format'] );
	}

	public function test_stored_preferences_that_are_not_a_list_are_replaced_by_the_defaults(): void {
		update_option( 'kilobyte_io_settings', 'corrupted' );

		$this->assertSame( self::DEFAULTS, $this->assertRestOk( $this->save_preferences( '{}' ) ) );
	}

	public function test_activating_the_plugin_keeps_preferences_saved_earlier(): void {
		$this->assertRestOk( $this->save_preferences( '{"quality":66}' ) );

		kilobyte_io_activate();

		$this->assertSame( 66, $this->assertRestOk( $this->save_preferences( '{}' ) )['quality'] );
	}
}
