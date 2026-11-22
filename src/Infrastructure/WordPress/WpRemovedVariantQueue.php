<?php
/**
 * Durable cleanup work for files that have no attachment record.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

/**
 * Stores bounded batches independently of their WordPress cron event.
 */
final class WpRemovedVariantQueue {

	public const LEGACY_EVENT = 'kilobyte_io_retry_variant_cleanup';

	private const PREFIX     = 'kilobyte_io_cleanup_batch_';
	private const BATCH_SIZE = 20;

	/**
	 * Store candidate paths before scheduling cleanup.
	 *
	 * @param string[] $paths         Candidate paths under uploads.
	 * @param int      $due           Earliest cleanup time.
	 * @param int      $deleted_image Wait for Core to delete this attachment, if specified.
	 * @return bool Whether all paths have durable storage.
	 */
	public static function enqueue( array $paths, int $due, int $deleted_image = 0 ): bool {
		$paths = array_values( array_unique( array_filter( $paths, 'is_string' ) ) );
		sort( $paths, SORT_STRING );
		$saved = true;
		foreach ( array_chunk( $paths, self::BATCH_SIZE ) as $batch ) {
			$job = array(
				'paths'         => $batch,
				'deleted_image' => $deleted_image,
			);
			$key = self::PREFIX . sprintf( '%012d', $due ) . '_' . hash( 'sha256', $deleted_image . "\0" . implode( "\0", $batch ) );
			if ( ! add_option( $key, $job, '', false ) && get_option( $key ) !== $job ) {
				$saved = false;
			}
		}
		return $saved;
	}

	/**
	 * Read a few batches in due-time order from the option name index.
	 *
	 * @param int      $limit Maximum batch count.
	 * @param int|null $due   Latest eligible time, or null to include future batches.
	 * @return string[]
	 */
	public static function batch_keys( int $limit, ?int $due = null ): array {
		global $wpdb;
		$pattern = $wpdb->esc_like( self::PREFIX ) . '%';
		// Each batch is non-autoloaded. The prefix and time bounds use the option name index.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s ORDER BY option_name LIMIT %d",
				$pattern,
				self::PREFIX . ( null === $due ? 'z' : sprintf( '%012d', $due + 1 ) ),
				$limit
			)
		);
	}

	/**
	 * Preserve path lists from older versions before their events are removed.
	 *
	 * @param int|null $limit Maximum events to migrate on a regular admin request.
	 * @param bool     $pause Preserve work while inactive if per-batch storage is unavailable.
	 * @return void
	 */
	public static function retain_legacy_events( ?int $limit = null, bool $pause = false ): void {
		// WordPress exposes individual events by their known args. Migration needs the old args themselves.
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ self::LEGACY_EVENT ] ?? array() as $event ) {
				if ( null !== $limit && $limit-- <= 0 ) {
					return;
				}
				$args = $event['args'] ?? array();
				if ( ! is_array( $args[0] ?? null ) ) {
					continue;
				}
				$due   = (int) ( $args[3] ?? $timestamp );
				$image = (int) ( $args[2] ?? 0 );
				try {
					$saved = self::enqueue( $args[0], $due, $image );
				} catch ( \Throwable $error ) {
					$saved = false;
				}
				if ( ! $saved && $pause && ! isset( $args[3] ) ) {
					// Core removes single events while the plugin is inactive. A weekly event retains its arguments.
					$paused = array( $args[0], (int) ( $args[1] ?? 0 ), $image, $due );
					$saved  = (bool) wp_next_scheduled( self::LEGACY_EVENT, $paused )
						|| (bool) wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::LEGACY_EVENT, $paused );
				}
				if ( $saved ) {
					wp_unschedule_event( (int) $timestamp, self::LEGACY_EVENT, $args );
				}
			}
		}
	}
}
