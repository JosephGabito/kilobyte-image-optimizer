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
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;
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
	public const AUTO_PENDING          = '_kilobyte_io_auto_pending';
	public const AUTO_ATTEMPTS         = '_kilobyte_io_auto_attempts';
	public const NATIVE_PENDING        = '_kilobyte_io_native_pending';

	private const MAX_CLEANUP_REFERENCES = 10000;
	private const COUNTER_WRITE_PENDING  = 'kilobyte_io_counter_write_pending';

	/** @var array<string, bool> Attachments Core is deleting in this request. */
	private static array $deleting_images = array();

	/** @var array<int, bool> Sites with a stats write in progress in this request. */
	private static array $active_stats_writes = array();

	/** @var int Maximum GIF data read for delivery across this request. */
	private int $gif_delivery_bytes_left = WpRasterImage::MAX_BYTES;

	/** @var int Compressed bytes available to verify ambiguous changes on this queue page. */
	private int $queue_verification_bytes_left = WpRasterImage::MAX_BYTES;

	/** @var bool|null Whether a scheduled cleanup can check the site's references safely. */
	private ?bool $cleanup_can_check_references = null;

	/**
	 * Return attachment metadata needed for processing, or null if invalid/outside uploads/missing.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $for_encoding  Capture the source fingerprint before publishing.
	 * @return array<string, mixed>|null
	 */
	public function find_image( int $attachment_id, bool $for_encoding = false ): ?array {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}

		$file = $this->resolve_safe_upload_file( get_attached_file( $attachment_id ) );
		if ( null === $file ) {
			return null;
		}

		if ( $for_encoding ) {
			clearstatcache( true, $file );
		}
		$bytes = (int) wp_filesize( $file );
		$mime  = $bytes > 0 ? wp_get_image_mime( $file ) : false;
		if ( ! is_string( $mime ) ) {
			return null;
		}
		$stamp       = $for_encoding && $bytes <= WpRasterImage::MAX_BYTES ? $this->source_stamp( $file ) : '';
		$source_hash = '' !== $stamp ? $this->source_hash( $file ) : '';
		if ( '' !== $source_hash && $stamp !== $this->source_stamp( $file ) ) {
			$source_hash = '';
		}
		return array(
			'id'              => $attachment_id,
			'file'            => $file,
			'filename'        => wp_basename( $file ),
			'mime_type'       => $mime,
			'file_size'       => $bytes,
			'source_hash'     => $source_hash,
			'source_revision' => '' !== $source_hash ? $stamp . '|' . $source_hash : '',
		);
	}

	/**
	 * @param string $file Validated local source path.
	 * @param string $mime Source MIME type.
	 * @return bool
	 */
	public function source_is_animated( string $file, string $mime ): bool {
		return WpRasterImage::is_animated( $file, $mime );
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function has_animated_gif_original( int $attachment_id ): bool {
		$image = $this->find_image( $attachment_id );
		return null !== $image && 'image/gif' === $image['mime_type'] && WpRasterImage::has_gif_animation( $image['file'] );
	}

	/**
	 * Keep serving a variant only while its source remains the same.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Stored optimization.
	 * @return array<string, string>
	 */
	public function delivery_variant_paths( int $attachment_id, OptimizationRecord $record ): array {
		$paths = $this->variant_paths( $attachment_id, $record );
		if ( empty( $paths ) ) {
			return array();
		}

		$is_gif = 'gif' === strtolower( pathinfo( $record->original_name(), PATHINFO_EXTENSION ) );
		$file   = $this->resolve_safe_upload_file( get_attached_file( $attachment_id ) );
		if ( null === $file ) {
			// Keep using a copy when the same Media Library file has gone missing.
			$attached = get_attached_file( $attachment_id, true );
			if ( $is_gif || ! is_string( $attached ) || ( $paths[ _wp_relative_upload_path( $attached ) ] ?? '' ) !== $record->variant_path() ) {
				return array();
			}
		} elseif ( '' === $record->source_revision() && ! $is_gif ) {
			// Earlier records lack a fingerprint. A changed size or newer source rules the copy out.
			$optimized_at = '' !== $record->optimized_at() ? strtotime( $record->optimized_at() . ' UTC' ) : false;
			if ( (int) wp_filesize( $file ) !== $record->original_size() || ( false !== $optimized_at && WpLocalFilesystem::get()->mtime( $file ) > $optimized_at ) ) {
				return array();
			}
		} else {
			$revision = explode( '|', $record->source_revision(), 2 );
			if ( empty( $revision[0] ) || empty( $revision[1] ) || $this->source_stamp( $file ) !== $revision[0] ) {
				return array();
			}
		}
		// Another media tool can replace a still GIF with an animation within one filesystem timestamp.
		if ( $is_gif ) {
			$size = (int) wp_filesize( $file );
			if ( $size <= 0 || $size > $this->gif_delivery_bytes_left ) {
				return array();
			}
			$this->gif_delivery_bytes_left -= $size;
			if ( $this->source_hash( $file ) !== $revision[1] ) {
				return array();
			}
		}
		$root = $this->uploads_root();
		foreach ( array_intersect_key( $record->thumbnail_stamps(), $paths ) as $source => $stamp ) {
			$current = null !== $root ? $this->source_stamp( $root . $source ) : '';
			if ( '' !== $current && $current !== $stamp ) {
				unset( $paths[ $source ] );
			}
		}
		return $paths;
	}

	/**
	 * Read inexpensive file attributes before doing a bounded content check.
	 *
	 * @param string $file Canonical local path.
	 * @return string
	 */
	private function source_stamp( string $file ): string {
		clearstatcache( true, $file );
		// Core's filesystem wrapper exposes mtime but not inode or change time.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$stats = @stat( $file );
		return is_array( $stats ) ? implode( ':', array( $stats['ino'], $stats['size'], $stats['mtime'], $stats['ctime'] ) ) : '';
	}

	/**
	 * Identify the current local file for a cached Media Library message.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string File identity that changes with the path or file attributes.
	 */
	public function attachment_source_stamp( int $attachment_id ): string {
		$path = (string) get_attached_file( $attachment_id, true );
		$file = $this->resolve_safe_upload_file( $path );
		return hash( 'sha256', $path . '|' . ( null === $file ? '' : $this->source_stamp( $file ) ) );
	}

	/**
	 * Hash a bounded local image to detect replacements within the stat timestamp.
	 *
	 * @param string $file Canonical local path.
	 * @return string
	 */
	private function source_hash( string $file ): string {
		// The file may be moved by another media tool before the read.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return (string) @hash_file( 'sha256', $file );
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
		if ( $record && $this->is_recorded_variant( $image['file'], $record ) ) {
			return false;
		}

		return ! is_wp_error( wp_get_image_editor( $image['file'], array( 'mime_type' => $image['mime_type'] ) ) );
	}

	/**
	 * Verify the original without allocating decoded pixels.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Stored optimization.
	 * @return bool
	 */
	public function has_verified_original( int $attachment_id, OptimizationRecord $record ): bool {
		if ( '' === $record->source_revision() ) {
			return false;
		}
		$image = $this->find_image( $attachment_id, true );
		return null !== $image
			&& $record->matches_source_hash( $image['source_hash'] )
			&& ! $this->is_recorded_variant( $image['file'], $record );
	}

	/**
	 * A generated copy can become the attachment's active file through another tool.
	 *
	 * @param string             $file   Canonical local file.
	 * @param OptimizationRecord $record Stored optimization.
	 * @return bool
	 */
	private function is_recorded_variant( string $file, OptimizationRecord $record ): bool {
		$root = $this->uploads_root();
		if ( null === $root || ! $record->is_sibling() || 0 !== strpos( $file, $root ) ) {
			return false;
		}
		$relative = substr( $file, strlen( $root ) );
		return $relative === $record->variant_path() || in_array( $relative, $record->variants(), true );
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
	 * Check the current source without decoding pixels or hashing an entire queue page.
	 *
	 * @param int                      $attachment_id Attachment ID.
	 * @param OptimizationRecord       $record        Existing optimization.
	 * @param array<string, mixed>|null $image         Source already read by an encoder, if available.
	 * @return bool
	 */
	public function has_changed_sibling_source( int $attachment_id, OptimizationRecord $record, ?array $image = null ): bool {
		if ( ! $record->is_sibling() || $record->has_backup() ) {
			return false;
		}
		$file = $image['file'] ?? $this->resolve_safe_upload_file( get_attached_file( $attachment_id ) );
		$root = $this->uploads_root();
		if ( null === $file || null === $root || 0 !== strpos( $file, $root ) || $this->is_recorded_variant( $file, $record ) ) {
			return false;
		}
		$source = substr( $file, strlen( $root ) );
		$paths  = $record->variants();
		if ( empty( $paths ) ) {
			$paths = $this->variant_paths( $attachment_id, $record );
		}
		if ( ( $paths[ $source ] ?? '' ) !== $record->variant_path() ) {
			return true;
		}

		$revision = $record->source_revision();
		if ( '' !== $revision ) {
			if ( ! empty( $image['source_revision'] ) ? $image['source_revision'] !== $revision : $this->source_differs_on_disk( $file, $record ) ) {
				return true;
			}
		} else {
			$optimized_at = '' !== $record->optimized_at() ? strtotime( $record->optimized_at() . ' UTC' ) : false;
			if ( (int) wp_filesize( $file ) !== $record->original_size() || ( false !== $optimized_at && WpLocalFilesystem::get()->mtime( $file ) > $optimized_at ) ) {
				return true;
			}
		}

		$stamps = $record->thumbnail_stamps();
		if ( empty( $stamps ) ) {
			return false;
		}
		$matched = 0;
		foreach ( $this->thumbnail_paths( $attachment_id, $file ) as $thumbnail ) {
			$relative = substr( $thumbnail, strlen( $root ) );
			if ( isset( $stamps[ $relative ] ) ) {
				if ( $this->source_stamp( $thumbnail ) !== $stamps[ $relative ] ) {
					return true;
				}
				++$matched;
			}
		}
		return count( $stamps ) !== $matched;
	}

	/**
	 * Verify a source whose filesystem timestamp can hide an edit made during the same second.
	 *
	 * @param string             $file   Current local source.
	 * @param OptimizationRecord $record Existing optimization.
	 * @return bool Whether the item should be checked by the encoder.
	 */
	private function source_differs_on_disk( string $file, OptimizationRecord $record ): bool {
		$revision = explode( '|', $record->source_revision(), 2 );
		$stamp    = $this->source_stamp( $file );
		if ( '' === $stamp || $stamp !== $revision[0] ) {
			return true;
		}
		$attributes = explode( ':', $stamp );
		$saved_at   = '' !== $record->optimized_at() ? strtotime( $record->optimized_at() . ' UTC' ) : false;
		if ( false !== $saved_at && max( (int) $attributes[2], (int) $attributes[3] ) < $saved_at ) {
			return false;
		}

		$bytes = (int) $attributes[1];
		if ( $bytes <= 0 || $bytes > $this->queue_verification_bytes_left ) {
			// Leave an uncertain image eligible. Its encoder checks the saved hash before publishing.
			return true;
		}
		$this->queue_verification_bytes_left -= $bytes;
		return $this->source_hash( $file ) !== ( $revision[1] ?? '' );
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
	 * @param bool|null $available Whether the database answered the lock request.
	 * @param bool $for_deletion Whether Core owns the attachment until its deletion finishes.
	 * @return callable|null
	 */
	public function acquire_attachment_lock( int $attachment_id, ?bool &$available = null, bool $for_deletion = false ): ?callable {
		$key = get_current_blog_id() . ':' . $attachment_id;
		if ( isset( self::$deleting_images[ $key ] ) ) {
			$available = true;
			return null;
		}
		$release = WpOperationLock::acquire( 'image:' . $attachment_id, 0, $available );
		if ( null === $release ) {
			return null;
		}
		wp_cache_delete( $attachment_id, 'post_meta' );
		if ( ! $for_deletion ) {
			return $release;
		}

		self::$deleting_images[ $key ] = true;
		return static function () use ( $release, $key ): void {
			try {
				$release();
			} finally {
				unset( self::$deleting_images[ $key ] );
			}
		};
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
				$saved    = $this->write_counted_change(
					$original,
					$this->add_record( $stats, $record ),
					'postmeta',
					static function () use ( $attachment_id, $record ): bool {
						return false !== update_post_meta( $attachment_id, self::META_KEY, $record->to_array() );
					}
				);
				if ( $saved && WpOperationLock::is_current() ) {
					delete_post_meta( $attachment_id, '_kilobyte_io_auto_failure' );
					delete_post_meta( $attachment_id, '_kilobyte_io_auto_skip' );
					$this->clear_automatic_job( $attachment_id );
				}
				return $saved;
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
				if ( ! $this->clear_automatic_job( $attachment_id ) ) {
					return false;
				}
				$original = $this->load_counters();
				return $this->write_counted_change(
					$original,
					$this->subtract_record( $original, $previous ),
					'postmeta',
					static function () use ( $attachment_id ): bool {
						return delete_post_meta( $attachment_id, self::META_KEY );
					}
				);
			}
		);
	}

	/**
	 * Cancel queued optimization before restoring the image or after a successful check.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether the job is absent.
	 */
	public function clear_automatic_job( int $attachment_id ): bool {
		foreach ( array( self::NATIVE_PENDING, self::AUTO_PENDING ) as $key ) {
			if ( metadata_exists( 'post', $attachment_id, $key ) && ( ! WpOperationLock::is_current() || ! delete_post_meta( $attachment_id, $key ) ) ) {
				return false;
			}
		}
		if ( metadata_exists( 'post', $attachment_id, self::AUTO_ATTEMPTS ) && WpOperationLock::is_current() ) {
			delete_post_meta( $attachment_id, self::AUTO_ATTEMPTS );
		}
		return true;
	}

	/**
	 * Restore an older backup to a new file while preserving published URLs.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_tmp_path Validated backup path.
	 * @param int    $expected_bytes  Recorded original size, if available.
	 * @return array{dest_file: string, size: int, thumbnails_ready: bool}|OperationFailure|null
	 */
	public function restore_file( int $attachment_id, string $source_tmp_path, int $expected_bytes ) {
		$filesystem    = WpLocalFilesystem::get();
		$previous_path = get_attached_file( $attachment_id, true );
		$previous_mime = (string) get_post_mime_type( $attachment_id );
		$file_type     = wp_check_filetype( $source_tmp_path );
		$target_ext    = $file_type['ext'];
		$target_mime   = $file_type['type'];
		$dimensions    = wp_getimagesize( $source_tmp_path );
		$source_bytes  = (int) wp_filesize( $source_tmp_path );
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}
		if ( ! is_string( $target_ext ) || ! is_string( $target_mime ) || ! is_array( $dimensions ) || ( $dimensions['mime'] ?? '' ) !== $target_mime
			|| $source_bytes <= 0 || ( $expected_bytes > 0 && $source_bytes !== $expected_bytes )
		) {
			return new OperationFailure( OperationFailure::BACKUP_UNVERIFIED );
		}
		$limit_failure = WpRasterImage::limit_failure( $source_tmp_path, $target_mime );
		if ( OperationFailure::INVALID_IMAGE === $limit_failure || ( null !== $limit_failure && $expected_bytes <= 0 ) ) {
			return new OperationFailure( OperationFailure::BACKUP_UNVERIFIED );
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

		$regenerated     = $this->build_thumbnail_metadata( $attachment_id, $restored, $metadata );
		$thumbnail_files = is_array( $regenerated ) ? $regenerated['files'] : array();
		if ( ! is_array( $regenerated ) && null === $limit_failure && ( 'image/gif' !== $target_mime || ! WpRasterImage::has_gif_animation( $restored ) ) ) {
			$editor = is_wp_error( $regenerated ) ? $regenerated : wp_get_image_editor( $restored, array( 'mime_type' => $target_mime ) );
			if ( is_wp_error( $editor ) && ( $expected_bytes <= 0 || 'image_no_editor' !== $editor->get_error_code() ) ) {
				$filesystem->delete( $restored );
				return new OperationFailure( OperationFailure::BACKUP_UNVERIFIED, $editor->get_error_message() );
			}
			unset( $editor );
		}
		if ( is_array( $regenerated ) ) {
			$metadata   = $regenerated['metadata'];
			$image_meta = wp_read_image_metadata( $restored );
			if ( is_array( $image_meta ) ) {
				$metadata['image_meta'] = $image_meta;
			}
		}

		global $wpdb;
		if ( ! WpOperationLock::is_current() || ! WpOperationLock::guard_write( $wpdb->postmeta, static fn () => update_attached_file( $attachment_id, $restored ) ) ) {
			WpVariantRetirement::schedule_removed_files( $thumbnail_files );
			return null;
		}
		if ( ! WpOperationLock::is_current() ) {
			WpVariantRetirement::schedule_removed_files( $thumbnail_files );
			return new OperationFailure( OperationFailure::BUSY );
		}

		$post_updated = $this->set_attachment_mime( $attachment_id, $target_mime );
		if ( ! $post_updated || ! WpOperationLock::is_current() || ! WpOperationLock::guard_write( $wpdb->postmeta, static fn () => wp_update_attachment_metadata( $attachment_id, $metadata ) ) ) {
			if ( is_string( $previous_path ) && WpOperationLock::is_current() ) {
				WpOperationLock::guard_write( $wpdb->postmeta, static fn () => update_attached_file( $attachment_id, $previous_path ) );
				if ( get_attached_file( $attachment_id, true ) === $previous_path && WpOperationLock::is_current() ) {
					$this->set_attachment_mime( $attachment_id, $previous_mime );
				}
			}
			// Keep the restored full-size file after WordPress has seen its path.
			WpVariantRetirement::schedule_removed_files( $thumbnail_files );
			return null;
		}

		return array(
			'dest_file'        => $restored,
			'size'             => $metadata['filesize'],
			'thumbnails_ready' => is_array( $regenerated ),
		);
	}

	/**
	 * Publish the restored MIME type through WordPress under the attachment lock.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $mime          MIME type of the current file.
	 * @return bool
	 */
	private function set_attachment_mime( int $attachment_id, string $mime ): bool {
		global $wpdb;
		$result = WpOperationLock::guard_write(
			$wpdb->posts,
			static fn () => wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $mime,
				),
				true
			)
		);
		return ! is_wp_error( $result ) && (bool) $result;
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
	 * @param float|null           $deadline Time limit for optional thumbnail encodes.
	 * @return array{variant_path: string, size: int, variants: array<string, string>, widths: array<string, int>, thumbnail_stamps: array<string, string>}|null
	 */
	public function save_sibling_variant(
		int $attachment_id,
		string $source_tmp_path,
		string $target_mime,
		string $target_ext,
		OptimizationSettings $settings,
		?float $deadline = null
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

		$variant = $this->build_variant_path( $original, $target_ext, $attachment_id, false );
		if ( ! $this->publish_file( $filesystem, $source_tmp_path, $variant ) ) {
			return null;
		}

		$relative         = substr( $variant, strlen( $root ) );
		$variants         = array( substr( $original, strlen( $root ) ) => $relative );
		$size             = wp_getimagesize( $variant );
		$widths           = is_array( $size ) ? array( $relative => (int) $size[0] ) : array();
		$thumbnail_stamps = array();

		foreach ( $this->thumbnail_paths( $attachment_id, $original, $deadline ) as $thumbnail ) {
			if ( null !== $deadline && microtime( true ) >= $deadline ) {
				break;
			}
			$stamp       = $this->source_stamp( $thumbnail );
			$source_mime = wp_get_image_mime( $thumbnail );
			if ( '' === $stamp || ! is_string( $source_mime ) || ! WpRasterImage::is_bounded( $thumbnail, $source_mime ) || WpRasterImage::is_animated( $thumbnail, $source_mime ) ) {
				continue;
			}
			$editor = wp_get_image_editor( $thumbnail );
			if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( $target_mime ) ) {
				continue;
			}
			if ( 'image/gif' === $source_mime && ! WpRasterImage::prepare_still_gif( $thumbnail, $editor ) ) {
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
			if ( null !== $deadline && microtime( true ) >= $deadline ) {
				break;
			}
			$destination = $this->build_variant_path( $thumbnail, $target_ext, $attachment_id, true );
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
			if ( $stamp !== $this->source_stamp( $thumbnail ) || wp_get_image_mime( $destination ) !== $target_mime || ( $settings->skip_smaller() && wp_filesize( $destination ) >= wp_filesize( $thumbnail ) ) ) {
				$filesystem->delete( $destination );
				continue;
			}

			$variant_relative                        = substr( $destination, strlen( $root ) );
			$thumbnail_relative                      = substr( $thumbnail, strlen( $root ) );
			$variants[ $thumbnail_relative ]         = $variant_relative;
			$widths[ $variant_relative ]             = (int) ( $saved['width'] ?? 0 );
			$thumbnail_stamps[ $thumbnail_relative ] = $stamp;
		}

		return array(
			'variant_path'     => $relative,
			'size'             => (int) wp_filesize( $variant ),
			'variants'         => $variants,
			'widths'           => $widths,
			'thumbnail_stamps' => $thumbnail_stamps,
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
			function ( string $variant, $source ) use ( $record, $attachment_id ): bool {
				if (
					! is_string( $source )
					|| $variant === $source
					|| validate_file( $source )
					|| validate_file( $variant )
					|| 0 === strpos( $variant, '/' )
					|| dirname( $variant ) !== dirname( $source )
				) {
					return false;
				}
				$ext = preg_quote( $record->format(), '~' );
				if ( $this->is_owned_variant_path( $variant, $attachment_id ) && pathinfo( $variant, PATHINFO_EXTENSION ) === $record->format() ) {
					return true;
				}
				// Keep serving earlier mapped copies with short names. Their ownership is ambiguous.
				if ( 1 === preg_match( '~(?:^|/)[a-zA-Z0-9_-]+-[a-f0-9]{8}\.' . $ext . '$~D', $variant ) ) {
					return true;
				}
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
					$name = is_array( $backup_size ) ? ( $backup_size['file'] ?? '' ) : '';
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
	 * Queue cleanup after a failed save outside the request that wrote the files.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        File owner.
	 * @return void
	 */
	public function discard_sibling_variant( int $attachment_id, OptimizationRecord $record ): void {
		$paths = array_filter( $this->variant_paths( $attachment_id, $record ), array( $this, 'is_owned_variant_path' ) );
		WpVariantRetirement::schedule_removed_files( $paths );
	}

	/**
	 * Remove recorded file paths under the current site's uploads directory.
	 *
	 * @param array<string> $paths    Recorded relative paths.
	 * @param float|null    $deadline End of this cleanup pass.
	 * @return bool
	 */
	public function delete_variant_files( array $paths, ?float $deadline = null ): bool {
		// A different site can reference the same storage. Keep its files when we cannot verify ownership.
		if ( is_multisite() ) {
			return false;
		}
		$filesystem = WpLocalFilesystem::get();
		$root       = $this->uploads_root();
		if ( null === $root ) {
			return empty( $paths );
		}

		$removed    = true;
		$deadline ??= microtime( true ) + 4;
		foreach ( $paths as $relative ) {
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			if ( ! $this->is_owned_variant_path( $relative ) ) {
				continue;
			}
			$file = $root . $relative;
			if ( ! $filesystem->exists( $file ) && ! is_link( $file ) ) {
				continue;
			}
			$referenced = $this->has_attachment_reference( $relative );
			if ( null === $referenced ) {
				return false;
			}
			if ( $referenced ) {
				continue;
			}
			if ( microtime( true ) >= $deadline || ! WpOperationLock::is_current() ) {
				return false;
			}
			if ( $file !== $this->resolve_safe_upload_file( $file ) || ! $filesystem->delete( $file ) ) {
				$removed = false;
			}
		}

		return $removed;
	}

	/**
	 * Screen the library using the core meta key index, without loading metadata values.
	 * @return bool|null Whether the scan fits its budget, or null after a temporary database error.
	 */
	public function can_check_cleanup_references(): ?bool {
		global $wpdb;
		if ( null === $this->cleanup_can_check_references ) {
			// Count only up to the cleanup budget, using the core meta key index without reading meta values.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$over_budget = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} FORCE INDEX (meta_key) WHERE meta_key IN (%s, %s, %s, %s) LIMIT %d, 1",
					'_wp_attached_file',
					'_wp_attachment_metadata',
					'_wp_attachment_backup_sizes',
					self::META_KEY,
					self::MAX_CLEANUP_REFERENCES
				)
			);
			if ( '' !== $wpdb->last_error ) {
				return null;
			}
			$this->cleanup_can_check_references = null === $over_budget;
		}
		return $this->cleanup_can_check_references;
	}

	/**
	 * An imported copy may now be the WordPress original for another attachment.
	 * @param string $relative Upload-relative file.
	 * @return bool|null Whether referenced, or null when the check could not finish safely.
	 */
	private function has_attachment_reference( string $relative ): ?bool {
		global $wpdb;
		if ( true !== $this->can_check_cleanup_references() ) {
			return null;
		}
		// Only scheduled cleanup checks these indexed meta keys. A basename match deliberately keeps ambiguous files.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reference = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s, %s) AND meta_value LIKE %s LIMIT 1",
				'_wp_attached_file',
				'_wp_attachment_metadata',
				'_wp_attachment_backup_sizes',
				self::META_KEY,
				'%' . $wpdb->esc_like( wp_basename( $relative ) ) . '%'
			)
		);
		return '' === $wpdb->last_error ? null !== $reference : null;
	}

	/**
	 * Build a clean, collision-free sibling variant path in the same directory as the source image.
	 *
	 * @param string $source_path   Absolute path to the source image or thumbnail.
	 * @param string $target_ext    Target file extension.
	 * @param int    $attachment_id Attachment ID.
	 * @param bool   $is_thumbnail  Whether the source is a registered sub-size.
	 * @return string
	 */
	private function build_variant_path(
		string $source_path,
		string $target_ext,
		int $attachment_id,
		bool $is_thumbnail
	): string {
		$source_stem = (string) pathinfo( $source_path, PATHINFO_FILENAME );
		$token       = wp_generate_uuid4();

		/**
		 * Filter the base filename (without extension) for a generated optimized variant.
		 *
		 * Kilobyte appends its own attachment ID and a version token for each copy.
		 *
		 * @param string               $stem    Source filename without its extension.
		 * @param array<string, mixed> $context Attachment and variant context.
		 */
		$filtered = apply_filters(
			'kilobyte_io_variant_filename',
			$source_stem,
			array(
				'attachment_id' => $attachment_id,
				'source_stem'   => $source_stem,
				'token'         => $token,
				'extension'     => $target_ext,
				'is_thumbnail'  => $is_thumbnail,
			)
		);

		$normalize = static function ( string $stem ): string {
			return trim( (string) preg_replace( '/[^a-zA-Z0-9_-]+/', '-', remove_accents( $stem ) ), '-' );
		};
		$stem      = is_string( $filtered ) ? $normalize( $filtered ) : '';
		if ( '' === $stem ) {
			$stem = $normalize( $source_stem );
		}
		$stem = '' !== $stem ? substr( $stem, 0, 175 ) : 'image';

		return trailingslashit( dirname( $source_path ) ) . $stem . '.kilobyte-' . $attachment_id . '-' . $token . '.' . $target_ext;
	}

	/**
	 * Confirm a relative upload path matches a Kilobyte-generated variant filename.
	 *
	 * @param mixed    $relative      Relative path under uploads.
	 * @param int|null $attachment_id Require the marker to match a specific attachment.
	 * @return bool
	 */
	public function is_owned_variant_path( $relative, ?int $attachment_id = null ): bool {
		if ( ! is_string( $relative ) || validate_file( $relative ) || 0 === strpos( $relative, '/' ) ) {
			return false;
		}
		$owner = null === $attachment_id ? '(?:\d+-)?' : $attachment_id . '-';
		return 1 === preg_match( '/\.kilobyte-' . $owner . '[a-f0-9-]{36}\.(?:jpe?g|png|gif|bmp|webp|avif)$/iD', $relative );
	}

	/**
	 * Record the pending counter change before Core removes the attachment's metadata.
	 * Keep the stats lock until Core reaches its post-deletion action.
	 *
	 * @param OptimizationRecord $record Record Core is about to remove.
	 * @return callable|null Completion callback, or null when deletion should be retried.
	 */
	public function prepare_record_deletion( OptimizationRecord $record ): ?callable {
		$release = $this->acquire_stats_lock( 0 );
		if ( null === $release ) {
			return null;
		}
		try {
			$this->refresh_stats_option_cache();
			$already_pending = $this->mark_counter_write();
		} catch ( \Throwable $error ) {
			$already_pending = null;
		}
		if ( null === $already_pending ) {
			$release();
			return null;
		}
		return function () use ( $record, $release, $already_pending ): void {
			try {
				$stats = $this->subtract_record( $this->load_counters(), $record );
				if ( WpOperationLock::is_current() && $this->write_counters( $stats ) ) {
					$this->clear_counter_write( $already_pending );
				}
			} catch ( \Throwable $error ) {
				// The saved marker lets a later reconcile repair interrupted accounting.
				return;
			} finally {
				$release();
			}
		};
	}

	/**
	 * Generate thumbnail sizes without calling the full-image generation pipeline.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int|null
	 */
	public function regenerate_thumbnails( int $attachment_id ): ?int {
		$image = $this->find_image( $attachment_id, true );
		if ( empty( $image['source_revision'] ) || ! wp_attachment_is_image( $attachment_id ) ) {
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
		global $wpdb;
		$metadata = $result['metadata'];
		$current  = $this->find_image( $attachment_id, true );
		if ( ( $current['source_revision'] ?? '' ) !== $image['source_revision'] || $current['file'] !== $image['file']
			|| wp_get_attachment_metadata( $attachment_id, true ) !== $previous
			|| ( $previous !== $metadata && ( ! WpOperationLock::is_current() || ! WpOperationLock::guard_write( $wpdb->postmeta, static fn () => wp_update_attachment_metadata( $attachment_id, $metadata ) ) ) )
		) {
			WpVariantRetirement::schedule_removed_files( $result['files'] );
			return null;
		}
		return $result['count'];
	}

	/**
	 * Generate sizes for a file without modifying an attachment record.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $file          Local image.
	 * @param array<string, mixed> $metadata      Metadata to preserve.
	 * @return array{metadata: array<string, mixed>, count: int, files: string[]}|\WP_Error|null
	 */
	private function build_thumbnail_metadata( int $attachment_id, string $file, array $metadata ) {
		$mime = wp_get_image_mime( $file );
		if ( ! is_string( $mime ) || ! WpRasterImage::is_bounded( $file, $mime ) || WpRasterImage::is_animated( $file, $mime ) ) {
			return null;
		}

		$root       = $this->uploads_root();
		$directory  = trailingslashit( dirname( $file ) );
		$work_dir   = $directory . 'kilobyte-work-' . wp_generate_uuid4();
		$filesystem = WpLocalFilesystem::get();
		if ( null === $root || 0 !== strpos( $directory, $root ) || ! $filesystem->mkdir( $work_dir, 0700 ) || realpath( $work_dir ) !== $work_dir ) {
			return null;
		}
		$staging = trailingslashit( $work_dir );
		$files   = array();
		$ready   = false;

		try {
			$source = $staging . wp_basename( $file );
			if ( ! $filesystem->copy( $file, $source ) || wp_filesize( $file ) !== wp_filesize( $source ) ) {
				return null;
			}
			$editor = wp_get_image_editor( $source, array( 'mime_type' => $mime ) );
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
			unset( $editor );
			if ( ! is_array( $generated ) || array_diff_key( $sizes, $generated ) ) {
				return null;
			}
			$generated = array_intersect_key( $generated, $sizes );
			$published = array();
			foreach ( $generated as $slug => $data ) {
				$name = is_array( $data ) ? ( $data['file'] ?? '' ) : '';
				if ( ! is_string( $name ) || '' === $name || wp_basename( $name ) !== $name || validate_file( $name ) ) {
					return null;
				}
				if ( ! isset( $published[ $name ] ) ) {
					$thumbnail = $this->resolve_safe_upload_file( $staging . $name );
					$type      = wp_check_filetype( $name );
					if ( null === $thumbnail || $source === $thumbnail || 0 !== strpos( $thumbnail, $staging )
						|| ! is_string( $type['ext'] ) || ! is_string( $type['type'] ) || wp_get_image_mime( $thumbnail ) !== $type['type']
					) {
						return null;
					}
					$existing = $metadata['sizes'][ $slug ]['file'] ?? '';
					$reusable = $this->reusable_thumbnail( $existing, $file, $thumbnail, $type['type'] );
					if ( null === $reusable ) {
						$destination = $directory . 'thumbnail.kilobyte-' . $attachment_id . '-' . wp_generate_uuid4() . '.' . $type['ext'];
						$relative    = substr( $destination, strlen( $root ) );
						if ( ! $this->is_owned_variant_path( $relative, $attachment_id ) || ! $this->publish_file( $filesystem, $thumbnail, $destination ) ) {
							return null;
						}
						$files[]  = $relative;
						$reusable = wp_basename( $destination );
					}
					$published[ $name ] = $reusable;
				}
				$generated[ $slug ]['file'] = $published[ $name ];
			}
			$metadata['sizes'] = array_replace( (array) ( $metadata['sizes'] ?? array() ), $generated );
			$ready             = true;
			return array(
				'metadata' => $metadata,
				'count'    => count( $generated ),
				'files'    => $files,
			);
		} finally {
			if ( realpath( $work_dir ) === $work_dir ) {
				foreach ( (array) $filesystem->dirlist( $staging ) as $entry ) {
					$name = $entry['name'] ?? '';
					if ( '' !== $name && wp_basename( $name ) === $name && ! validate_file( $name ) && 'd' !== $entry['type'] ) {
						$filesystem->delete( $staging . $name, false, 'f' );
					}
				}
				$filesystem->rmdir( $work_dir );
			}
			if ( ! $ready ) {
				WpVariantRetirement::schedule_removed_files( $files );
			}
		}
	}

	/**
	 * Reuse an existing sub-size if the editor produced exactly the same bytes.
	 *
	 * @param mixed  $name      Existing sub-size name from WordPress.
	 * @param string $original  Full image path.
	 * @param string $generated Newly encoded thumbnail in the work directory.
	 * @param string $mime      MIME type of the generated thumbnail.
	 * @return string|null
	 */
	private function reusable_thumbnail( $name, string $original, string $generated, string $mime ): ?string {
		if ( ! is_string( $name ) || '' === $name || wp_basename( $name ) !== $name || validate_file( $name ) ) {
			return null;
		}
		$type  = wp_check_filetype( $name );
		$path  = trailingslashit( dirname( $original ) ) . $name;
		$bytes = (int) wp_filesize( $generated );
		if ( $type['type'] !== $mime || $path === $original || $this->resolve_safe_upload_file( $path ) !== $path || $bytes <= 0 || (int) wp_filesize( $path ) !== $bytes ) {
			return null;
		}
		$hash = $this->source_hash( $generated );
		return '' !== $hash && $hash === $this->source_hash( $path ) ? $name : null;
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
	 * Read a bounded page of eligible images before an attachment ID.
	 *
	 * @param string $mode     Queue mode.
	 * @param int    $before   Cursor from the previous page, or zero.
	 * @param int    $per_page Page size.
	 * @param bool   $check_permissions Require the current user to be able to edit each attachment.
	 * @return array<string, mixed>
	 */
	public function get_queue_page( string $mode, int $before, int $per_page, bool $check_permissions = true ): array {
		$this->queue_verification_bytes_left = WpRasterImage::MAX_BYTES;
		$per_page                            = max( 1, min( 100, $per_page ) );
		$posts                               = $this->query_images( $before, $per_page + 1 );
		$has_more                            = count( $posts ) > $per_page;
		$posts                               = array_slice( $posts, 0, $per_page );
		$items                               = array();
		$unavailable                         = array();
		$cursor                              = 0;
		$keep_gif                            = 'optimize' === $mode && $this->keeps_gif_format();

		foreach ( $posts as $post ) {
			$attachment_id = (int) $post->ID;
			$cursor        = $attachment_id;
			if ( $check_permissions && ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}

			$record = $this->get_record( $attachment_id );
			if ( 'optimize' === $mode && null !== $record && ( $keep_gif || OptimizationRecord::STATUS_ORIGINAL_FORMAT_KEPT !== $record->status() ) ) {
				$eligible = $record->is_unchanged()
					? '' !== $record->source_revision() && ! $this->checked_source_has_same_stamp( $attachment_id, $record )
					: $this->has_changed_sibling_source( $attachment_id, $record );
				if ( ! $eligible ) {
					continue;
				}
			}
			if ( 'restore' === $mode && ( null === $record || ! $record->is_restorable() ) ) {
				continue;
			}

			$image = $this->find_image( $attachment_id );
			if ( null === $image && 'restore' !== $mode ) {
				$file = (string) get_attached_file( $attachment_id, true );
				if ( '' === $file ) {
					$metadata = wp_get_attachment_metadata( $attachment_id, true );
					$file     = (string) ( $metadata['file'] ?? '' );
				}
				$unavailable[] = array(
					'id'       => $attachment_id,
					'filename' => wp_basename( (string) wp_parse_url( wp_normalize_path( $file ), PHP_URL_PATH ) ),
					'title'    => trim( wp_strip_all_tags( $post->post_title ) ),
					'edit_url' => esc_url_raw( (string) get_edit_post_link( $attachment_id, 'raw' ), array( 'http', 'https' ) ),
				);
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
			'items'             => $items,
			'unavailable_items' => $unavailable,
			'skipped_missing'   => count( $unavailable ),
			'next_before'       => $has_more ? $cursor : 0,
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
				return $this->write_counted_change(
					$original,
					$stats,
					'options',
					static function (): bool {
						return (bool) get_option( self::BACKUPS_PURGED_OPTION ) || update_option( self::BACKUPS_PURGED_OPTION, true, false );
					}
				);
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

				if ( ! WpOperationLock::is_current() || ! $this->write_counters( $stats ) || ! WpOperationLock::is_current() ) {
					return null;
				}
				global $wpdb;
				WpOperationLock::guard_write(
					$wpdb->options,
					static function (): void {
						delete_option( self::COUNTER_WRITE_PENDING );
						delete_option( self::RECONCILE_OPTION );
					}
				);
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
		$pending_gifs    = $counters['original_format_count'] > 0 && ! $this->keeps_gif_format() ? $counters['original_format_count'] : 0;
		$optimized_count = min( $total, max( 0, $counters['optimized_count'] - $pending_gifs ) );
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
			'needs_reconcile'  => (bool) get_option( self::RECONCILE_OPTION ) || (bool) get_option( self::COUNTER_WRITE_PENDING ),
		);
	}

	/**
	 * Determine whether the current preference leaves GIF files in their original format.
	 *
	 * @return bool
	 */
	private function keeps_gif_format(): bool {
		return ImageFormat::ORIGINAL === ( new OptionSettingsRepository() )->load()->format()->value();
	}

	/**
	 * Detect checked sources that changed outside WordPress's metadata hooks.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Completed check.
	 * @return bool
	 */
	private function checked_source_has_same_stamp( int $attachment_id, OptimizationRecord $record ): bool {
		$file = $this->resolve_safe_upload_file( get_attached_file( $attachment_id ) );
		return null !== $file && $this->source_stamp( $file ) === explode( '|', $record->source_revision(), 2 )[0];
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
	 * @param int        $attachment_id Attachment ID.
	 * @param string     $original      Main image path.
	 * @param float|null $deadline      End of the optional encoding pass.
	 * @return string[]
	 */
	private function thumbnail_paths( int $attachment_id, string $original, ?float $deadline = null ): array {
		if ( null !== $deadline && microtime( true ) >= $deadline ) {
			return array();
		}
		$meta  = wp_get_attachment_metadata( $attachment_id, true );
		$dir   = trailingslashit( dirname( $original ) );
		$files = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $data ) {
			if ( null !== $deadline && microtime( true ) >= $deadline ) {
				break;
			}
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
		$release = $this->acquire_stats_lock( 5 );
		if ( null === $release ) {
			return null;
		}
		try {
			$this->refresh_stats_option_cache();
			return $operation();
		} finally {
			$release();
		}
	}

	/**
	 * WordPress callbacks can reenter on the same DB session. Allow only one stats write per site.
	 * @param int $wait Seconds to wait for other requests.
	 * @return callable|null
	 */
	private function acquire_stats_lock( int $wait ): ?callable {
		$site = get_current_blog_id();
		if ( isset( self::$active_stats_writes[ $site ] ) ) {
			return null;
		}
		$release = WpOperationLock::acquire( 'stats', $wait );
		if ( null === $release ) {
			return null;
		}

		self::$active_stats_writes[ $site ] = true;
		return static function () use ( $release, $site ): void {
			try {
				$release();
			} finally {
				unset( self::$active_stats_writes[ $site ] );
			}
		};
	}

	/**
	 * Read stats options afresh once their lock is held.
	 * @return void
	 */
	private function refresh_stats_option_cache(): void {
		wp_cache_delete( self::STATS_OPTION, 'options' );
		wp_cache_delete( self::BACKUPS_PURGED_OPTION, 'options' );
		wp_cache_delete( self::COUNTER_WRITE_PENDING, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Leave a repair marker if execution stops between writing totals and their source record.
	 *
	 * @param array<string, int> $original Previous counters.
	 * @param array<string, int> $stats    New counters.
	 * @param string             $table    WordPress table property targeted by the paired write.
	 * @param callable           $write    Record write to pair with the counter update.
	 * @return bool
	 */
	private function write_counted_change( array $original, array $stats, string $table, callable $write ): bool {
		global $wpdb;
		$already_pending = $this->mark_counter_write();
		if ( null === $already_pending ) {
			return false;
		}
		if ( ! WpOperationLock::is_current() || ! $this->write_counters( $stats ) || ! WpOperationLock::is_current() ) {
			return false;
		}
		$saved = WpOperationLock::guard_write( $wpdb->$table, $write );
		if ( WpOperationLock::is_current() && ( $saved || $this->write_counters( $original ) ) ) {
			$this->clear_counter_write( $already_pending );
		}
		return $saved;
	}

	/**
	 * Persist a repair marker while the stats lock is held.
	 * @return bool|null Prior marker value, or null when it could not be saved.
	 */
	private function mark_counter_write(): ?bool {
		global $wpdb;
		$already_pending = (bool) get_option( self::COUNTER_WRITE_PENDING );
		if ( ! WpOperationLock::is_current() || ( ! $already_pending && ! WpOperationLock::guard_write( $wpdb->options, static fn () => add_option( self::COUNTER_WRITE_PENDING, true, '', false ) ) ) ) {
			return null;
		}
		return $already_pending;
	}

	/**
	 * A completed operation can clear its own marker and leave earlier work for reconcile.
	 * @param bool $already_pending Whether an earlier operation left the marker.
	 * @return void
	 */
	private function clear_counter_write( bool $already_pending ): void {
		global $wpdb;
		if ( ! $already_pending && WpOperationLock::is_current() ) {
			WpOperationLock::guard_write( $wpdb->options, static fn () => delete_option( self::COUNTER_WRITE_PENDING ) );
		}
	}

	/**
	 * Update counters and treat an unchanged value as a successful write.
	 *
	 * @param array<string, int> $stats Updated counters.
	 * @return bool
	 */
	private function write_counters( array $stats ): bool {
		global $wpdb;
		return $stats === $this->load_counters() || WpOperationLock::guard_write( $wpdb->options, static fn () => update_option( self::STATS_OPTION, $stats, false ) );
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
		if ( OptimizationRecord::STATUS_ORIGINAL_FORMAT_KEPT === $record->status() ) {
			++$stats['original_format_count'];
			return $stats;
		}
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
		if ( OptimizationRecord::STATUS_ORIGINAL_FORMAT_KEPT === $record->status() ) {
			$stats['original_format_count'] = max( 0, $stats['original_format_count'] - 1 );
			return $stats;
		}
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
	 * @return array{optimized_count: int, original_format_count: int, total_original: int, total_optimized: int, backup_count: int, backup_bytes: int, restorable_count: int}
	 */
	private function load_counters(): array {
		$raw = get_option( self::STATS_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return array(
			'optimized_count'       => isset( $raw['optimized_count'] ) ? max( 0, (int) $raw['optimized_count'] ) : 0,
			'original_format_count' => max( 0, (int) ( $raw['original_format_count'] ?? 0 ) ),
			'total_original'        => isset( $raw['total_original'] ) ? max( 0, (int) $raw['total_original'] ) : 0,
			'total_optimized'       => isset( $raw['total_optimized'] ) ? max( 0, (int) $raw['total_optimized'] ) : 0,
			'backup_count'          => isset( $raw['backup_count'] ) ? max( 0, (int) $raw['backup_count'] ) : 0,
			'backup_bytes'          => isset( $raw['backup_bytes'] ) ? max( 0, (int) $raw['backup_bytes'] ) : 0,
			'restorable_count'      => isset( $raw['restorable_count'] ) ? max( 0, (int) $raw['restorable_count'] ) : 0,
		);
	}
}
