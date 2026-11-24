<?php
/**
 * Shared base for Kilobyte integration tests.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Support;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Presentation\Hooks\FrontendImageDelivery;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Runs each test against real WordPress, a real database and real image files.
 *
 * WordPress rolls back database changes after each test. This class also removes
 * every file the test added to the uploads folder.
 */
abstract class TestCase extends \WP_UnitTestCase {

	public const REST_NAMESPACE = '/kilobyte-io/v1';

	/**
	 * Database connections a test opened to stand in for other PHP requests.
	 *
	 * @var \mysqli[]
	 */
	private array $other_requests = array();

	/**
	 * Makes sure this month's uploads folder exists.
	 *
	 * The previous test's tear_down() deleted it, and wp_upload_dir() remembers that it
	 * already created it once, so uploads through /wp/v2/media would have nowhere to go.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_mkdir_p( wp_upload_dir( null, false )['path'] );
	}

	/**
	 * Removes files and connections the test created.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->other_requests as $connection ) {
			$connection->close();
		}
		$this->other_requests = array();
		UploadedFiles::reset();
		Images::clean();
		$uploads = wp_upload_dir( null, false );
		$this->rmdir( $uploads['basedir'] );
		$this->delete_folders( $uploads['basedir'] );
		wp_mkdir_p( $uploads['basedir'] );
		parent::tear_down();
	}

	/**
	 * Signs in a new user with the given role.
	 *
	 * @param string $role WordPress role.
	 * @return int User ID.
	 */
	protected function sign_in_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Adds an image to the Media Library the way an upload does, including thumbnails.
	 *
	 * @param string $file    Image file to upload.
	 * @param int    $parent  Parent post ID.
	 * @return int Attachment ID.
	 */
	protected function upload( string $file, int $parent = 0 ): int {
		$id = self::factory()->attachment->create_upload_object( $file, $parent );
		$this->assertIsInt( $id, 'The test image could not be added to the Media Library.' );
		return $id;
	}

	/**
	 * Sends a request to the REST API.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $route   Route under the plugin namespace, or a full route.
	 * @param array<string, mixed> $params  Request parameters.
	 * @param array<string, mixed> $files   Uploaded files, shaped like `$_FILES`.
	 * @param array<string, mixed> $headers Request headers.
	 * @return WP_REST_Response
	 */
	protected function rest( string $method, string $route, array $params = array(), array $files = array(), array $headers = array() ): WP_REST_Response {
		$route   = 0 === strpos( $route, '/wp/' ) ? $route : self::REST_NAMESPACE . $route;
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( 'GET' !== $method && $params ) {
			$request->set_body_params( $params );
		}
		$request->set_file_params( $files );
		$request->set_headers( $headers );
		return rest_do_request( $request );
	}

	/**
	 * Optimizes an image with the server's image editor.
	 *
	 * @param int $id Attachment ID.
	 * @return WP_REST_Response
	 */
	protected function optimize_on_server( int $id ): WP_REST_Response {
		return $this->rest( 'POST', "/optimize-server/{$id}" );
	}

	/**
	 * Sends a copy the browser made, the way the admin screen does.
	 *
	 * @param int                  $id          Attachment ID.
	 * @param string               $copy        File the browser produced.
	 * @param string|null          $source_hash SHA-256 of the original the browser read. Defaults to the current original.
	 * @param array<string, mixed> $params      Extra form fields.
	 * @return WP_REST_Response
	 */
	protected function send_browser_copy( int $id, string $copy, ?string $source_hash = null, array $params = array() ): WP_REST_Response {
		UploadedFiles::receive( $copy );
		$params['source_hash'] = $source_hash ?? hash_file( 'sha256', (string) get_attached_file( $id ) );
		return $this->rest(
			'POST',
			"/optimize/{$id}",
			$params,
			array(
				'file' => array(
					'name'     => wp_basename( $copy ),
					'type'     => (string) wp_get_image_mime( $copy ),
					'tmp_name' => $copy,
					'error'    => UPLOAD_ERR_OK,
					'size'     => (int) filesize( $copy ),
				),
			)
		);
	}

	/**
	 * Encodes a WebP copy of the attachment's original, as the browser worker would.
	 *
	 * @param int $id Attachment ID.
	 * @return string Path of the WebP file.
	 */
	protected function browser_webp_of( int $id ): string {
		$editor = wp_get_image_editor( (string) get_attached_file( $id ) );
		$this->assertNotWPError( $editor );
		$path  = Images::png( 1, 1, 'placeholder.png' );
		$saved = $editor->save( dirname( $path ) . '/browser-copy.webp', 'image/webp' );
		$this->assertNotWPError( $saved );
		return $saved['path'];
	}

