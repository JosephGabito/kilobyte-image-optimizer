<?php
/**
 * REST API controller for Kilobyte Image Optimizer.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation\Rest;

use Kilobyte\ImageOptimizer\Application\MarkAlreadyOptimalUseCase;
use Kilobyte\ImageOptimizer\Application\OptimizeFromBrowserUseCase;
use Kilobyte\ImageOptimizer\Application\OptimizeOnServerUseCase;
use Kilobyte\ImageOptimizer\Application\PurgeBackupsUseCase;
use Kilobyte\ImageOptimizer\Application\RegenerateThumbnailsUseCase;
use Kilobyte\ImageOptimizer\Application\RestoreAttachmentUseCase;
use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\SettingsRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\ImageFormat;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpRasterImage;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;
use Kilobyte\ImageOptimizer\Presentation\OperationErrorMessage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes REST routes under kilobyte-io/v1.
 */
final class OptimizerRestController {

	public const NAMESPACE = 'kilobyte-io/v1';

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepositoryInterface
	 */
	private AttachmentRepositoryInterface $attachments;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepositoryInterface
	 */
	private SettingsRepositoryInterface $settings;

	/**
	 * Browser optimization use case.
	 *
	 * @var OptimizeFromBrowserUseCase
	 */
	private OptimizeFromBrowserUseCase $optimize_browser;

	/**
	 * Server optimization use case.
	 *
	 * @var OptimizeOnServerUseCase
	 */
	private OptimizeOnServerUseCase $optimize_server;

	/**
	 * Restore use case.
	 *
	 * @var RestoreAttachmentUseCase
	 */
	private RestoreAttachmentUseCase $restore;

	/**
	 * Thumbnail regeneration use case.
	 *
	 * @var RegenerateThumbnailsUseCase
	 */
	private RegenerateThumbnailsUseCase $regenerate;

	/**
	 * Skip/already-optimal use case.
	 *
	 * @var MarkAlreadyOptimalUseCase
	 */
	private MarkAlreadyOptimalUseCase $mark_optimal;

	/**
	 * Purge backups use case.
	 *
	 * @var PurgeBackupsUseCase
	 */
	private PurgeBackupsUseCase $purge_backups;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepositoryInterface $attachments      Attachment repository.
	 * @param SettingsRepositoryInterface   $settings         Settings repository.
	 * @param OptimizeFromBrowserUseCase    $optimize_browser Browser optimization use case.
	 * @param OptimizeOnServerUseCase       $optimize_server  Server optimization use case.
	 * @param RestoreAttachmentUseCase      $restore          Restore use case.
	 * @param RegenerateThumbnailsUseCase   $regenerate       Regenerate thumbnails use case.
	 * @param MarkAlreadyOptimalUseCase     $mark_optimal     Mark already optimal use case.
	 * @param PurgeBackupsUseCase           $purge_backups    Purge backups use case.
	 */
	public function __construct(
		AttachmentRepositoryInterface $attachments,
		SettingsRepositoryInterface $settings,
		OptimizeFromBrowserUseCase $optimize_browser,
		OptimizeOnServerUseCase $optimize_server,
		RestoreAttachmentUseCase $restore,
		RegenerateThumbnailsUseCase $regenerate,
		MarkAlreadyOptimalUseCase $mark_optimal,
		PurgeBackupsUseCase $purge_backups
	) {
		$this->attachments      = $attachments;
		$this->settings         = $settings;
		$this->optimize_browser = $optimize_browser;
		$this->optimize_server  = $optimize_server;
		$this->restore          = $restore;
		$this->regenerate       = $regenerate;
		$this->mark_optimal     = $mark_optimal;
		$this->purge_backups    = $purge_backups;
	}

