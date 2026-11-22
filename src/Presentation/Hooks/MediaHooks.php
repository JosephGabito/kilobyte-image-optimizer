<?php
/**
 * Media Library hooks, upload interceptor enqueue, Safari/server fallback, alt-text, and CWV hints.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation\Hooks;

use Kilobyte\ImageOptimizer\Application\OptimizeOnServerUseCase;
use Kilobyte\ImageOptimizer\Domain\Policy\AltTextPolicy;
use Kilobyte\ImageOptimizer\Domain\Policy\CompressionPolicy;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;
use Kilobyte\ImageOptimizer\Presentation\OperationErrorMessage;
use Kilobyte\ImageOptimizer\Presentation\Admin\OptimizerAdminPage;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpRasterImage;
use Kilobyte\ImageOptimizer\Domain\Repository\SettingsRepositoryInterface;

/**
 * Integrates Kilobyte with WordPress Media Library and frontend image rendering.
 */
final class MediaHooks {

	private const NATIVE_PENDING = '_kilobyte_io_native_pending';
	private const AUTO_FAILURE   = '_kilobyte_io_auto_failure';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepositoryInterface
	 */
	private SettingsRepositoryInterface $settings;

	/**
	 * Attachment repository.
	 *
	 * @var WpAttachmentRepository
	 */
	private WpAttachmentRepository $attachments;

	/** @var WpVariantRetirement */
	private WpVariantRetirement $retirement;

	/** @var array<string, array<string, mixed>> */
	private array $pending_deletions = array();

	/**
	 * Server fallback use case.
	 *
	 * @var OptimizeOnServerUseCase
	 */
	private OptimizeOnServerUseCase $optimize_server;

	/**
	 * Alt text policy.
	 *
	 * @var AltTextPolicy
	 */
	private AltTextPolicy $alt_text_policy;

	/**
	 * Compression policy.
	 *
	 * @var CompressionPolicy
	 */
	private CompressionPolicy $compression_policy;

	/**
	 * Plugin base URL.
	 *
	 * @var string
	 */
	private string $plugin_url;

	/**
	 * Plugin base directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Whether LCP fetchpriority has been applied on this request.
	 *
	 * @var bool
	 */
	private bool $lcp_applied = false;

	/**
	 * Track brand-new upload IDs seen in add_attachment during this request.
	 * Prevents thumbnail regeneration on existing attachments from entering the upload conversion path.
	 *
	 * @var array<int, bool>
	 */
	private array $new_upload_ids = array();

	/** @var array<int, string> */
	private array $browser_managed_uploads = array();

