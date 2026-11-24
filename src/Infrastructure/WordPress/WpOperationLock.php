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

	/** @var string|null Database session that acquired the active locks. */
	private static ?string $session = null;

	/** @var int Number of locks held by this request. */
	private static int $depth = 0;

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

		// SQLite (WordPress Playground and the SQLite Database Integration plugin) has no advisory locks,
		// so operations run without one there. SQLite sites are usually single-user test sites.
		if ( is_a( $wpdb, 'WP_SQLite_DB' ) ) {
			$available = true;
			return static function (): void {};
		}

		$key = 'kilobyte_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $lock_name );

		$previous = $wpdb->suppress_errors();
		try {
			// Advisory locks belong to the current connection and cannot be cached.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_row( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d), CONNECTION_ID()', $key, $wait ), ARRAY_N );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		$acquired  = (string) ( $result[0] ?? '' );
		$session   = (string) ( $result[1] ?? '' );
		$available = in_array( $acquired, array( '0', '1' ), true );
		if ( '1' !== $acquired || '' === $session ) {
			return null;
		}

		if ( null !== self::$session && self::$session !== $session ) {
			self::release( $key, $session );
			return null;
		}

		self::$session = $session;
		++self::$depth;
		return static function () use ( $key, $session ): void {
			try {
				self::release( $key, $session );
			} finally {
				if ( 0 === --self::$depth ) {
					self::$session = null;
				}
			}
		};
	}

	/**
	 * Recheck the lock's session before a write. WordPress can reconnect during a request.
	 *
	 * @return bool
	 */
	public static function is_current(): bool {
		if ( null === self::$session ) {
			return true;
		}

		global $wpdb;
		$previous = $wpdb->suppress_errors();
		try {
			// The current database session must never be read from a cache.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return self::$session === (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Keep a single WordPress API write on the lock-owning connection.
	 * Core can retry the exact SQL after a reconnect, without running the query filter again.
	 *
	 * @param string   $table     WordPress table used by the API call.
	 * @param callable $operation WordPress metadata, post, or option write.
	 * @return mixed
	 */
	public static function guard_write( string $table, callable $operation ) {
		if ( null === self::$session ) {
			return $operation();
		}

		global $wpdb;
		$condition = $wpdb->prepare( 'CONNECTION_ID() = %s', self::$session );
		$target    = '`?' . preg_quote( $table, '/' ) . '`?';
		$upsert    = ' ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)';
		$filter    = static function ( $sql ) use ( $target, $condition, $upsert ) {
			if ( ! is_string( $sql ) ) {
				return $sql;
			}
			if ( preg_match( '/\A(?:UPDATE ' . $target . ' SET |DELETE FROM ' . $target . ' WHERE )/i', $sql ) ) {
				// Core uses a WHERE clause made of equality or IN predicates for these writes.
				return $sql . ' AND ' . $condition;
			}
			if ( preg_match( '/\A(INSERT INTO ' . $target . ' \([^)]*\)) VALUES \((.*?)\)(' . preg_quote( $upsert, '/' ) . ')?\z/is', $sql, $parts ) ) {
				return $parts[1] . ' SELECT ' . $parts[2] . ' FROM DUAL WHERE ' . $condition . ( $parts[3] ?? '' );
			}
			return $sql;
		};
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		add_filter( 'query', $filter, PHP_INT_MIN );
		try {
			return $operation();
		} finally {
			remove_filter( 'query', $filter, PHP_INT_MIN );
		}
	}

	/**
	 * Release only on the connection that acquired this lock.
	 *
	 * @param string $key     Lock name.
	 * @param string $session Database connection ID.
	 * @return void
	 */
	private static function release( string $key, string $session ): void {
		global $wpdb;
		// The session condition also applies if WordPress reconnects while running this query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %s, RELEASE_LOCK(%s), NULL)', $session, $key ) );
	}
}