	/**
	 * The image URLs a visitor's browser is offered as the optimized source, in a fresh page view.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $size Registered image size.
	 * @return string[] URLs from the `<source srcset>`; empty when the plain image is served.
	 */
	protected function delivered_copies( int $id, string $size = 'full' ): array {
		$page = new FrontendImageDelivery( new WpAttachmentRepository() );
		$html = $page->wrap_attachment_image( $this->without_frontend_delivery( static fn () => wp_get_attachment_image( $id, $size ) ), $id );
		if ( ! preg_match( '~<source type="[^"]+" srcset="([^"]+)"~', $html, $match ) ) {
			return array();
		}
		return array_map(
			static fn ( string $candidate ): string => preg_split( '/\s+/', trim( $candidate ) )[0],
			explode( ',', html_entity_decode( $match[1] ) )
		);
	}

	/**
	 * Runs a callback with the plugin's front-end delivery filters switched off.
	 *
	 * The delivery object the plugin hooked when it loaded remembers each attachment's copies
	 * for the whole PHP process, so its answers go stale between steps of one test.
	 *
	 * @param callable $render Callback that renders markup.
	 * @return mixed What the callback returns.
	 */
	protected function without_frontend_delivery( callable $render ) {
		global $wp_filter;
		$removed = array();
		foreach ( array( 'the_content', 'wp_get_attachment_image', 'post_thumbnail_html' ) as $hook ) {
			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof FrontendImageDelivery ) {
						remove_filter( $hook, $callback['function'], $priority );
						$removed[] = array( $hook, $callback['function'], $priority, $callback['accepted_args'] );
					}
				}
			}
		}
		try {
			return $render();
		} finally {
			foreach ( $removed as [ $hook, $function, $priority, $args ] ) {
				add_filter( $hook, $function, $priority, $args );
			}
		}
	}

	/**
	 * Skips a test that needs AVIF encoding, which GD only has from PHP 8.1.
	 *
	 * @return void
	 */
	protected function requires_avif(): void {
		if ( ! function_exists( 'imageavif' ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			$this->markTestSkipped( 'This PHP build cannot encode AVIF.' );
		}
	}

	/**
	 * Skips a test about background cleanup on multisite.
	 *
	 * Kilobyte never deletes copies on multisite, where uploads storage can be shared, so its
	 * cleanup jobs do nothing there. The readme FAQ documents this.
	 *
	 * @return void
	 */
	protected function skip_on_multisite(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On multisite, Kilobyte keeps copies on disk instead of cleaning them up.' );
		}
	}

	/**
	 * Converts an uploads URL to its file path.
	 *
	 * @param string $url Uploads URL.
	 * @return string
	 */
	protected function path_of( string $url ): string {
		$uploads = wp_upload_dir( null, false );
		return $uploads['basedir'] . rawurldecode( substr( $url, strlen( $uploads['baseurl'] ) ) );
	}

	/**
	 * Asserts a REST response is the plugin error with this code and HTTP status.
	 *
	 * @param string           $reason   Failure reason, without the `kilobyte_io_` prefix.
	 * @param int              $status   Expected HTTP status.
	 * @param WP_REST_Response $response Response.
	 * @return void
	 */
	protected function assertRestFailure( string $reason, int $status, WP_REST_Response $response ): void {
		$data = $response->get_data();
		$this->assertSame( 'kilobyte_io_' . $reason, $data['code'] ?? null, 'Unexpected response: ' . wp_json_encode( $data ) );
		$this->assertSame( $status, $response->get_status() );
	}

	/**
	 * Asserts a REST response succeeded and returns its data.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	protected function assertRestOk( WP_REST_Response $response ): array {
		$this->assertSame( 200, $response->get_status(), 'Unexpected response: ' . wp_json_encode( $response->get_data() ) );
		return (array) $response->get_data();
	}

	/**
	 * Holds one of the plugin's locks from another database connection, as a
	 * second PHP request working on the same thing would.
	 *
	 * The name must match what the plugin passes to WpOperationLock, such as
	 * `image:123`, `backups`, `stats` or `automatic_uploads`.
	 *
	 * @param string $lock_name Plugin lock name.
	 * @return void
	 */
	protected function hold_lock_in_other_request( string $lock_name ): void {
		global $wpdb;
		[ $host, $port ] = array_pad( explode( ':', DB_HOST, 2 ), 2, '3306' );
		$connection      = new \mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME, (int) $port );
		$key             = 'kilobyte_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $lock_name );
		$held            = $connection->query( "SELECT GET_LOCK('" . $connection->real_escape_string( $key ) . "', 0)" )->fetch_row()[0];
		$this->assertSame( '1', (string) $held, "Could not take the {$lock_name} lock." );
		$this->other_requests[] = $connection;
	}

	/**
	 * Makes WordPress report a different database connection from now on, as it does
	 * after it reconnects to a dropped database. Any lock the plugin held is then lost.
	 *
	 * @return void
	 */
	protected function reconnect_database(): void {
		add_filter(
			'query',
			static fn ( $sql ) => 'SELECT CONNECTION_ID()' === $sql ? 'SELECT 0' : $sql
		);
	}

	/**
	 * Moves an attachment's background optimization job to now, as if its delay had passed.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	protected function make_upload_job_due( int $id ): void {
		update_post_meta( $id, WpAttachmentRepository::AUTO_PENDING, time() - 1 );
	}
}
