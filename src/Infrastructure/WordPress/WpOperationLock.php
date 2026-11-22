<?php
/**
 * Database advisory locks for media operations.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

/**
 * Shares locks across requests and releases them when the database session closes.
 */
final class WpOperationLock {

	/**
	 * Run an operation under a per-site lock.
	 *
	 * @param string   $lock_name Resource name.
	 * @param int      $wait     Seconds to wait for the lock.
	 * @param callable $operation Operation to run.
	 * @return mixed Null if the lock could not be acquired.
	 */
	public static function run( string $lock_name, int $wait, callable $operation ) {
		$release = self::acquire( $lock_name, $wait );
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
	 * Acquire a lock and return its release callback.
	 *
	 * @param string $lock_name Scope of the lock.
	 * @param int    $wait      Seconds to wait.
	 * @param bool|null $available Whether the database answered the lock request.
	 * @return callable|null
	 */
	public static function acquire( string $lock_name, int $wait, ?bool &$available = null ): ?callable {
		global $wpdb;

		$key = 'kilobyte_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $lock_name );

		$previous = $wpdb->suppress_errors();
		try {
			// Advisory locks belong to the current connection and cannot be cached.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$acquired = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, $wait ) );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		$available = in_array( $acquired, array( '0', '1' ), true );
		if ( '1' !== $acquired ) {
			return null;
		}

		return static function () use ( $key, $wpdb ): void {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		};
	}
}