	/**
	 * Constructor.
	 *
	 * @param SettingsRepositoryInterface   $settings           Settings repository.
	 * @param WpVariantRetirement            $retirement         Retired variant storage.
	 * @param WpAttachmentRepository $attachments        Attachment repository.
	 * @param OptimizeOnServerUseCase       $optimize_server    Server optimization use case.
	 * @param AltTextPolicy                 $alt_text_policy    Alt text policy.
	 * @param CompressionPolicy             $compression_policy Compression policy.
	 * @param string                        $plugin_url         Plugin URL.
	 * @param string                        $plugin_dir         Plugin directory path.
	 * @param string                        $version            Plugin version.
	 */
	public function __construct(
		SettingsRepositoryInterface $settings,
		WpAttachmentRepository $attachments,
		WpVariantRetirement $retirement,
		OptimizeOnServerUseCase $optimize_server,
		AltTextPolicy $alt_text_policy,
		CompressionPolicy $compression_policy,
		string $plugin_url,
		string $plugin_dir,
		string $version
	) {
		$this->settings           = $settings;
		$this->attachments        = $attachments;
		$this->retirement         = $retirement;
		$this->optimize_server    = $optimize_server;
		$this->alt_text_policy    = $alt_text_policy;
		$this->compression_policy = $compression_policy;
		$this->plugin_url         = $plugin_url;
		$this->plugin_dir         = $plugin_dir;
		$this->version            = $version;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_upload_interceptor' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media_modal_assets' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'enter_core_upload' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'leave_core_upload' ), 10, 3 );
		add_action( 'add_attachment', array( $this, 'maybe_set_auto_alt_text' ) );
		add_filter( 'pre_delete_attachment', array( $this, 'before_delete_attachment' ), PHP_INT_MAX, 2 );
		add_action( 'deleted_post', array( $this, 'after_delete_attachment' ), 10, 2 );
		add_action( 'kilobyte_io_retry_variant_cleanup', array( $this, 'retry_variant_cleanup' ), 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'maybe_server_fallback_on_upload' ), 20, 3 );
		add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_modal_field' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'enhance_image_attributes' ), 10, 1 );
	}

	/**
	 * Enqueue browser-side upload compressor on media/editor screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_upload_interceptor( string $hook_suffix ): void {
		$upload_screens = array(
			'upload.php',
			'media-new.php',
			'post.php',
			'post-new.php',
			'site-editor.php',
			'media_page_' . OptimizerAdminPage::PAGE_SLUG,
		);

		if ( ! in_array( $hook_suffix, $upload_screens, true ) ) {
			return;
		}

		$this->enqueue_media_modal_assets();
	}

	/**
	 * Enqueue media modal restore handler and upload interceptor whenever WordPress loads the Media Library modal in admin.
	 *
	 * @return void
	 */
	public function enqueue_media_modal_assets(): void {
		if ( ! is_admin() || ! current_user_can( 'upload_files' ) || wp_script_is( 'kilobyte-io-upload-interceptor', 'enqueued' ) ) {
			return;
		}

		$asset_path = $this->plugin_dir . 'build/upload-interceptor.asset.php';
		$asset      = is_readable( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->version,
			);

		wp_enqueue_script(
			'kilobyte-io-upload-interceptor',
			$this->plugin_url . 'build/upload-interceptor.js',
			is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
			(string) $asset['version'],
			true
		);

		wp_set_script_translations( 'kilobyte-io-upload-interceptor', 'kilobyte-image-optimizer' );
		wp_localize_script(
			'kilobyte-io-upload-interceptor',
			'KilobyteIOUploadConfig',
			array(
				'settings'     => $this->settings->load()->to_array(),
				'workerUrl'    => esc_url_raw( $this->plugin_url . 'build/image-worker.js?ver=' . $this->version ),
				'mediaUrl'     => esc_url_raw( rest_url( 'wp/v2/media' ) ),
				'outputMimes'  => WpRasterImage::output_mimes(),
				'optimizerUrl' => admin_url( 'upload.php?page=' . OptimizerAdminPage::PAGE_SLUG ),
			)
		);
	}

	/**
	 * Capture cleanup information before core removes metadata. Perform no file operations here.
	 *
	 * @param mixed    $delete Existing short-circuit result.
	 * @param \WP_Post $post   Attachment.
	 * @return mixed
	 */
	public function before_delete_attachment( $delete, \WP_Post $post ) {
		if ( null !== $delete || ! wp_attachment_is_image( $post ) ) {
			return $delete;
		}

		$id      = $post->ID;
		$key     = get_current_blog_id() . ':' . $id;
		$release = $this->attachments->acquire_attachment_lock( $id );
		if ( null === $release ) {
			return false;
		}

		$record = $this->attachments->get_record( $id );
		$paths  = array_keys( $this->retirement->pending( $id ) );
		if ( null !== $record && $record->is_sibling() && $record->variants() ) {
			$paths = array_merge( $paths, array_values( $this->attachments->variant_paths( $id, $record ) ) );
		}
		$this->pending_deletions[ $key ] = array(
			'paths'   => array_values( array_unique( array_filter( $paths, array( $this->attachments, 'is_owned_variant_path' ) ) ) ),
			'record'  => $record,
			'release' => $release,
		);
		return $delete;
	}

	/**
	 * Remove plugin files only after core confirms the post was deleted.
	 *
	 * @param int      $id   Post ID.
	 * @param \WP_Post $post Deleted post.
	 * @return void
	 */
	public function after_delete_attachment( int $id, \WP_Post $post ): void {
		$key     = get_current_blog_id() . ':' . $id;
		$pending = $this->pending_deletions[ $key ] ?? null;
		if ( null === $pending ) {
			return;
		}

		unset( $this->pending_deletions[ $key ] );
		try {
			$record = $pending['record'];
			$this->retry_variant_cleanup( $pending['paths'], 0 );
			if ( null !== $record ) {
				if ( ! $this->attachments->remove_deleted_record( $record ) ) {
					update_option( WpAttachmentRepository::RECONCILE_OPTION, true, false );
				}
			}
			clean_post_cache( $post );
			do_action( 'kilobyte_io_variant_changed', $id );
		} finally {
			$pending['release']();
		}
	}

	/**
	 * Retry removal if a file was unavailable during permanent deletion.
	 *
	 * @param string[] $paths   Recorded file paths.
	 * @param int      $attempt Retry count.
	 * @return void
	 */
	public function retry_variant_cleanup( array $paths, int $attempt ): void {
		if ( ! empty( $paths ) && ! $this->attachments->delete_variant_files( $paths ) && $attempt < 3 ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'kilobyte_io_retry_variant_cleanup', array( $paths, $attempt + 1 ) );
		}
	}

	/**
	 * Track uploads whose browser will handle image processing.
	 *
	 * @param mixed            $response Existing response.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function enter_core_upload( $response, array $handler, \WP_REST_Request $request ) {
		if ( '/wp/v2/media' === untrailingslashit( $request->get_route() ) && 'POST' === $request->get_method() ) {
			if ( false === $request->get_param( 'generate_sub_sizes' ) && function_exists( 'wp_is_client_side_media_processing_enabled' ) && wp_is_client_side_media_processing_enabled() ) {
				$this->browser_managed_uploads[ spl_object_id( $request ) ] = 'native';
			} elseif ( '1' === $request->get_header( 'X-Kilobyte-Browser-Optimize' ) ) {
				$this->browser_managed_uploads[ spl_object_id( $request ) ] = 'kilobyte';
			}
		}
		return $response;
	}

	/**
	 * End the upload-specific REST context.
	 *
	 * @param mixed            $response Existing response.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function leave_core_upload( $response, array $handler, \WP_REST_Request $request ) {
		$key  = spl_object_id( $request );
		$mode = $this->browser_managed_uploads[ $key ] ?? '';
		unset( $this->browser_managed_uploads[ $key ] );

		if ( ! $response instanceof \WP_REST_Response || $response->get_status() >= 400 ) {
			return $response;
		}

		if ( 'native' === $mode && $this->settings->load()->is_enabled() ) {
			$data = $response->get_data();
			$id   = (int) ( $data['id'] ?? 0 );
			if ( $id > 0 && wp_attachment_is_image( $id ) ) {
				update_post_meta( $id, self::NATIVE_PENDING, true );
			}
		} elseif ( 'POST' === $request->get_method() && preg_match( '~^/wp/v2/media/([0-9]+)/finalize/?$~D', $request->get_route(), $match ) ) {
			$id = (int) $match[1];
			if ( get_post_meta( $id, self::NATIVE_PENDING, true ) ) {
				delete_post_meta( $id, self::NATIVE_PENDING );
				if ( $this->settings->load()->is_enabled() ) {
					$this->optimize_upload( $id );
				}
			}
		}
		return $response;
	}

	/**
	 * Track newly uploaded attachments and populate clean alt text from filename when empty.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function maybe_set_auto_alt_text( int $attachment_id ): void {
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$this->new_upload_ids[ get_current_blog_id() . ':' . $attachment_id ] = true;
		}

		if ( ! $this->settings->load()->auto_alt_text() || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( (string) $existing ) ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || '' === $file ) {
			return;
		}

		$alt = $this->alt_text_policy->from_filename( $file );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
	}

	/**
	 * Automatic server-side fallback for brand-new Safari, mobile app, or REST uploads only.
	 *
	 * Never runs on existing attachments during thumbnail regeneration or repair.
	 *

	 * @param array<string, mixed> $metadata      Generated attachment metadata.
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $context       Core metadata operation.
	 * @return array<string, mixed>
	 */
	public function maybe_server_fallback_on_upload( array $metadata, int $attachment_id, string $context = 'create' ): array {
		if ( empty( $this->new_upload_ids[ get_current_blog_id() . ':' . $attachment_id ] ) ) {
			return $metadata;
		}
		unset( $this->new_upload_ids[ get_current_blog_id() . ':' . $attachment_id ] );

		$settings = $this->settings->load();
		$mime     = (string) get_post_mime_type( $attachment_id );
		if ( empty( $this->browser_managed_uploads ) && 'create' === $context && $settings->is_enabled() && $this->compression_policy->is_supported_source_mime( $mime ) ) {
			$this->optimize_upload( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Optimize a newly saved copy and remember failures for the Media Library.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function optimize_upload( int $attachment_id ): void {
		$result = $this->optimize_server->execute( $attachment_id );
		if ( $result instanceof OperationFailure ) {
			update_post_meta( $attachment_id, self::AUTO_FAILURE, $result->reason() );
		}
	}

	/**
	 * Show a retry action when the original uploaded but the copy could not be made.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function upload_status( int $attachment_id ): string {
		$reason = get_post_meta( $attachment_id, self::AUTO_FAILURE, true );
		if ( ! is_string( $reason ) || '' === $reason ) {
			return esc_html__( 'Not optimized', 'kilobyte-image-optimizer' );
		}
		$error = OperationErrorMessage::to_wp_error( new OperationFailure( $reason ) );
		return sprintf(
			'%s <a href="%s">%s</a><details><summary>%s</summary>%s</details>',
			esc_html__( 'Original saved. The smaller copy needs another try.', 'kilobyte-image-optimizer' ),
			esc_url( admin_url( 'upload.php?page=' . OptimizerAdminPage::PAGE_SLUG ) ),
			esc_html__( 'Open image optimization', 'kilobyte-image-optimizer' ),
			esc_html__( 'Technical details', 'kilobyte-image-optimizer' ),
			esc_html( $error->get_error_message() )
		);
	}

	/**
	 * Add Kilobyte status column to Media Library list view.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_media_column( array $columns ): array {
		$columns['kilobyte_io'] = __( 'Optimization', 'kilobyte-image-optimizer' );
		return $columns;
	}

	/**
	 * Render Kilobyte status cell in Media Library list view.
	 *
	 * @param string $column_name Column slug.
	 * @param int    $post_id     Attachment ID.
	 * @return void
	 */
	public function render_media_column( string $column_name, int $post_id ): void {
		if ( 'kilobyte_io' !== $column_name ) {
			return;
		}

		if ( ! wp_attachment_is_image( $post_id ) ) {
			echo '<span style="color:#646970;">-</span>';
			return;
		}

		$record = $this->attachments->get_record( $post_id );
		if ( null === $record ) {
			printf(
				'<span style="color:#646970;">%s</span>',
				wp_kses_post( $this->upload_status( $post_id ) )
			);
			return;
		}

		if ( $record->is_already_optimal() ) {
			printf(
				'<span style="color:#2271b1;font-weight:500;">%s</span>',
				esc_html__( 'Already optimal', 'kilobyte-image-optimizer' )
			);
			return;
		}

		printf(
			'<div><strong style="color:#007017;">-%s%%</strong> <span style="color:#50575e;">(%s)</span></div><div style="font-size:12px;color:#646970;">%s &rarr; %s</div>',
			esc_html( (string) $record->saved_percent() ),
			esc_html( strtoupper( $record->format() ) ),
			esc_html( (string) size_format( $record->original_size(), 1 ) ),
			esc_html( (string) size_format( $record->optimized_size(), 1 ) )
		);

		if ( $record->is_restorable() && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $post_id ) ) {
			printf(
				'<div style="margin-top:4px;"><button type="button" class="button-link kilobyte-restore-btn" data-id="%d" style="color:#b32d2e;font-size:12px;">%s</button></div>',
				(int) $post_id,
				esc_html__( 'Restore original', 'kilobyte-image-optimizer' )
			);
		}
	}

	/**
	 * Show optimization status and restore action in the Media Library Grid View modal.
	 *
	 * @param array<string, mixed> $form_fields Existing attachment modal fields.
	 * @param \WP_Post             $post        Attachment post object.
	 * @return array<string, mixed>
	 */
	public function add_attachment_modal_field( array $form_fields, \WP_Post $post ): array {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$record = $this->attachments->get_record( $post->ID );
		if ( null === $record ) {
			$html = $this->upload_status( $post->ID );
		} elseif ( $record->is_already_optimal() ) {
			$html = esc_html__( 'Already optimal', 'kilobyte-image-optimizer' );
		} else {
			$html = sprintf(
				'<strong>-%s%%</strong> (%s) &middot; %s &rarr; %s',
				esc_html( (string) $record->saved_percent() ),
				esc_html( strtoupper( $record->format() ) ),
				esc_html( (string) size_format( $record->original_size(), 1 ) ),
				esc_html( (string) size_format( $record->optimized_size(), 1 ) )
			);
			if ( $record->is_restorable() && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $post->ID ) ) {
				$html .= sprintf(
					' <button type="button" class="button-link kilobyte-restore-btn" data-id="%d">%s</button>',
					(int) $post->ID,
					esc_html__( 'Restore original', 'kilobyte-image-optimizer' )
				);
			}
		}

		$form_fields['kilobyte_io'] = array(
			'label' => __( 'Optimization', 'kilobyte-image-optimizer' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}

	/**
	 * Apply lightweight Core Web Vitals hints on frontend images.
	 *
	 * @param array<string, string> $attr Image attributes.
	 * @return array<string, string>
	 */
	public function enhance_image_attributes( array $attr ): array {
		if ( is_admin() || ! $this->settings->load()->cwv_enhancements() ) {
			return $attr;
		}

		if ( empty( $attr['decoding'] ) ) {
			$attr['decoding'] = 'async';
		}

		if ( ! $this->lcp_applied && is_singular() && in_the_loop() ) {
			$attr['fetchpriority'] = 'high';
			unset( $attr['loading'] );
			$this->lcp_applied = true;
		}

		return $attr;
	}
}
