<?php
/**
 * WordPress attachment repository with strict path containment, per-attachment locks, and safe sibling variants.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

use Kilobyte\ImageOptimizer\Domain\Policy\CompressionPolicy;
use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\ImageFormat;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationSettings;
use WP_Filesystem_Base;
use WP_Query;

/**
 * WordPress adapter for attachment files, sibling variants, metadata, and library stats.
 */
final class WpAttachmentRepository implements AttachmentRepositoryInterface {

	public const META_KEY              = '_kilobyte_io_meta';
	public const STATS_OPTION          = 'kilobyte_io_stats';
	public const RECONCILE_OPTION      = 'kilobyte_io_stats_need_rebuild';
	public const BACKUPS_PURGED_OPTION = 'kilobyte_io_backups_purged';

	/**
	 * Return attachment metadata needed for processing, or null if invalid/outside uploads/missing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|null
	 */
	public function find_image( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}

		$file = $this->resolve_safe_upload_file( get_attached_file( $attachment_id ) );
		if ( null === $file ) {
			return null;
		}

		$bytes = (int) wp_filesize( $file );
		$mime  = $bytes > 0 ? wp_get_image_mime( $file ) : false;
		if ( ! is_string( $mime ) ) {
			return null;
		}
		return array(
			'id'        => $attachment_id,
			'file'      => $file,
			'filename'  => wp_basename( $file ),
			'mime_type' => $mime,
			'file_size' => $bytes,
		);
	}

	/**
	 * Verify the original before retiring a potentially usable copy.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function has_readable_original( int $attachment_id ): bool {
		$image = $this->find_image( $attachment_id );
		if ( null === $image || ! in_array( $image['mime_type'], CompressionPolicy::SUPPORTED_SOURCE_MIMES, true )
			|| ! WpRasterImage::is_bounded( $image['file'], $image['mime_type'] ) || WpRasterImage::is_animated( $image['file'], $image['mime_type'] )
		) {
			return false;
		}

		$record = $this->get_record( $attachment_id );
		if ( $record && in_array( _wp_relative_upload_path( $image['file'] ), $this->variant_paths( $attachment_id, $record ), true ) ) {
			return false;
		}

		return ! is_wp_error( wp_get_image_editor( $image['file'], array( 'mime_type' => $image['mime_type'] ) ) );
	}

	/**
	 * Retrieve an attachment's optimization record, if present.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return OptimizationRecord|null
	 */
	public function get_record( int $attachment_id ): ?OptimizationRecord {
		$raw = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		if ( ! empty( $raw['backup_path'] ) && get_option( self::BACKUPS_PURGED_OPTION ) ) {
			$raw['backup_path']  = '';
			$raw['backup_bytes'] = 0;
		}

		return OptimizationRecord::from_array( $raw );
	}

	/**
	 * Run a mutation with an exclusive lock for this attachment.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param callable $operation     Operation to run.
	 * @return mixed
	 */
	public function with_attachment_lock( int $attachment_id, callable $operation ) {
		$release = $this->acquire_attachment_lock( $attachment_id );
		if ( null === $release ) {
			return null;
		}
		try {
			return $operation();
		} finally {
			$release();
		}
	}

	/**
	 * Acquire a lock which can span WordPress's attachment deletion hooks.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return callable|null
	 */
	public function acquire_attachment_lock( int $attachment_id ): ?callable {
		$release = WpOperationLock::acquire( 'image:' . $attachment_id, 0 );
		if ( null !== $release ) {
			wp_cache_delete( $attachment_id, 'post_meta' );
		}
		return $release;
	}

	/**
	 * Serialize legacy backup restoration and purging.
	 *
	 * @param callable $operation Operation to run.
	 * @return mixed
	 */
	public function with_backup_lock( callable $operation ) {
		return WpOperationLock::run( 'backups', 0, $operation );
	}

	/**
	 * Persist a record and its aggregate counters under the same lock.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Optimization record.
	 * @return bool
	 */
	public function save_record( int $attachment_id, OptimizationRecord $record ): bool {
		return true === $this->with_stats_lock(
			function () use ( $attachment_id, $record ): bool {
				$previous = $this->get_record( $attachment_id );
				if ( null !== $previous && $previous->to_array() === $record->to_array() ) {
					return true;
				}
				$original = $this->load_counters();
				$stats    = null !== $previous ? $this->subtract_record( $original, $previous ) : $original;
				if ( ! $this->write_counters( $this->add_record( $stats, $record ) ) ) {
					return false;
				}
				if ( false === update_post_meta( $attachment_id, self::META_KEY, $record->to_array() ) ) {
					$this->write_counters( $original );
					return false;
				}
				delete_post_meta( $attachment_id, '_kilobyte_io_auto_failure' );
				return true;
			}
		);
	}

	/**
	 * Remove an attachment record and subtract its aggregate counters.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function delete_record( int $attachment_id ): bool {
		return true === $this->with_stats_lock(
			function () use ( $attachment_id ): bool {
				$previous = $this->get_record( $attachment_id );
				if ( null === $previous ) {
					return true;
				}
				$original = $this->load_counters();
				if ( ! $this->write_counters( $this->subtract_record( $original, $previous ) ) ) {
					return false;
				}
				if ( ! delete_post_meta( $attachment_id, self::META_KEY ) ) {
					$this->write_counters( $original );
					return false;
				}
				return true;
			}
		);
	}

	/**
	 * Restore an older backup to a new file while preserving published URLs.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_tmp_path Validated backup path.
	 * @return array{dest_file: string, size: int, thumbnails_ready: bool}|null
	 */
	public function restore_file( int $attachment_id, string $source_tmp_path ): ?array {
		$filesystem    = WpLocalFilesystem::get();
		$previous_path = get_attached_file( $attachment_id, true );
		$previous_mime = (string) get_post_mime_type( $attachment_id );
		$file_type     = wp_check_filetype( $source_tmp_path );
		$target_ext    = $file_type['ext'];
		$target_mime   = $file_type['type'];
		$dimensions    = wp_getimagesize( $source_tmp_path );
		if ( 'attachment' !== get_post_type( $attachment_id ) || ! is_string( $target_ext ) || ! is_string( $target_mime )
			|| ! is_array( $dimensions ) || ( $dimensions['mime'] ?? '' ) !== $target_mime
		) {
			return null;
		}

		$root      = $this->uploads_root();
		$directory = is_string( $previous_path ) ? realpath( dirname( $previous_path ) ) : false;
		if ( null === $root || false === $directory || 0 !== strpos( trailingslashit( $directory ), $root ) ) {
			$uploads   = wp_upload_dir();
			$root      = $this->uploads_root();
			$directory = realpath( (string) ( $uploads['path'] ?? '' ) );
		}
		if ( null === $root || false === $directory || 0 !== strpos( trailingslashit( $directory ), $root ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$previous_meta = wp_get_attachment_metadata( $attachment_id, true );
		$existing      = $this->resolve_safe_upload_file( $previous_path );
		$source_bytes  = (int) wp_filesize( $source_tmp_path );

		// A previous attempt may have recovered the file before the record could be cleared.
		if ( null !== $existing && $target_mime === $previous_mime && $source_bytes > 0
			&& (int) wp_filesize( $existing ) === $source_bytes && _wp_relative_upload_path( $existing ) === ( $previous_meta['file'] ?? '' )
		) {
			$source_hash = hash_file( 'sha256', $source_tmp_path );
			if ( is_string( $source_hash ) && hash_file( 'sha256', $existing ) === $source_hash ) {
				$sizes = $this->requested_thumbnail_sizes( $attachment_id, $previous_meta );
				return array(
					'dest_file'        => $existing,
					'size'             => $source_bytes,
					'thumbnails_ready' => null !== $sizes && ! array_diff_key( $sizes, (array) ( $previous_meta['sizes'] ?? array() ) ),
				);
			}
		}

		$name = 'kilobyte-restored-' . $attachment_id . '-' . wp_generate_uuid4() . '.' . $target_ext;
		$name = wp_unique_filename( $directory, $name );
		if ( '' === $name || wp_basename( $name ) !== $name || validate_file( $name ) ) {
			return null;
		}
		$restored = trailingslashit( $directory ) . $name;
		if ( ! $this->publish_file( $filesystem, $source_tmp_path, $restored ) ) {
			return null;
		}

		$metadata = is_array( $previous_meta ) ? $previous_meta : array();
		unset( $metadata['original_image'] );
		$metadata['file']     = _wp_relative_upload_path( $restored );
		$metadata['width']    = (int) $dimensions[0];
		$metadata['height']   = (int) $dimensions[1];
		$metadata['sizes']    = array();
		$metadata['filesize'] = (int) wp_filesize( $restored );

		$regenerated = $this->build_thumbnail_metadata( $attachment_id, $restored, $metadata );
		if ( is_wp_error( $regenerated ) && 'invalid_image' === $regenerated->get_error_code() ) {
			$this->discard_restore_files( $restored, $metadata );
			return null;
		}
		if ( is_array( $regenerated ) ) {
			$metadata   = $regenerated['metadata'];
			$image_meta = wp_read_image_metadata( $restored );
			if ( is_array( $image_meta ) ) {
				$metadata['image_meta'] = $image_meta;
			}
		}

		if ( ! update_attached_file( $attachment_id, $restored ) ) {
			$this->discard_restore_files( $restored, $metadata );
			return null;
		}

		$post_updated = wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $target_mime,
			),
			true
		);
		if ( is_wp_error( $post_updated ) || ! $post_updated || ! wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
			if ( is_string( $previous_path ) && update_attached_file( $attachment_id, $previous_path ) ) {
				$this->discard_restore_files( $restored, $metadata );
			}
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $previous_mime,
				)
			);
			return null;
		}

		return array(
			'dest_file'        => $restored,
			'size'             => $metadata['filesize'],
			'thumbnails_ready' => is_array( $regenerated ) && $regenerated['complete'],
		);
	}

	/**
	 * Write a sibling variant and any compatible responsive sizes.
	 *
	 * The filename is unique to this record, so other optimizers and earlier outputs keep their files.
	 *
	 * @param int    $attachment_id   Attachment ID.
	 * @param string $source_tmp_path Path to the encoded image.
	 * @param string $target_mime     Target MIME type.
	 * @param string $target_ext      Target extension.
	 * @param OptimizationSettings $settings Image settings.
	 * @return array{variant_path: string, size: int, variants: array<string, string>, widths: array<string, int>}|null
	 */
	public function save_sibling_variant(
		int $attachment_id,
		string $source_tmp_path,
		string $target_mime,
		string $target_ext,
		OptimizationSettings $settings
	): ?array {
		$filesystem = WpLocalFilesystem::get();
		$original   = $this->resolve_safe_upload_file( get_attached_file( $attachment_id ) );
		$root       = $this->uploads_root();
		if (
			null === $original || null === $root
			|| ImageFormat::extension_for_mime( $target_mime ) !== $target_ext
			|| ! WpRasterImage::is_bounded( $source_tmp_path, $target_mime )
		) {
			return null;
		}

		$suffix  = '.kilobyte-' . $attachment_id . '-' . wp_generate_uuid4() . '.' . $target_ext;
		$variant = $original . $suffix;
		if ( ! $this->publish_file( $filesystem, $source_tmp_path, $variant ) ) {
			return null;
		}

		$relative = substr( $variant, strlen( $root ) );
		$variants = array( substr( $original, strlen( $root ) ) => $relative );
		$size     = wp_getimagesize( $variant );
		$widths   = is_array( $size ) ? array( $relative => (int) $size[0] ) : array();

		foreach ( $this->thumbnail_paths( $attachment_id, $original ) as $thumbnail ) {
			$source_mime = wp_get_image_mime( $thumbnail );
			if ( ! is_string( $source_mime ) || ! WpRasterImage::is_bounded( $thumbnail, $source_mime ) || WpRasterImage::is_animated( $thumbnail, $source_mime ) ) {
				continue;
			}
			$editor = wp_get_image_editor( $thumbnail );
			if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( $target_mime ) ) {
				continue;
			}

			$dimensions = $editor->get_size();
			$max_width  = $settings->max_width();
			$max_height = $settings->max_height();
			if ( ! is_array( $dimensions ) ) {
				continue;
			}
			if ( ( $max_width > 0 && $dimensions['width'] > $max_width ) || ( $max_height > 0 && $dimensions['height'] > $max_height ) ) {
				$resized = $editor->resize( $max_width > 0 ? $max_width : null, $max_height > 0 ? $max_height : null );
				if ( is_wp_error( $resized ) ) {
					continue;
				}
			}
			$editor->set_quality( $settings->quality() );
			$destination = $thumbnail . $suffix;
			if ( $filesystem->exists( $destination ) || is_link( $destination ) ) {
				continue;
			}
			$saved  = $editor->save( $destination, $target_mime );
			$output = is_array( $saved ) ? ( $saved['path'] ?? '' ) : '';
			if ( $output !== $destination ) {
				if ( is_string( $output ) && pathinfo( $output, PATHINFO_FILENAME ) === pathinfo( $destination, PATHINFO_FILENAME )
					&& dirname( $output ) === dirname( $destination ) && null !== $this->resolve_safe_upload_file( $output ) ) {
					$filesystem->delete( $output );
				}
				continue;
			}
			if ( wp_get_image_mime( $destination ) !== $target_mime || ( $settings->skip_smaller() && wp_filesize( $destination ) >= wp_filesize( $thumbnail ) ) ) {
				$filesystem->delete( $destination );
				continue;
			}

			$variant_relative                                  = substr( $destination, strlen( $root ) );
			$variants[ substr( $thumbnail, strlen( $root ) ) ] = $variant_relative;
			$widths[ $variant_relative ]                       = (int) ( $saved['width'] ?? 0 );
		}

		return array(
			'variant_path' => $relative,
			'size'         => (int) wp_filesize( $variant ),
			'variants'     => $variants,
			'widths'       => $widths,
		);
	}

	/**
	 * Return the recorded variant paths, including paths written by earlier versions.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        File owner.
	 * @return array<string, string>
	 */
	public function variant_paths( int $attachment_id, OptimizationRecord $record ): array {
		if ( ! $record->is_sibling() ) {
			return array();
		}

		$root = $this->uploads_root();
		if ( null === $root ) {
			return array();
		}

		$paths = $record->variants();
		if ( empty( $paths ) ) {
			$original = get_attached_file( $attachment_id, true );
			$ext      = $record->format();
			if ( ! is_string( $original ) || ! in_array( $ext, array( 'avif', 'webp', 'jpg', 'png' ), true ) ) {
				return array();
			}
			$relative = _wp_relative_upload_path( $original );
			if ( $relative . '.' . $ext !== $record->variant_path() ) {
				return array();
			}

			$paths[ $relative ] = $record->variant_path();
		}

		return array_filter(
			$paths,
			static function ( string $variant, $source ) use ( $record, $attachment_id ): bool {
				if ( ! is_string( $source ) || validate_file( $source ) || validate_file( $variant ) || 0 === strpos( $variant, '/' ) ) {
					return false;
				}
				$ext = preg_quote( $record->format(), '~' );
				return 1 === preg_match( '~^' . preg_quote( $source, '~' ) . '(?:\.kilobyte-(?:' . $attachment_id . '-)?[a-f0-9-]{36})?\.' . $ext . '$~D', $variant );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Return live WordPress image paths that must survive deferred cleanup.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, true>
	 */
	public function attachment_image_paths( int $attachment_id ): array {
		$root = $this->uploads_root();
		if ( null === $root ) {
			return array();
		}

		$attached_files = array( get_attached_file( $attachment_id, true ), get_attached_file( $attachment_id ) );
		$paths          = array( wp_get_original_image_path( $attachment_id, true ) );
		$metadata       = wp_get_attachment_metadata( $attachment_id, true );
		$backup_sizes   = (array) get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		$backup_sizes[] = array( 'file' => $metadata['thumb'] ?? '' );
		foreach ( array_unique( array_filter( $attached_files, 'is_string' ) ) as $attached_file ) {
			$file = $this->resolve_safe_upload_file( $attached_file );
			if ( null !== $file ) {
				$paths   = array_merge( $paths, $this->thumbnail_paths( $attachment_id, $file ) );
				$paths[] = $file;
				foreach ( $backup_sizes as $backup_size ) {
					$name = $backup_size['file'] ?? '';
					if ( is_string( $name ) && '' !== $name && wp_basename( $name ) === $name ) {
						$paths[] = trailingslashit( dirname( $file ) ) . $name;
					}
				}
			}
		}

		$protected = array();
		foreach ( $paths as $path ) {
			$file = $this->resolve_safe_upload_file( $path );
			if ( null !== $file ) {
				$protected[ substr( $file, strlen( $root ) ) ] = true;
			}
		}
		return $protected;
	}

	/**
	 * Delete only the variant files owned by the record.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        File owner.
	 * @return bool
	 */
	public function delete_sibling_variant( int $attachment_id, OptimizationRecord $record ): bool {
		$paths = $this->variant_paths( $attachment_id, $record );
		return ! empty( $paths ) && $this->delete_variant_files( $paths );
	}

	/**
	 * Remove recorded file paths under the current site's uploads directory.
	 *
	 * @param array<string> $paths Recorded relative paths.
	 * @return bool
	 */
	public function delete_variant_files( array $paths ): bool {
		$filesystem = WpLocalFilesystem::get();
		$root       = $this->uploads_root();
		if ( null === $root ) {
			return empty( $paths );
		}

		$removed = true;
		foreach ( $paths as $relative ) {
			if ( ! is_string( $relative ) || validate_file( $relative ) || 0 === strpos( $relative, '/' )
				|| ! preg_match( '/\.kilobyte-(?:\d+-)?[a-f0-9-]{36}\.(?:jpg|png|webp|avif)$/iD', $relative ) ) {
				$removed = false;
				continue;
			}
			$file = $root . $relative;
			if ( is_link( $file ) || ( $filesystem->exists( $file ) && ( null === $this->resolve_safe_upload_file( $file ) || ! $filesystem->delete( $file ) ) ) ) {
				$removed = false;
			}
		}

		return $removed;
	}

	/**
	 * Subtract the captured record after core deletes the attachment and its post meta.
	 *
	 * @param OptimizationRecord $record Removed record.
	 * @return bool
	 */
	public function remove_deleted_record( OptimizationRecord $record ): bool {
		return true === $this->with_stats_lock(
			function () use ( $record ): bool {
				return $this->write_counters( $this->subtract_record( $this->load_counters(), $record ) );
			}
		);
	}

	/**
	 * Generate thumbnail sizes without calling the full-image generation pipeline.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int|null
	 */
	public function regenerate_thumbnails( int $attachment_id ): ?int {
		$image = $this->find_image( $attachment_id );
		if ( null === $image || ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		$previous = wp_get_attachment_metadata( $attachment_id, true );
		$result   = $this->build_thumbnail_metadata(
			$attachment_id,
			(string) $image['file'],
			is_array( $previous ) ? $previous : array()
		);
		if ( ! is_array( $result ) ) {
			return null;
		}
		$metadata = $result['metadata'];
		if ( $previous !== $metadata && ! wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
			return null;
		}
		return $result['complete'] ? $result['count'] : null;
	}

	/**
	 * Generate sizes for a file without modifying an attachment record.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $file          Local image.
	 * @param array<string, mixed> $metadata      Metadata to preserve.
	 * @return array{metadata: array<string, mixed>, count: int, complete: bool}|\WP_Error|null
	 */
	private function build_thumbnail_metadata( int $attachment_id, string $file, array $metadata ) {
		$mime = wp_get_image_mime( $file );
		if ( ! is_string( $mime ) || ! WpRasterImage::is_bounded( $file, $mime ) || WpRasterImage::is_animated( $file, $mime ) ) {
			return null;
		}

		$editor = wp_get_image_editor( $file, array( 'mime_type' => $mime ) );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$size = $editor->get_size();
		if ( ! is_array( $size ) ) {
			return null;
		}

		$metadata['file']   = _wp_relative_upload_path( $file );
		$metadata['width']  = $size['width'];
		$metadata['height'] = $size['height'];
		$sizes              = $this->requested_thumbnail_sizes( $attachment_id, $metadata );
		if ( null === $sizes ) {
			return null;
		}

		$generated = $editor->multi_resize( $sizes );
		if ( ! is_array( $generated ) ) {
			return null;
		}
		$metadata['sizes'] = array_replace( (array) ( $metadata['sizes'] ?? array() ), $generated );
		return array(
			'metadata' => $metadata,
			'count'    => count( $generated ),
			'complete' => ! array_diff_key( $sizes, $generated ),
		);
	}

	/**
	 * Return the thumbnail sizes allowed by WordPress that fit this image.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Image metadata.
	 * @return array<string, mixed>|null
	 */
	private function requested_thumbnail_sizes( int $attachment_id, array $metadata ): ?array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$sizes = apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), $metadata, $attachment_id );
		if ( ! is_array( $sizes ) ) {
			return null;
		}
		return array_filter(
			$sizes,
			static function ( $size ) use ( $metadata ): bool {
				return is_array( $size ) && (bool) image_resize_dimensions(
					(int) ( $metadata['width'] ?? 0 ),
					(int) ( $metadata['height'] ?? 0 ),
					(int) ( $size['width'] ?? 0 ),
					(int) ( $size['height'] ?? 0 ),
					$size['crop'] ?? false
				);
			}
		);
	}

	/**
	 * Clean up new files when a legacy restore fails before publishing the record.
	 *
	 * @param string               $file     Staged restored original.
	 * @param array<string, mixed> $metadata New metadata.
	 * @return void
	 */
	private function discard_restore_files( string $file, array $metadata ): void {
		$filesystem = WpLocalFilesystem::get();
		$directory  = trailingslashit( dirname( $file ) );
		$prefix     = pathinfo( $file, PATHINFO_FILENAME ) . '-';
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			$name = $size['file'] ?? '';
			if ( is_string( $name ) && wp_basename( $name ) === $name && 0 === strpos( $name, $prefix ) && null !== $this->resolve_safe_upload_file( $directory . $name ) ) {
				$filesystem->delete( $directory . $name );
			}
		}
		$filesystem->delete( $file );
	}

	/**
	 * Read a bounded page of eligible images before an attachment ID.
	 *
	 * @param string $mode     Queue mode.
	 * @param int    $before   Cursor from the previous page, or zero.
	 * @param int    $per_page Page size.
	 * @param bool   $check_permissions Require the current user to be able to edit each attachment.
	 * @return array<string, mixed>
	 */
	public function get_queue_page( string $mode, int $before, int $per_page, bool $check_permissions = true ): array {
		$per_page = max( 1, min( 100, $per_page ) );
		$posts    = $this->query_images( $before, $per_page + 1 );
		$has_more = count( $posts ) > $per_page;
		$posts    = array_slice( $posts, 0, $per_page );
		$items    = array();
		$missing  = 0;
		$cursor   = 0;

		foreach ( $posts as $post ) {
			$attachment_id = (int) $post->ID;
			$cursor        = $attachment_id;
			if ( $check_permissions && ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}

			$record = $this->get_record( $attachment_id );
			if ( 'optimize' === $mode && null !== $record ) {
				continue;
			}
			if ( 'restore' === $mode && ( null === $record || ! $record->is_restorable() ) ) {
				continue;
			}

			$image = $this->find_image( $attachment_id );
			if ( null === $image && 'restore' !== $mode ) {
				++$missing;
				continue;
			}

			$meta = wp_get_attachment_metadata( $attachment_id );

			$items[] = array(
				'id'        => $attachment_id,
				'title'     => get_the_title( $attachment_id ),
				'filename'  => $image['filename'] ?? wp_basename( (string) get_attached_file( $attachment_id, true ) ),
				'url'       => (string) wp_get_attachment_url( $attachment_id ),
				'mime_type' => $image['mime_type'] ?? '',
				'file_size' => $image['file_size'] ?? 0,
				'width'     => (int) ( $meta['width'] ?? 0 ),
				'height'    => (int) ( $meta['height'] ?? 0 ),
			);
		}

		return array(
			'items'           => $items,
			'skipped_missing' => $missing,
			'next_before'     => $has_more ? $cursor : 0,
		);
	}

	/**
	 * Mark legacy backups as purged without walking the media library.
	 *
	 * @return bool
	 */
	public function clear_backup_counters(): bool {
		return true === $this->with_stats_lock(
			function (): bool {
				$original                  = $this->load_counters();
				$stats                     = $original;
				$stats['restorable_count'] = max( 0, $stats['restorable_count'] - $stats['backup_count'] );
				$stats['backup_count']     = 0;
				$stats['backup_bytes']     = 0;
				if ( ! $this->write_counters( $stats ) ) {
					return false;
				}
				if ( ! get_option( self::BACKUPS_PURGED_OPTION ) && ! update_option( self::BACKUPS_PURGED_OPTION, true, false ) ) {
					$this->write_counters( $original );
					return false;
				}
				return true;
			}
		);
	}

	/**
	 * Rebuild aggregate counters from the CLI while record commits are paused.
	 *
	 * @return array<string, mixed>|null
	 */
	public function reconcile_stats(): ?array {
		return $this->with_stats_lock(
			function (): ?array {
				$stats  = array_fill_keys( array_keys( $this->load_counters() ), 0 );
				$before = 0;

				do {
					$posts    = $this->query_images( $before, 100 );
					$has_more = count( $posts ) === 100;
					foreach ( $posts as $post ) {
						$before = (int) $post->ID;
						$record = $this->get_record( $before );
						if ( null !== $record ) {
							$stats = $this->add_record( $stats, $record );
						}
					}
				} while ( $has_more );

				if ( ! $this->write_counters( $stats ) ) {
					return null;
				}
				delete_option( self::RECONCILE_OPTION );
				return $this->get_library_stats();
			}
		);
	}

	/**
	 * Read the dashboard counters and reuse WordPress's cached attachment counts.
	 *
	 * @return array<string, mixed>
	 */
	public function get_library_stats(): array {
		$counts = (array) wp_count_attachments();
		$total  = array_sum( array_intersect_key( $counts, array_flip( CompressionPolicy::SUPPORTED_SOURCE_MIMES ) ) );

		$counters        = $this->load_counters();
		$optimized_count = min( $total, $counters['optimized_count'] );
		$total_original  = $counters['total_original'];
		$total_optimized = $counters['total_optimized'];
		$total_saved     = max( 0, $total_original - $total_optimized );

		return array(
			'total_images'     => $total,
			'optimized_count'  => $optimized_count,
			'remaining_count'  => max( 0, $total - $optimized_count ),
			'total_original'   => $total_original,
			'total_optimized'  => $total_optimized,
			'total_saved'      => $total_saved,
			'saved_percent'    => $total_original > 0 ? round( ( $total_saved / $total_original ) * 100, 1 ) : 0.0,
			'backup_count'     => $counters['backup_count'],
			'backup_bytes'     => $counters['backup_bytes'],
			'restorable_count' => $counters['restorable_count'],
			'needs_reconcile'  => (bool) get_option( self::RECONCILE_OPTION ),
		);
	}

	/**
	 * Resolve a regular local file inside this site's uploads directory.
	 *
	 * @param mixed $path Raw attachment path.
	 * @return string|null
	 */
	public function resolve_safe_upload_file( $path ): ?string {
		if ( ! is_string( $path ) || '' === $path || is_link( $path ) ) {
			return null;
		}

		$root       = $this->uploads_root();
		$file       = realpath( $path );
		$filesystem = WpLocalFilesystem::get();
		return ( null !== $root && false !== $file && 0 === strpos( $file, $root ) && $filesystem->is_file( $file ) )
			? $file
			: null;
	}

	/**
	 * Return the canonical uploads root with a directory boundary.
	 *
	 * @return string|null
	 */
	public function uploads_root(): ?string {
		$uploads = wp_get_upload_dir();
		$root    = realpath( (string) $uploads['basedir'] );
		return false === $root ? null : trailingslashit( $root );
	}

	/**
	 * Stage a copy in the destination directory before making it visible.
	 *
	 * @param WP_Filesystem_Base $filesystem Filesystem adapter.
	 * @param string             $source     File to copy.
	 * @param string             $destination Unused final path.
	 * @return bool
	 */
	private function publish_file( WP_Filesystem_Base $filesystem, string $source, string $destination ): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$stage = wp_tempnam( 'kilobyte', trailingslashit( dirname( $destination ) ) );
		if ( ! is_string( $stage ) || '' === $stage ) {
			return false;
		}

		try {
			$bytes = wp_filesize( $source );
			return $bytes > 0
				&& ! is_link( $destination )
				&& $filesystem->copy( $source, $stage, true, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 )
				&& wp_filesize( $stage ) === $bytes
				&& $filesystem->move( $stage, $destination );
		} finally {
			if ( $filesystem->exists( $stage ) ) {
				$filesystem->delete( $stage );
			}
		}
	}

	/**
	 * Read unique existing thumbnail files next to the attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $original      Main image path.
	 * @return string[]
	 */
	private function thumbnail_paths( int $attachment_id, string $original ): array {
		$meta  = wp_get_attachment_metadata( $attachment_id, true );
		$dir   = trailingslashit( dirname( $original ) );
		$files = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $data ) {
			$name = is_array( $data ) ? ( $data['file'] ?? '' ) : '';
			if ( ! is_string( $name ) || '' === $name || wp_basename( $name ) !== $name || validate_file( $name ) ) {
				continue;
			}
			$file = $this->resolve_safe_upload_file( $dir . $name );
			if ( null !== $file && $file !== $original ) {
				$files[ $file ] = $file;
			}
		}
		return array_values( $files );
	}

	/**
	 * Query attachment IDs in descending order using a bounded cursor.
	 *
	 * @param int $before Starting cursor.
	 * @param int $limit  Max rows to read.
	 * @return \WP_Post[]
	 */
	private function query_images( int $before, int $limit ): array {
		$filter = static function ( string $where, WP_Query $query ): string {
			$cursor = (int) $query->get( 'kilobyte_io_before' );
			if ( $cursor <= 0 ) {
				return $where;
			}
			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $cursor );
		};

		add_filter( 'posts_where', $filter, 10, 2 );
		try {
			$query = new WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => CompressionPolicy::SUPPORTED_SOURCE_MIMES,
					'posts_per_page'         => $limit,
					'orderby'                => 'ID',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'kilobyte_io_before'     => $before,
				)
			);
			return $query->posts;
		} finally {
			remove_filter( 'posts_where', $filter, 10 );
		}
	}

	/**
	 * Serialize aggregate option writes and refresh the option cache after acquiring the lock.
	 *
	 * @param callable $operation Operation to run.
	 * @return mixed
	 */
	private function with_stats_lock( callable $operation ) {
		return WpOperationLock::run(
			'stats',
			5,
			static function () use ( $operation ) {
				wp_cache_delete( self::STATS_OPTION, 'options' );
				wp_cache_delete( self::BACKUPS_PURGED_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return $operation();
			}
		);
	}

	/**
	 * Update counters and treat an unchanged value as a successful write.
	 *
	 * @param array<string, int> $stats Updated counters.
	 * @return bool
	 */
	private function write_counters( array $stats ): bool {
		return $stats === $this->load_counters() || update_option( self::STATS_OPTION, $stats, false );
	}

	/**
	 * Add a record to aggregate counters.
	 *
	 * @param array<string, int> $stats  Current counters.
	 * @param OptimizationRecord $record Added record.
	 * @return array<string, int>
	 */
	private function add_record( array $stats, OptimizationRecord $record ): array {
		++$stats['optimized_count'];
		$stats['total_original']  += $record->original_size();
		$stats['total_optimized'] += $record->optimized_size();
		if ( $record->has_backup() ) {
			++$stats['backup_count'];
			$stats['backup_bytes'] += $record->backup_bytes();
		}
		if ( $record->is_restorable() ) {
			++$stats['restorable_count'];
		}
		return $stats;
	}

	/**
	 * Subtract a previous record from aggregate counters.
	 *
	 * @param array<string, int> $stats  Current counters.
	 * @param OptimizationRecord $record Record being removed/replaced.
	 * @return array<string, int>
	 */
	private function subtract_record( array $stats, OptimizationRecord $record ): array {
		$stats['optimized_count'] = max( 0, $stats['optimized_count'] - 1 );
		$stats['total_original']  = max( 0, $stats['total_original'] - $record->original_size() );
		$stats['total_optimized'] = max( 0, $stats['total_optimized'] - $record->optimized_size() );
		if ( $record->has_backup() ) {
			$stats['backup_count'] = max( 0, $stats['backup_count'] - 1 );
			$stats['backup_bytes'] = max( 0, $stats['backup_bytes'] - $record->backup_bytes() );
		}
		if ( $record->is_restorable() ) {
			$stats['restorable_count'] = max( 0, $stats['restorable_count'] - 1 );
		}
		return $stats;
	}

	/**
	 * Load persisted incremental counters.
	 *
	 * @return array{optimized_count: int, total_original: int, total_optimized: int, backup_count: int, backup_bytes: int, restorable_count: int}
	 */
	private function load_counters(): array {
		$raw = get_option( self::STATS_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return array(
			'optimized_count'  => isset( $raw['optimized_count'] ) ? max( 0, (int) $raw['optimized_count'] ) : 0,
			'total_original'   => isset( $raw['total_original'] ) ? max( 0, (int) $raw['total_original'] ) : 0,
			'total_optimized'  => isset( $raw['total_optimized'] ) ? max( 0, (int) $raw['total_optimized'] ) : 0,
			'backup_count'     => isset( $raw['backup_count'] ) ? max( 0, (int) $raw['backup_count'] ) : 0,
			'backup_bytes'     => isset( $raw['backup_bytes'] ) ? max( 0, (int) $raw['backup_bytes'] ) : 0,
			'restorable_count' => isset( $raw['restorable_count'] ) ? max( 0, (int) $raw['restorable_count'] ) : 0,
		);
	}
}
