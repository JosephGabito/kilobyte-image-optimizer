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

	private const PENDING         = '_kilobyte_io_retired_variants';
	private const CURSOR          = 'kilobyte_io_cleanup_before';
	private const RECOVERY_CHECK  = 'kilobyte_io_cleanup_recovery_checked';
	private const REFERENCE_PAUSE = 'kilobyte_io_cleanup_reference_pause';

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
			$pending = $this->pending( $attachment_id );
			foreach ( $paths as $path ) {
				$pending[ $path ] = array(
					'due'         => time() + DAY_IN_SECONDS,
					'source_hash' => $hash,
				);
			}
			if ( ! $this->save_pending( $attachment_id, $pending ) ) {
				return false;
			}
		} catch ( \Throwable $error ) {
			// Cleanup hooks are optional. The previous files can safely stay in place.
			return false;
		}
		try {
			self::schedule_worker();
		} catch ( \Throwable $error ) {
			// Scheduling can recover independently of this saved cleanup work.
			return true;
		}
		return true;
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
	 * @param string[] $paths         Candidate file paths.
	 * @param int      $deleted_image Wait for Core to delete this attachment, if specified.
	 * @return bool Whether the cleanup work has been saved.
	 */
	public static function schedule_removed_files( array $paths, int $deleted_image = 0 ): bool {
		if ( ! $paths || is_multisite() ) {
			return true;
		}
		return self::adopt_legacy_event( $paths, 0, $deleted_image );
	}

	/**
	 * Adopt one-time jobs from older versions, or reschedule their arguments if storage fails.
	 * @param string[] $paths          Candidate file paths.
	 * @param int      $legacy_attempt Retry count stored by older versions.
	 * @param int      $deleted_image  Attachment that must be gone before cleanup.
	 * @param int|null $paused_due     Earliest cleanup time for a job paused during deactivation.
	 * @return bool Whether the cleanup work has been saved.
	 */
	public static function adopt_legacy_event( array $paths, int $legacy_attempt = 0, int $deleted_image = 0, ?int $paused_due = null ): bool {
		$stored = false;
		$args   = $deleted_image > 0 ? array( $paths, $legacy_attempt, $deleted_image ) : array( $paths, $legacy_attempt );
		try {
			$stored = WpRemovedVariantQueue::enqueue( $paths, $paused_due ?? time() + 10, $deleted_image );
			if ( $stored ) {
				if ( null !== $paused_due ) {
					wp_clear_scheduled_hook( WpRemovedVariantQueue::LEGACY_EVENT, array( $paths, $legacy_attempt, $deleted_image, $paused_due ) );
				}
				if ( ! is_multisite() ) {
					self::schedule_worker();
				}
				return true;
			}
		} catch ( \Throwable $error ) {
			if ( $stored ) {
				return true;
			}
		}
		if ( null !== $paused_due ) {
			// WordPress has already scheduled the next occurrence of the paused job.
			return false;
		}
		try {
			return (bool) wp_next_scheduled( WpRemovedVariantQueue::LEGACY_EVENT, $args )
				|| wp_schedule_single_event( time() + HOUR_IN_SECONDS, WpRemovedVariantQueue::LEGACY_EVENT, $args );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Recover persisted work on activation or after a temporary scheduling failure.
	 *
	 * @param bool $activation Check immediately when enabling the plugin.
	 * @return void
	 */
	public static function resume_if_needed( bool $activation = false ): void {
		try {
			if ( ! $activation && get_transient( self::RECOVERY_CHECK ) ) {
				return;
			}
			set_transient( self::RECOVERY_CHECK, true, MINUTE_IN_SECONDS );
			WpRemovedVariantQueue::retain_legacy_events( $activation ? null : 5 );
			if ( is_multisite() || wp_next_scheduled( self::EVENT ) ) {
				return;
			}
			if ( self::has_retirement_work() || WpRemovedVariantQueue::batch_keys( 1 ) ) {
				self::schedule_worker();
			}
		} catch ( \Throwable $error ) {
			// An admin visit can retry independently of a missing event.
			return;
		}
	}

	/**
	 * Ensure that the durable cleanup work has a scheduled worker.
	 *
	 * @return bool
	 */
	private static function schedule_worker(): bool {
		return (bool) wp_next_scheduled( self::EVENT )
			|| wp_schedule_event( time() + 10, 'hourly', self::EVENT )
			|| (bool) wp_next_scheduled( self::EVENT );
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
		if ( get_transient( self::REFERENCE_PAUSE ) ) {
			return;
		}
		WpOperationLock::run(
			'variant_cleanup',
			0,
			function (): void {
				$started = microtime( true );
				$removed = WpRemovedVariantQueue::batch_keys( 5, time() );
				if ( ! $removed && ! self::has_retirement_work() ) {
					return;
				}
				$capacity = $this->attachments->can_check_cleanup_references();
				if ( false === $capacity ) {
					// Recheck large libraries daily. Their jobs stay where they are.
					set_transient( self::REFERENCE_PAUSE, true, DAY_IN_SECONDS );
				}
				if ( true !== $capacity ) {
					return;
				}
				$this->clean_removed_batches( $removed, $started + 2 );
				if ( microtime( true ) < $started + 4 && WpOperationLock::is_current() ) {
					$this->clean_retired_variants( $started + 4 );
				}
			}
		);
	}

	/**
	 * Check the indexed retirement key before scanning for file references.
	 * @return bool
	 */
	private static function has_retirement_work(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::PENDING ) );
	}

	/**
	 * Keep each batch until it is safe to acknowledge or it has been saved for retry.
	 *
	 * @param string[] $keys     Due batch keys.
	 * @param float    $deadline End of this pass.
	 * @return void
	 */
	private function clean_removed_batches( array $keys, float $deadline ): void {
		global $wpdb;
		$now = time();
		foreach ( $keys as $key ) {
			if ( microtime( true ) >= $deadline || ! WpOperationLock::is_current() ) {
				break;
			}
			$batch = get_option( $key );
			$paths = $batch['paths'] ?? null;
			if ( ! is_array( $paths ) ) {
				continue;
			}
			$attachment = (int) ( $batch['deleted_image'] ?? 0 );
			$complete   = $attachment > 0
				? $this->attachments->with_attachment_lock(
					$attachment,
					fn (): bool => $this->clean_deleted_attachment( $attachment, $paths, $deadline )
				)
				: $this->attachments->delete_variant_files( $paths, $deadline );
			if ( WpOperationLock::is_current() && ( $complete || WpRemovedVariantQueue::enqueue( $paths, $now + HOUR_IN_SECONDS, $attachment ) ) ) {
				WpOperationLock::guard_write( $wpdb->options, static fn () => delete_option( $key ) );
			}
		}
	}

	/**
	 * Wait for Core's deletion, or finish an aborted job if its paths are still tracked.
	 * @param int      $attachment Attachment ID.
	 * @param string[] $paths      Copies captured before Core deleted the attachment.
	 * @param float    $deadline   End of this pass.
	 * @return bool
	 */
	private function clean_deleted_attachment( int $attachment, array $paths, float $deadline ): bool {
		global $wpdb;
		// The primary key lookup must bypass a stale post object after an interrupted deletion.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $attachment ) );
		if ( '' !== $wpdb->last_error || ! WpOperationLock::is_current() ) {
			return false;
		}
		if ( null === $id ) {
			return $this->attachments->delete_variant_files( $paths, $deadline );
		}
		$tracked = array_keys( $this->pending( $attachment ) );
		$record  = $this->attachments->get_record( $attachment );
		if ( null !== $record ) {
			$tracked = array_merge( $tracked, array_values( $this->attachments->variant_paths( $attachment, $record ) ) );
		}
		return ! array_diff( $paths, $tracked );
	}

	/**
	 * Verify the source and remove due copies from a bounded page of attachments.
	 *
	 * @param float $deadline End of this pass.
	 * @return void
	 */
	private function clean_retired_variants( float $deadline ): void {
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
		$last = 0;
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
						if ( hash_equals( (string) $entry['source_hash'], $checksum ) && $this->attachments->delete_variant_files( array( $file ), $deadline ) ) {
							unset( $pending[ $file ] );
						} else {
							$pending[ $file ]['due'] = $now + HOUR_IN_SECONDS;
						}
					}
					if ( WpOperationLock::is_current() ) {
						$this->save_pending( $id, $pending );
					}
				}
			);
		}
		$last_in_page = (int) end( $query->posts );
		$next         = 100 === count( $query->posts ) || $last_in_page !== $last ? $last : 0;
		if ( WpOperationLock::is_current() ) {
			update_option( self::CURSOR, $next, false );
		}
	}

	/**
	 * Store remaining paths and their cleanup dates.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array<string, array{due: int, source_hash: string}|int> $pending Files and due times.
	 * @return bool
	 */
	private function save_pending( int $attachment_id, array $pending ): bool {
		$current = $this->pending( $attachment_id );
		if ( $pending === $current ) {
			return true;
		}
		if ( ! WpOperationLock::is_current() ) {
			return false;
		}
		global $wpdb;
		if ( empty( $pending ) ) {
			return WpOperationLock::guard_write( $wpdb->postmeta, static fn () => delete_post_meta( $attachment_id, self::PENDING ) );
		}
		return (bool) WpOperationLock::guard_write( $wpdb->postmeta, static fn () => update_post_meta( $attachment_id, self::PENDING, $pending ) );
	}
}
