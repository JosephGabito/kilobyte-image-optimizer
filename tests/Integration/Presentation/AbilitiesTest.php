<?php
/**
 * WordPress Abilities API integration for Kilobyte Image Optimizer.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Presentation;

use Kilobyte\ImageOptimizer\Tests\Support\Images;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;
use WP_Ability;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AbilitiesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API is not available in this WordPress version.' );
		}
		$this->sign_in_as( 'administrator' );
	}

	public function test_kilobyte_registers_its_category_and_all_seven_public_abilities(): void {
		$this->assertTrue( wp_has_ability_category( 'kilobyte-image-optimizer' ) );

		$expected = array(
			'kilobyte/get-stats'             => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'kilobyte/get-queue'             => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'kilobyte/get-settings'          => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'kilobyte/update-settings'       => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'kilobyte/optimize-image'        => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'kilobyte/restore-image'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'kilobyte/regenerate-thumbnails' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		);

		foreach ( $expected as $name => $flags ) {
			$ability = wp_get_ability( $name );
			$this->assertInstanceOf( WP_Ability::class, $ability, "Ability {$name} was not registered." );
			$this->assertSame( 'kilobyte-image-optimizer', $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'public' ), "Ability {$name} should be public." );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ), "Ability {$name} should be exposed in REST." );
			$annotations = $ability->get_meta_item( 'annotations' );
			$this->assertSame( $flags['readonly'], $annotations['readonly'], "Unexpected readonly annotation on {$name}." );
			$this->assertSame( $flags['destructive'], $annotations['destructive'], "Unexpected destructive annotation on {$name}." );
			$this->assertSame( $flags['idempotent'], $annotations['idempotent'], "Unexpected idempotent annotation on {$name}." );
		}
	}

	public function test_get_stats_ability_returns_media_library_totals(): void {
		$id = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $id ) );

		$result = $this->execute_ability( 'kilobyte/get-stats' );

		$this->assertSame( 1, $result['total_images'] );
		$this->assertSame( 1, $result['optimized_count'] );
		$this->assertSame( 0, $result['remaining_count'] );
		$this->assertGreaterThan( 0, $result['total_saved'] );
	}

	public function test_get_queue_ability_accepts_defaults_arrays_and_objects(): void {
		$unoptimized = $this->upload( Images::jpeg( 640, 480, 'first.jpg' ) );
		$optimized   = $this->upload( Images::jpeg( 640, 480, 'second.jpg' ) );
		$this->assertRestOk( $this->optimize_on_server( $optimized ) );

		$optimize_queue = $this->execute_ability( 'kilobyte/get-queue' );
		$restore_queue  = $this->execute_ability(
			'kilobyte/get-queue',
			(object) array(
				'mode'     => 'restore',
				'per_page' => '10',
			)
		);

		$this->assertSame( array( $unoptimized ), array_column( $optimize_queue['items'], 'id' ) );
		$this->assertSame( array( $optimized ), array_column( $restore_queue['items'], 'id' ) );
		$this->assertSame( 0, $restore_queue['next_before'] );
	}

	public function test_update_settings_ability_normalizes_string_booleans_and_object_inputs(): void {
		$initial = $this->execute_ability( 'kilobyte/get-settings' );
		$this->assertTrue( $initial['enabled'] );
		$this->assertTrue( $initial['skip_smaller'] );

		$updated = $this->execute_ability(
			'kilobyte/update-settings',
			(object) array(
				'enabled'       => 'false',
				'skip_smaller'  => '0',
				'auto_alt_text' => 'true',
				'format'        => 'avif',
				'quality'       => '74',
			)
		);

		$this->assertFalse( $updated['enabled'], 'String "false" must turn enabled off.' );
		$this->assertFalse( $updated['skip_smaller'], 'String "0" must turn skip_smaller off.' );
		$this->assertTrue( $updated['auto_alt_text'] );
		$this->assertSame( 'avif', $updated['format'] );
		$this->assertSame( 74, $updated['quality'] );

		$reloaded = $this->execute_ability( 'kilobyte/get-settings' );
		$this->assertFalse( $reloaded['enabled'] );
		$this->assertFalse( $reloaded['skip_smaller'] );
		$this->assertSame( 74, $reloaded['quality'] );
	}

	public function test_update_settings_ability_rejects_invalid_input_and_save_failures(): void {
		$invalid = wp_get_ability( 'kilobyte/update-settings' )->execute( array( 'quality' => 120 ) );
		$this->assertWPError( $invalid );
		$this->assertSame( 'ability_invalid_input', $invalid->get_error_code() );

		add_filter( 'pre_update_option_kilobyte_io_settings', static fn ( $value, $old ) => $old, 10, 2 );
		$failed = wp_get_ability( 'kilobyte/update-settings' )->execute( array( 'quality' => 65 ) );
		$this->assertWPError( $failed );
		$this->assertSame( 'kilobyte_io_settings_save_failed', $failed->get_error_code() );
	}

	public function test_optimize_restore_and_regenerate_abilities_accept_object_inputs_end_to_end(): void {
		$id = $this->upload( Images::jpeg( 640, 480, 'studio.jpg' ) );

		$optimized = $this->execute_ability( 'kilobyte/optimize-image', (object) array( 'attachment_id' => $id ) );
		$this->assertFalse( $optimized['skipped'] );
		$this->assertSame( 'webp', $optimized['format'] );
		$this->assertNotEmpty( $this->delivered_copies( $id ) );

		$again = $this->execute_ability( 'kilobyte/optimize-image', array( 'attachment_id' => (string) $id ) );
		$this->assertTrue( $again['skipped'] );
		$this->assertSame( 'already_processed', $again['status'] );

		$restored = $this->execute_ability( 'kilobyte/restore-image', (object) array( 'attachment_id' => $id ) );
		$this->assertTrue( $restored['restored'] );
		$this->assertSame( 'sibling', $restored['mode'] );
		$this->assertSame( array(), $this->delivered_copies( $id ) );

		$missing_backup = wp_get_ability( 'kilobyte/restore-image' )->execute( array( 'attachment_id' => $id ) );
		$this->assertWPError( $missing_backup );
		$this->assertSame( 'kilobyte_io_no_backup', $missing_backup->get_error_code() );

		$regenerated = $this->execute_ability( 'kilobyte/regenerate-thumbnails', (object) array( 'attachment_id' => $id ) );
		$this->assertSame( $id, $regenerated['id'] );
		$this->assertGreaterThanOrEqual( 2, $regenerated['sizes'] );
	}

	public function test_abilities_work_through_the_wordpress_abilities_rest_api(): void {
		$id = $this->upload( Images::jpeg( 640, 480, 'rest-ability.jpg' ) );

		$settings_response = $this->run_ability_rest(
			'POST',
			'kilobyte/update-settings',
			array(
				'enabled' => 'false',
				'quality' => 68,
			)
		);
		$this->assertSame( 200, $settings_response->get_status() );
		$this->assertFalse( $settings_response->get_data()['enabled'] );
		$this->assertSame( 68, $settings_response->get_data()['quality'] );

		$optimize_response = $this->run_ability_rest(
			'POST',
			'kilobyte/optimize-image',
			array( 'attachment_id' => $id )
		);
		$this->assertSame( 200, $optimize_response->get_status() );
		$this->assertFalse( $optimize_response->get_data()['skipped'] );

		$stats_response = $this->run_ability_rest( 'GET', 'kilobyte/get-stats' );
		$this->assertSame( 200, $stats_response->get_status() );
		$this->assertSame( 1, $stats_response->get_data()['optimized_count'] );

		$queue_response = $this->run_ability_rest(
			'GET',
			'kilobyte/get-queue',
			array(
				'mode'     => 'restore',
				'per_page' => 5,
			)
		);
		$this->assertSame( 200, $queue_response->get_status() );
		$this->assertSame( array( $id ), array_column( $queue_response->get_data()['items'], 'id' ) );

		$restore_response = $this->run_ability_rest(
			'POST',
			'kilobyte/restore-image',
			array( 'attachment_id' => $id )
		);
		$this->assertSame( 200, $restore_response->get_status() );
		$this->assertTrue( $restore_response->get_data()['restored'] );
	}

	public function test_abilities_enforce_wordpress_capabilities_and_per_attachment_ownership(): void {
		$admin_image = $this->upload( Images::jpeg( 320, 240, 'admin-photo.jpg' ) );

		$author_id = $this->sign_in_as( 'author' );
		$own_image = $this->upload( Images::jpeg( 320, 240, 'author-photo.jpg' ) );
		wp_update_post(
			array(
				'ID'          => $own_image,
				'post_author' => $author_id,
			)
		);

		$this->assertIsArray( $this->execute_ability( 'kilobyte/get-stats' ) );
		$this->assertFalse( $this->execute_ability( 'kilobyte/optimize-image', (object) array( 'attachment_id' => $own_image ) )['skipped'] );

		$denied_other = wp_get_ability( 'kilobyte/optimize-image' )->execute( array( 'attachment_id' => $admin_image ) );
		$this->assertWPError( $denied_other );
		$this->assertSame( 'ability_invalid_permissions', $denied_other->get_error_code() );

		$denied_settings = wp_get_ability( 'kilobyte/update-settings' )->execute( array( 'quality' => 60 ) );
		$this->assertWPError( $denied_settings );
		$this->assertSame( 'ability_invalid_permissions', $denied_settings->get_error_code() );

		$this->sign_in_as( 'contributor' );
		$denied_stats = wp_get_ability( 'kilobyte/get-stats' )->execute();
		$this->assertWPError( $denied_stats );
		$this->assertSame( 'ability_invalid_permissions', $denied_stats->get_error_code() );
	}

	public function test_non_attachment_post_is_rejected_by_permission_check(): void {
		$post_id = self::factory()->post->create();

		$result = wp_get_ability( 'kilobyte/optimize-image' )->execute( array( 'attachment_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Execute a registered ability directly and assert it succeeds with an array payload.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Optional ability input (array or object).
	 * @return array<string, mixed>
	 */
	private function execute_ability( string $name, $input = null ): array {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, "Ability {$name} was not registered." );

		$result = null === $input ? $ability->execute() : $ability->execute( $input );
		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			$result instanceof WP_Error ? "Ability {$name} failed: {$result->get_error_code()} - {$result->get_error_message()}" : ''
		);
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * Dispatch a request to WordPress's published Abilities REST endpoint.
	 *
	 * @param string                    $method HTTP method (GET or POST).
	 * @param string                    $name   Ability name.
	 * @param array<string, mixed>|null $input  Optional input payload.
	 * @return WP_REST_Response
	 */
	private function run_ability_rest( string $method, string $name, ?array $input = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp-abilities/v1/abilities/' . $name . '/run' );
		if ( null !== $input ) {
			if ( 'GET' === $method ) {
				$request->set_query_params( array( 'input' => $input ) );
			} else {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( array( 'input' => $input ) ) );
			}
		}

		return rest_do_request( $request );
	}
}
