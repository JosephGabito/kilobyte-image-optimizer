<?php
/**
 * Deferred cleanup for restored images referenced by cached pages.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

use Kilobyte\ImageOptimizer\Domain\Repository\VariantRetirementInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use WP_Query;

/**
 * Keeps restored copies for a day, then cleans them up in bounded batches.
 */
final class WpVariantRetirement implements VariantRetirementInterface {

	public const EVENT         = 'kilobyte_io_clean_retired_variants';
	public const REMOVED_EVENT = 'kilobyte_io_retry_variant_cleanup';

	private const PENDING = '_kilobyte_io_retired_variants';
	private const CURSOR  = 'kilobyte_io_cleanup_before';

	/** @var WpAttachmentRepository */
	private WpAttachmentRepository $attachments;

	/**
	 * @param WpAttachmentRepository $attachments Attachment storage.
	 */
	public function __construct( WpAttachmentRepository $attachments ) {
		$this->attachments = $attachments;
	}

	/**
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Record owning the files.
	 * @return bool
	 */
	public function retire( int $attachment_id, OptimizationRecord $record ): bool {
		// Older releases used generic filenames that other media tools may also own.
		$hash = $record->source_hash();
		if ( '' === $hash || ! $record->variants() || is_multisite() ) {
			return false;
		}
		$paths = array_filter( $this->attachments->variant_paths( $attachment_id, $record ), array( $this->attachments, 'is_owned_variant_path' ) );
		if ( empty( $paths ) ) {
			return false;
		}

		try {
			if ( ! wp_next_scheduled( self::EVENT ) && ! wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::EVENT ) && ! wp_next_scheduled( self::EVENT ) ) {
				return false;
			}

			$pending = $this->pending( $attachment_id );
			foreach ( $paths as $path ) {
				$pending[ $path ] = array(
					'due'         => time() + DAY_IN_SECONDS,
					'source_hash' => $hash,
				);
			}
			return $this->save_pending( $attachment_id, $pending );
		} catch ( \Throwable $error ) {
			// Cleanup hooks are optional. The previous files can safely stay in place.
			return false;
		}
	}

	/**
	 * Preserve copies if the current image differs from the original that produced them.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Record owning the files.
	 * @return bool
	 */
	public function preserve( int $attachment_id, OptimizationRecord $record ): bool {
		$pending = $this->pending( $attachment_id );
		if ( ! $pending ) {
			return true;
		}
		$paths   = $this->attachments->variant_paths( $attachment_id, $record );
		$paths[] = $record->variant_path();
		return $this->save_pending( $attachment_id, array_diff_key( $pending, array_fill_keys( $paths, true ) ) );
	}

	/**
	 * Read pending paths before the attachment's metadata is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, array{due: int, source_hash: string}|int>
	 */
	public function pending( int $attachment_id ): array {
		$pending = get_post_meta( $attachment_id, self::PENDING, true );
		return is_array( $pending ) ? $pending : array();
	}

	/**
	 * Schedule a bounded cleanup after a failed save or attachment deletion.
	 *
	 * @param string[] $paths   Candidate file paths.
	 * @param int      $attempt Previous cleanup attempts.
	 * @return void
	 */
	public static function schedule_removed_files( array $paths, int $attempt = 0 ): void {
		if ( is_multisite() ) {
			return;
		}
		$delay = $attempt > 0 ? HOUR_IN_SECONDS : 10;
		try {
			foreach ( array_chunk( array_values( $paths ), 20 ) as $batch ) {
				wp_schedule_single_event( time() + $delay, self::REMOVED_EVENT, array( $batch, $attempt ) );
			}
		} catch ( \Throwable $error ) {
			// Leave the files in place if a cleanup hook fails.
			return;
		}
	}

	/**
	 * Retry a bounded cleanup outside the original request.
	 *
	 * @param string[] $paths   Candidate file paths.
	 * @param int      $attempt Previous cleanup attempts.
	 * @return void
	 */
	public function clean_removed_files( array $paths, int $attempt ): void {
		if ( ! $this->attachments->delete_variant_files( $paths ) && $attempt < 3 ) {
			self::schedule_removed_files( $paths, $attempt + 1 );
		}
	}

	/**
	 * Remove due copies without scanning the full Media Library.
	 *
	 * @return void
	 */
	public function clean_due_files(): void {
		if ( is_multisite() ) {
			wp_clear_scheduled_hook( self::EVENT );
			return;
		}
		$now    = time();
		$before = (int) get_option( self::CURSOR, 0 );
		$filter = static function ( string $where, WP_Query $query ): string {
			$cursor = (int) $query->get( 'kilobyte_io_retirement_before' );
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
					'post_type'                     => 'attachment',
					'post_status'                   => array( 'inherit', 'trash', 'private' ),
					'fields'                        => 'ids',
					'posts_per_page'                => 100,
					'no_found_rows'                 => true,
					'orderby'                       => 'ID',
					'order'                         => 'DESC',
					// This scheduled batch uses the postmeta key index with no meta value comparison.
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'                      => self::PENDING,
					'kilobyte_io_retirement_before' => $before,
				)
			);
		} finally {
			remove_filter( 'posts_where', $filter, 10 );
		}
		$deadline = microtime( true ) + 4;
		$last     = 0;
		foreach ( $query->posts as $attachment_id ) {
			if ( $last > 0 && microtime( true ) >= $deadline ) {
				break;
			}
			$id   = (int) $attachment_id;
			$last = $id;
			$this->attachments->with_attachment_lock(
				$id,
				function () use ( $id, $now, $deadline ): void {
					$pending   = $this->pending( $id );
					$record    = $this->attachments->get_record( $id );
					$active    = $record ? $this->attachments->variant_paths( $id, $record ) : array();
					$checksum  = null;
					$protected = null;
					foreach ( $pending as $file => $entry ) {
						if ( microtime( true ) >= $deadline ) {
							break;
						}
						// Earlier retirement records did not identify the original. Keep those files.
						if ( ! $this->attachments->is_owned_variant_path( $file ) || ! is_array( $entry ) || empty( $entry['source_hash'] ) ) {
							unset( $pending[ $file ] );
							continue;
						}
						if ( (int) ( $entry['due'] ?? 0 ) > $now ) {
							continue;
						}
						$protected ??= $this->attachments->attachment_image_paths( $id );
						if ( isset( $protected[ $file ] ) || in_array( $file, $active, true ) ) {
							unset( $pending[ $file ] );
							continue;
						}
						$checksum ??= $this->attachments->find_image( $id, true )['source_hash'] ?? '';
						if ( hash_equals( (string) $entry['source_hash'], $checksum ) && $this->attachments->delete_variant_files( array( $file ) ) ) {
							unset( $pending[ $file ] );
						} else {
							$pending[ $file ]['due'] = $now + HOUR_IN_SECONDS;
						}
					}
					$this->save_pending( $id, $pending );
				}
			);
		}
		$last_in_page = (int) end( $query->posts );
		$next         = 100 === count( $query->posts ) || $last_in_page !== $last ? $last : 0;
		update_option( self::CURSOR, $next, false );
	}

	/**
	 * Store remaining paths and their cleanup dates.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array<string, array{due: int, source_hash: string}|int> $pending Files and due times.
	 * @return bool
	 */
	private function save_pending( int $attachment_id, array $pending ): bool {
		if ( empty( $pending ) ) {
			return ! metadata_exists( 'post', $attachment_id, self::PENDING ) || delete_post_meta( $attachment_id, self::PENDING );
		}
		return $pending === $this->pending( $attachment_id ) || (bool) update_post_meta( $attachment_id, self::PENDING, $pending );
	}
}
