<?php
/**
 * WordPress Abilities API adapter for Kilobyte Image Optimizer.
 *
 * Exposes read and action abilities for WordPress 6.9+ / 7.x, MCP adapters, and AI agents
 * while remaining a no-op on WordPress 6.6 through 6.8.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation\Abilities;

use Kilobyte\ImageOptimizer\Application\OptimizeOnServerUseCase;
use Kilobyte\ImageOptimizer\Application\RegenerateThumbnailsUseCase;
use Kilobyte\ImageOptimizer\Application\RestoreAttachmentUseCase;
use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\SettingsRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\ImageFormat;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;
use Kilobyte\ImageOptimizer\Presentation\OperationErrorMessage;
use WP_Error;

/**
 * Registers Kilobyte abilities and category with the WordPress Abilities API.
 */
final class OptimizerAbilities {

	public const CATEGORY = 'kilobyte-image-optimizer';

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
	 * Constructor.
	 *
	 * @param AttachmentRepositoryInterface $attachments     Attachment repository.
	 * @param SettingsRepositoryInterface   $settings        Settings repository.
	 * @param OptimizeOnServerUseCase       $optimize_server Server optimization use case.
	 * @param RestoreAttachmentUseCase      $restore         Restore use case.
	 * @param RegenerateThumbnailsUseCase   $regenerate      Regenerate thumbnails use case.
	 */
	public function __construct(
		AttachmentRepositoryInterface $attachments,
		SettingsRepositoryInterface $settings,
		OptimizeOnServerUseCase $optimize_server,
		RestoreAttachmentUseCase $restore,
		RegenerateThumbnailsUseCase $regenerate
	) {
		$this->attachments     = $attachments;
		$this->settings        = $settings;
		$this->optimize_server = $optimize_server;
		$this->restore         = $restore;
		$this->regenerate      = $regenerate;
	}