	/**
	 * Hook REST API route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'can_upload' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_queue' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'args'                => array(
					'mode'     => array(
						'type'    => 'string',
						'default' => 'optimize',
						'enum'    => array( 'optimize', 'regenerate', 'restore' ),
					),
					'before'   => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimize/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize_from_browser' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/optimize-server/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize_on_server' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/skip/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_skipped' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/restore/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_original' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/regenerate/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate_thumbnails' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'purge_backups' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback for per-attachment mutations.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function can_edit_attachment( WP_REST_Request $request ): bool {
		$attachment_id = absint( $request->get_url_params()['id'] ?? 0 );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Permission callback for media read actions.
	 *
	 * @return bool
	 */
	public function can_upload(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Permission callback for settings updates.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return library optimization statistics.
	 *
	 * @return WP_REST_Response
	 */
	public function get_stats(): WP_REST_Response {
		return rest_ensure_response( $this->attachments->get_library_stats() );
	}

	/**
	 * Return paginated queue page.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_queue( WP_REST_Request $request ): WP_REST_Response {
		$mode     = (string) $request->get_param( 'mode' );
		$before   = absint( $request->get_param( 'before' ) );
		$per_page = absint( $request->get_param( 'per_page' ) );

		return rest_ensure_response(
			$this->attachments->get_queue_page( $mode, $before, $per_page )
		);
	}

	/**
	 * Handle browser-compressed image upload.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function optimize_from_browser( WP_REST_Request $request ) {
		$attachment_id = absint( $request->get_url_params()['id'] ?? 0 );
		$files         = $request->get_file_params();
		$file          = $files['file'] ?? array();

		if (
			! is_array( $file )
			|| ! is_string( $file['tmp_name'] ?? null )
			|| ! is_string( $file['name'] ?? null )
			|| ! is_uploaded_file( $file['tmp_name'] )
		) {
			return new WP_Error(
				'kilobyte_io_missing_file',
				__( 'No optimized image file received.', 'kilobyte-image-optimizer' ),
				array( 'status' => 400 )
			);
		}

		$tmp_path     = $file['tmp_name'];
		$upload_error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			return new WP_Error(
				'kilobyte_io_upload_error',
				__( 'Image upload did not complete cleanly.', 'kilobyte-image-optimizer' ),
				array( 'status' => 400 )
			);
		}

		$uploaded_name = sanitize_file_name( $file['name'] );
		$checked       = wp_check_filetype_and_ext( $tmp_path, $uploaded_name );
		$detected_mime = ! empty( $checked['type'] ) ? (string) $checked['type'] : '';

		// Enforce WordPress allowed upload MIME policy (no raw mime_content_type bypass).
		if ( '' === $detected_mime || ! ImageFormat::is_allowed_output_mime( $detected_mime ) ) {
			return new WP_Error(
				'kilobyte_io_invalid_mime',
				__( 'Uploaded file is not an allowed image format on this site.', 'kilobyte-image-optimizer' ),
				array( 'status' => 400 )
			);
		}

		if ( ! WpRasterImage::is_bounded( $tmp_path, $detected_mime, true )
			|| is_wp_error( wp_get_image_editor( $tmp_path, array( 'mime_type' => $detected_mime ) ) )
		) {
			return new WP_Error(
				'kilobyte_io_invalid_image',
				__( 'This image is damaged or too large for the server to process.', 'kilobyte-image-optimizer' ),
				array( 'status' => 422 )
			);
		}

		$result = $this->optimize_browser->execute( $attachment_id, $tmp_path, $detected_mime, (int) wp_filesize( $tmp_path ) );

		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Handle server-side fallback optimization.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function optimize_on_server( WP_REST_Request $request ) {
		$result = $this->optimize_server->execute( absint( $request->get_url_params()['id'] ?? 0 ) );
		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Mark attachment as already optimal.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_skipped( WP_REST_Request $request ) {
		$result = $this->mark_optimal->execute( absint( $request->get_url_params()['id'] ?? 0 ) );
		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Restore original image from backup.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_original( WP_REST_Request $request ) {
		$result = $this->restore->execute( absint( $request->get_url_params()['id'] ?? 0 ) );
		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Regenerate thumbnails for an attachment.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function regenerate_thumbnails( WP_REST_Request $request ) {
		$result = $this->regenerate->execute( absint( $request->get_url_params()['id'] ?? 0 ) );
		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Clear stale references after backed-up originals have been recovered.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge_backups() {
		$result = $this->purge_backups->execute();
		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Save settings via REST.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'kilobyte_io_invalid_settings', __( 'Could not read these preferences.', 'kilobyte-image-optimizer' ), array( 'status' => 400 ) );
		}

		$settings = OptimizationSettings::from_array( array_replace( $this->settings->load()->to_array(), $params ) );
		$this->settings->save( $settings );

		return rest_ensure_response( $this->settings->load()->to_array() );
	}
}
