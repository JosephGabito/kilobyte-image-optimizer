<?php
/**
 * Media Library hooks, upload interceptor enqueue, Safari/server fallback, alt-text, and CWV hints.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation\Hooks;

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
	 * Background upload optimization.
	 *
	 * @var AutomaticUploadOptimization
	 */
	private AutomaticUploadOptimization $automatic_uploads;

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
	 * @param AutomaticUploadOptimization   $automatic_uploads Deferred upload work.
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
		AutomaticUploadOptimization $automatic_uploads,
		AltTextPolicy $alt_text_policy,
		CompressionPolicy $compression_policy,
		string $plugin_url,
		string $plugin_dir,
		string $version
	) {
		$this->settings           = $settings;
		$this->attachments        = $attachments;
		$this->retirement         = $retirement;
		$this->automatic_uploads  = $automatic_uploads;
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
		add_action( 'rest_after_insert_attachment', array( $this, 'record_native_upload' ), 10, 3 );
		add_action( 'add_attachment', array( $this, 'maybe_set_auto_alt_text' ) );
		add_action( 'added_post_meta', array( $this, 'maybe_reset_image_check' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'maybe_reset_image_check' ), 10, 3 );
		add_filter( 'pre_delete_attachment', array( $this, 'before_delete_attachment' ), PHP_INT_MAX, 2 );
		add_action( 'deleted_post', array( $this, 'after_delete_attachment' ), 10, 2 );
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

		$id        = $post->ID;
		$key       = get_current_blog_id() . ':' . $id;
		$available = null;
		$release   = $this->attachments->acquire_attachment_lock( $id, $available );
		if ( null === $release ) {
			if ( $available ) {
				return false;
			}
			// Allow Core deletion when the database cannot provide a lock. Leave all plugin files alone.
			if ( null !== $this->attachments->get_record( $id ) ) {
				update_option( WpAttachmentRepository::RECONCILE_OPTION, true, false );
			}
			return $delete;
		}

		$record = $this->attachments->get_record( $id );
		$paths  = array_keys( $this->retirement->pending( $id ) );
		if ( null !== $record && $record->is_sibling() && $record->variants() ) {
			$paths = array_merge( $paths, array_values( $this->attachments->variant_paths( $id, $record ) ) );
		}
		if ( $paths ) {
			$paths = array_diff( $paths, array_keys( $this->attachments->attachment_image_paths( $id ) ) );
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
			WpVariantRetirement::schedule_removed_files( $pending['paths'] );
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
		unset( $this->browser_managed_uploads[ spl_object_id( $request ) ] );

		if ( $response instanceof \WP_REST_Response && $response->get_status() < 400 && 'POST' === $request->get_method()
			&& preg_match( '~^/wp/v2/media/([0-9]+)/finalize/?$~D', $request->get_route(), $match )
		) {
			$this->automatic_uploads->enqueue( (int) $match[1], true );
		}
		return $response;
	}

	/**
	 * Mark native uploads using the attachment ID even when REST filters response fields.
	 *
	 * @param \WP_Post         $attachment The new attachment.
	 * @param \WP_REST_Request $request    Upload request.
	 * @param bool             $creating   Whether WordPress is creating the attachment.
	 * @return void
	 */
	public function record_native_upload( \WP_Post $attachment, \WP_REST_Request $request, bool $creating ): void {
		try {
			if ( $creating && 'native' === ( $this->browser_managed_uploads[ spl_object_id( $request ) ] ?? '' )
				&& $this->settings->load()->is_enabled()
				&& $this->compression_policy->is_supported_source_mime( $attachment->post_mime_type )
			) {
				update_post_meta( $attachment->ID, WpAttachmentRepository::NATIVE_PENDING, true );
			}
		} catch ( \Throwable $error ) {
			// Keep the original upload available if a third-party hook interrupts optional optimization.
			return;
		}
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
		if ( 'create' === $context && $settings->is_enabled() && $this->compression_policy->is_supported_source_mime( $mime )
			&& ! in_array( 'native', $this->browser_managed_uploads, true )
		) {
			// Give the browser time to finish. If the tab closes, WordPress can still make a copy.
			$delay = in_array( 'kilobyte', $this->browser_managed_uploads, true ) ? 5 * MINUTE_IN_SECONDS : 10;
			$this->automatic_uploads->enqueue( $attachment_id, false, $delay );
		}

		return $metadata;
	}

	/**
	 * Queue a previously checked attachment again if WordPress has replaced its source.
	 *
	 * @param int    $meta_id       Metadata row ID.
	 * @param int    $attachment_id Post ID.
	 * @param string $key           Metadata key.
	 * @return void
	 */
	public function maybe_reset_image_check( int $meta_id, int $attachment_id, string $key ): void {
		if ( ! in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) {
			return;
		}
		$record = $this->attachments->get_record( $attachment_id );
		if ( null === $record || ! $record->is_unchanged() || '' === $record->source_revision() ) {
			return;
		}
		$image = $this->attachments->find_image( $attachment_id, true );
		if ( $record->source_revision() === ( $image['source_revision'] ?? '' ) ) {
			return;
		}
		$this->attachments->with_attachment_lock(
			$attachment_id,
			function () use ( $attachment_id, $record ): void {
				$current = $this->attachments->get_record( $attachment_id );
				if ( null !== $current && $record->to_array() === $current->to_array() ) {
					$this->attachments->delete_record( $attachment_id );
				}
			}
		);
	}

	/**
	 * Show a retry action when the original uploaded but the copy could not be made.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function upload_status( int $attachment_id ): string {
		$status = $this->automatic_uploads->status( $attachment_id );
		$reason = $status['failure'];
		if ( '' === $reason ) {
			if ( $status['pending'] ) {
				return sprintf(
					'%s <a href="%s">%s</a>',
					esc_html__( 'Original saved. Optimization is queued.', 'kilobyte-image-optimizer' ),
					esc_url( admin_url( 'upload.php?page=' . OptimizerAdminPage::PAGE_SLUG ) ),
					esc_html__( 'Optimize now in Media', 'kilobyte-image-optimizer' )
				);
			}
			return '' !== $status['skip']
				? esc_html( OperationErrorMessage::describe_skip( $status['skip'] ) )
				: esc_html__( 'Not optimized', 'kilobyte-image-optimizer' );
		}
		$error = OperationErrorMessage::to_wp_error( new OperationFailure( $reason ) );
		return sprintf(
			'%s %s <a href="%s">%s</a>',
			esc_html__( 'Original saved.', 'kilobyte-image-optimizer' ),
			esc_html( $error->get_error_message() ),
			esc_url( admin_url( 'upload.php?page=' . OptimizerAdminPage::PAGE_SLUG ) ),
			esc_html__( 'Open image optimization', 'kilobyte-image-optimizer' )
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

		if ( $record->is_unchanged() ) {
			printf(
				'<span style="color:#2271b1;font-weight:500;">%s</span>',
				esc_html( OperationErrorMessage::describe_skip( $record->status() ) )
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
		} elseif ( $record->is_unchanged() ) {
			$html = esc_html( OperationErrorMessage::describe_skip( $record->status() ) );
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

		if ( ! array_key_exists( 'decoding', $attr ) ) {
			$attr['decoding'] = 'async';
		}

		return $attr;
	}
}
