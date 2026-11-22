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

	public const EVENT = 'kilobyte_io_clean_retired_variants';

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
		if ( ! $record->variants() ) {
			return true;
		}
		$paths = $this->attachments->variant_paths( $attachment_id, $record );
		if ( empty( $paths ) ) {
			return false;
		}

		if ( ! wp_next_scheduled( self::EVENT ) && ! wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::EVENT ) && ! wp_next_scheduled( self::EVENT ) ) {
			return false;
		}

		$pending = $this->pending( $attachment_id );
		foreach ( $paths as $path ) {
			$pending[ $path ] = time() + DAY_IN_SECONDS;
		}
		return $this->save_pending( $attachment_id, $pending );
	}

	/**
	 * Read pending paths before the attachment's metadata is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, int>
	 */
	public function pending( int $attachment_id ): array {
		$pending = get_post_meta( $attachment_id, self::PENDING, true );
		return is_array( $pending ) ? $pending : array();
	}

	/**
	 * Remove due copies without scanning the full Media Library.
	 *
	 * @return void
	 */
	public function clean_due_files(): void {
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
				function () use ( $id, $now ): void {
					$pending   = $this->pending( $id );
					$record    = $this->attachments->get_record( $id );
					$active    = $record ? $this->attachments->variant_paths( $id, $record ) : array();
					$can_clean = null;
					$protected = null;
					foreach ( $pending as $file => $due ) {
						if ( $due > $now ) {
							continue;
						}
						$protected ??= $this->attachments->attachment_image_paths( $id );
						if ( isset( $protected[ $file ] ) || in_array( $file, $active, true ) ) {
							unset( $pending[ $file ] );
							continue;
						}
						$can_clean ??= $this->attachments->has_readable_original( $id );
						if ( $can_clean && $this->attachments->delete_variant_files( array( $file ) ) ) {
							unset( $pending[ $file ] );
						} else {
							$pending[ $file ] = $now + HOUR_IN_SECONDS;
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
	 * @param array<string, int> $pending       Files and due times.
	 * @return bool
	 */
	private function save_pending( int $attachment_id, array $pending ): bool {
		if ( empty( $pending ) ) {
			delete_post_meta( $attachment_id, self::PENDING );
			return true;
		}
		return $pending === $this->pending( $attachment_id ) || (bool) update_post_meta( $attachment_id, self::PENDING, $pending );
	}
}