	/**
	 * Hook into the WordPress Abilities API lifecycle when available.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the Kilobyte ability category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Image optimization', 'kilobyte-image-optimizer' ),
				'description' => __( 'Inspect Media Library optimization totals, manage preferences, optimize or restore images, and regenerate thumbnails with Kilobyte.', 'kilobyte-image-optimizer' ),
			)
		);
	}

	/**
	 * Register Kilobyte abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_ability(
			'kilobyte/get-stats',
			array(
				'label'               => __( 'Get image optimization stats', 'kilobyte-image-optimizer' ),
				'description'         => __( 'Return Media Library image optimization totals, estimated transfer savings, restorable counts, and legacy backup totals.', 'kilobyte-image-optimizer' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_images'     => array( 'type' => 'integer' ),
						'optimized_count'  => array( 'type' => 'integer' ),
						'remaining_count'  => array( 'type' => 'integer' ),
						'total_original'   => array( 'type' => 'integer' ),
						'total_optimized'  => array( 'type' => 'integer' ),
						'total_saved'      => array( 'type' => 'integer' ),
						'saved_percent'    => array( 'type' => 'number' ),
						'backup_count'     => array( 'type' => 'integer' ),
						'backup_bytes'     => array( 'type' => 'integer' ),
						'restorable_count' => array( 'type' => 'integer' ),
						'needs_reconcile'  => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'total_images', 'optimized_count', 'remaining_count', 'total_original', 'total_optimized', 'total_saved', 'saved_percent', 'restorable_count' ),
				),
				'meta'                => $this->ability_meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);

		$this->register_ability(
			'kilobyte/get-queue',
			array(
				'label'               => __( 'List image optimization queue', 'kilobyte-image-optimizer' ),
				'description'         => __( 'Return a paginated batch of Media Library images eligible for optimization, thumbnail regeneration, or restore.', 'kilobyte-image-optimizer' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'get_queue' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'input_schema'        => $this->queue_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'items'             => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'        => array( 'type' => 'integer' ),
									'title'     => array( 'type' => 'string' ),
									'filename'  => array( 'type' => 'string' ),
									'url'       => array( 'type' => 'string' ),
									'mime_type' => array( 'type' => 'string' ),
									'file_size' => array( 'type' => 'integer' ),
									'width'     => array( 'type' => 'integer' ),
									'height'    => array( 'type' => 'integer' ),
								),
							),
						),
						'unavailable_items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array( 'type' => 'integer' ),
									'filename' => array( 'type' => 'string' ),
									'title'    => array( 'type' => 'string' ),
									'edit_url' => array( 'type' => 'string' ),
								),
							),
						),
						'skipped_missing'   => array( 'type' => 'integer' ),
						'next_before'       => array( 'type' => 'integer' ),
					),
					'required'   => array( 'items', 'unavailable_items', 'skipped_missing', 'next_before' ),
				),
				'meta'                => $this->ability_meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);

		$this->register_ability(
			'kilobyte/get-settings',
			array(
				'label'               => __( 'Get image optimization settings', 'kilobyte-image-optimizer' ),
				'description'         => __( 'Return the active Kilobyte image optimization preferences, including any filter overrides.', 'kilobyte-image-optimizer' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'output_schema'       => $this->settings_output_schema(),
				'meta'                => $this->ability_meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);

		$this->register_ability(
			'kilobyte/update-settings',
			array(
				'label'               => __( 'Update image optimization settings', 'kilobyte-image-optimizer' ),
				'description'         => __( 'Update one or more Kilobyte image optimization preferences and return the active settings.', 'kilobyte-image-optimizer' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'input_schema'        => $this->settings_input_schema(),
				'output_schema'       => $this->settings_output_schema(),
				'meta'                => $this->ability_meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);

		$this->register_ability(
			'kilobyte/optimize-image',
			array(
				'label'               => __( 'Optimize image', 'kilobyte-image-optimizer' ),
				'description'         => __( 'Create an optimized WebP, AVIF, or recompressed copy for a Media Library image while keeping the original file and existing URLs intact.', 'kilobyte-image-optimizer' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'optimize_image' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'input_schema'        => $this->attachment_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array( 'type' => 'integer' ),
						'skipped'        => array( 'type' => 'boolean' ),
						'status'         => array( 'type' => 'string' ),
						'original_size'  => array( 'type' => 'integer' ),
						'optimized_size' => array( 'type' => 'integer' ),
						'saved_bytes'    => array( 'type' => 'integer' ),
						'saved_percent'  => array( 'type' => 'number' ),
						'format'         => array( 'type' => 'string' ),
						'delivery_mode'  => array( 'type' => 'string' ),
						'has_backup'     => array( 'type' => 'boolean' ),
						'engine'         => array( 'type' => 'string' ),
					),
					'required'   => array( 'id', 'skipped' ),
				),
				'meta'                => $this->ability_meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);

		$this->register_ability(
			'kilobyte/restore-image',
			array(
				'label'               => __( 'Restore original image', 'kilobyte-image-optimizer' ),
				'description'         => __( 'Switch frontend delivery for a Media Library image back to its original file and schedule cache-safe cleanup of retired copies.', 'kilobyte-image-optimizer' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'restore_image' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'input_schema'        => $this->attachment_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'               => array( 'type' => 'integer' ),
						'restored'         => array( 'type' => 'boolean' ),
						'mode'             => array( 'type' => 'string' ),
						'copy_retained'    => array( 'type' => 'boolean' ),
						'backup_removed'   => array( 'type' => 'boolean' ),
						'thumbnails_ready' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'id', 'restored', 'mode' ),
				),
				'meta'                => $this->ability_meta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					)
				),
			)
		);

		$this->register_ability(
			'kilobyte/regenerate-thumbnails',
			array(
				'label'               => __( 'Regenerate image thumbnails', 'kilobyte-image-optimizer' ),
				'description'         => __( 'Rebuild all registered WordPress thumbnail sizes for a Media Library image without breaking existing URLs.', 'kilobyte-image-optimizer' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'regenerate_thumbnails' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'input_schema'        => $this->attachment_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'sizes' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id', 'sizes' ),
				),
				'meta'                => $this->ability_meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * Permission callback for media read abilities.
	 *
	 * @return bool
	 */
	public function can_upload(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Permission callback for settings mutations.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for per-attachment mutations.
	 *
	 * @param mixed $input Ability input payload.
	 * @return bool
	 */
	public function can_edit_attachment( $input = null ): bool {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		$normalized    = $this->normalize_object_input( $input, $this->attachment_input_schema() );
		$attachment_id = absint( $normalized['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Return library optimization stats.
	 *
	 * @return array<string, mixed>
	 */
	public function get_stats(): array {
		return $this->attachments->get_library_stats();
	}

	/**
	 * Return a paginated queue of images.
	 *
	 * @param mixed $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function get_queue( $input = null ): array {
		$normalized = $this->normalize_object_input( $input, $this->queue_input_schema() );
		$mode       = isset( $normalized['mode'] ) && is_string( $normalized['mode'] ) ? $normalized['mode'] : 'optimize';
		$before     = absint( $normalized['before'] ?? 0 );
		$per_page   = absint( $normalized['per_page'] ?? 50 );
		if ( $per_page <= 0 ) {
			$per_page = 50;
		}

		return $this->attachments->get_queue_page( $mode, $before, $per_page );
	}

	/**
	 * Return active optimization preferences.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->settings->load()->to_array();
	}

	/**
	 * Update optimization preferences and return active settings.
	 *
	 * @param mixed $input Ability settings payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_settings( $input = null ) {
		$normalized = $this->normalize_object_input( $input, $this->settings_input_schema() );
		$settings   = OptimizationSettings::from_array(
			array_replace( $this->settings->load_saved()->to_array(), $normalized )
		);
		if ( ! $this->settings->save( $settings ) ) {
			return new WP_Error(
				'kilobyte_io_settings_save_failed',
				__( 'Could not save these preferences.', 'kilobyte-image-optimizer' ),
				array( 'status' => 500 )
			);
		}

		return $this->settings->load()->to_array();
	}

	/**
	 * Optimize a single attachment on the server.
	 *
	 * @param mixed $input Ability input with attachment_id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function optimize_image( $input = null ) {
		$normalized = $this->normalize_object_input( $input, $this->attachment_input_schema() );
		$result     = $this->optimize_server->execute( absint( $normalized['attachment_id'] ?? 0 ) );
		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : $result;
	}

	/**
	 * Restore a single attachment back to its original file.
	 *
	 * @param mixed $input Ability input with attachment_id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function restore_image( $input = null ) {
		$normalized = $this->normalize_object_input( $input, $this->attachment_input_schema() );
		$result     = $this->restore->execute( absint( $normalized['attachment_id'] ?? 0 ) );
		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : $result;
	}

	/**
	 * Regenerate registered thumbnail sizes for an attachment.
	 *
	 * @param mixed $input Ability input with attachment_id.
	 * @return array<string, int>|WP_Error
	 */
	public function regenerate_thumbnails( $input = null ) {
		$normalized = $this->normalize_object_input( $input, $this->attachment_input_schema() );
		$result     = $this->regenerate->execute( absint( $normalized['attachment_id'] ?? 0 ) );
		return $result instanceof OperationFailure ? OperationErrorMessage::to_wp_error( $result ) : $result;
	}

	/**
	 * Register an ability idempotently.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability definition.
	 * @return void
	 */
	private function register_ability( string $name, array $args ): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			return;
		}
		wp_register_ability( $name, $args );
	}

	/**
	 * Normalize object/array ability inputs and coerce schema types such as string booleans.
	 *
	 * WordPress validates object schemas against both PHP arrays and objects, and accepts
	 * string booleans like "false" without sanitizing them before invoking callbacks.
	 *
	 * @param mixed                $input  Raw ability input.
	 * @param array<string, mixed> $schema Declared JSON schema for this input.
	 * @return array<string, mixed>
	 */
	private function normalize_object_input( $input, array $schema ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}
		if ( ! is_array( $input ) ) {
			return array();
		}

		if ( function_exists( 'rest_sanitize_value_from_schema' ) ) {
			$sanitized = rest_sanitize_value_from_schema( $input, $schema );
			if ( is_array( $sanitized ) ) {
				return $sanitized;
			}
		}

		return $input;
	}

	/**
	 * Input schema for paginated queue lookup.
	 *
	 * @return array<string, mixed>
	 */
	private function queue_input_schema(): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'additionalProperties' => false,
			'properties'           => array(
				'mode'     => array(
					'type'        => 'string',
					'enum'        => array( 'optimize', 'regenerate', 'restore' ),
					'default'     => 'optimize',
					'description' => __( 'Queue mode to inspect.', 'kilobyte-image-optimizer' ),
				),
				'before'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => __( 'Attachment ID cursor from the previous page, or 0 for the first page.', 'kilobyte-image-optimizer' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 50,
					'description' => __( 'Maximum number of images to inspect in this page (1 to 100).', 'kilobyte-image-optimizer' ),
				),
			),
		);
	}

	/**
	 * Input schema for preference updates.
	 *
	 * @return array<string, mixed>
	 */
	private function settings_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'enabled'          => array(
					'type'        => 'boolean',
					'description' => __( 'Optimize new uploads automatically.', 'kilobyte-image-optimizer' ),
				),
				'format'           => array(
					'type'        => 'string',
					'enum'        => array( ImageFormat::WEBP, ImageFormat::AVIF, ImageFormat::ORIGINAL ),
					'description' => __( 'Target output format for optimized copies.', 'kilobyte-image-optimizer' ),
				),
				'quality'          => array(
					'type'        => 'integer',
					'minimum'     => OptimizationSettings::MIN_QUALITY,
					'maximum'     => OptimizationSettings::MAX_QUALITY,
					'description' => __( 'Compression quality between 40 and 95.', 'kilobyte-image-optimizer' ),
				),
				'max_width'        => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => OptimizationSettings::MAX_DIMENSION,
					'description' => __( 'Maximum copy width in pixels (0 keeps original width).', 'kilobyte-image-optimizer' ),
				),
				'max_height'       => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => OptimizationSettings::MAX_DIMENSION,
					'description' => __( 'Maximum copy height in pixels (0 keeps original height).', 'kilobyte-image-optimizer' ),
				),
				'skip_smaller'     => array(
					'type'        => 'boolean',
					'description' => __( 'Keep the original when the new copy is not smaller.', 'kilobyte-image-optimizer' ),
				),
				'auto_alt_text'    => array(
					'type'        => 'boolean',
					'description' => __( 'Fill empty alt text from filenames on upload.', 'kilobyte-image-optimizer' ),
				),
				'cwv_enhancements' => array(
					'type'        => 'boolean',
					'description' => __( 'Add async decoding hints to frontend images.', 'kilobyte-image-optimizer' ),
				),
			),
		);
	}

	/**
	 * Shared attachment input schema for per-image actions.
	 *
	 * @return array<string, mixed>
	 */
	private function attachment_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'WordPress Media Library attachment ID.', 'kilobyte-image-optimizer' ),
				),
			),
			'required'             => array( 'attachment_id' ),
		);
	}

	/**
	 * Shared settings output schema.
	 *
	 * @return array<string, mixed>
	 */
	private function settings_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'enabled'          => array( 'type' => 'boolean' ),
				'format'           => array( 'type' => 'string' ),
				'quality'          => array( 'type' => 'integer' ),
				'max_width'        => array( 'type' => 'integer' ),
				'max_height'       => array( 'type' => 'integer' ),
				'keep_backups'     => array( 'type' => 'boolean' ),
				'skip_smaller'     => array( 'type' => 'boolean' ),
				'auto_alt_text'    => array( 'type' => 'boolean' ),
				'cwv_enhancements' => array( 'type' => 'boolean' ),
				'concurrency'      => array( 'type' => 'integer' ),
			),
			'required'   => array( 'enabled', 'format', 'quality', 'max_width', 'max_height', 'skip_smaller', 'auto_alt_text', 'cwv_enhancements' ),
		);
	}

	/**
	 * Build standard ability metadata with MCP/REST exposure and explicit annotations.
	 *
	 * @param array{readonly: bool, destructive: bool, idempotent: bool} $annotations Semantic ability annotations.
	 * @return array<string, mixed>
	 */
	private function ability_meta( array $annotations ): array {
		return array(
			'annotations'  => $annotations,
			'public'       => true,
			'show_in_rest' => true,
		);
	}
}
