<?php
/**
 * The database locks that keep two PHP requests from working on the same thing at once.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Uploads;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/UploadTestCase.php';

final class OperationLockTest extends UploadTestCase {

	public function test_optimizing_releases_the_image_and_stats_locks_it_took_one_inside_the_other(): void {
		$id = $this->upload( Images::jpeg() );

		$this->assertRestOk( $this->optimize_on_server( $id ) );

		// Each call fails the test if this request still holds the lock.
		$this->hold_lock_in_other_request( "image:{$id}" );
		$this->hold_lock_in_other_request( 'stats' );
	}

	public function test_the_worker_releases_its_lock_when_it_finishes(): void {
		$id = $this->upload( Images::jpeg() );
		$this->make_upload_job_due( $id );

		$this->run_background_worker();

		$this->hold_lock_in_other_request( 'automatic_uploads' );
		$this->hold_lock_in_other_request( "image:{$id}" );
	}

	public function test_a_lock_taken_on_a_new_connection_while_an_older_one_is_held_is_refused_and_given_back(): void {
		$id = $this->upload( Images::jpeg() );
		$this->make_upload_job_due( $id );
		// WordPress reconnects after the worker took its own lock and before it locks the image.
		$this->answer_from_new_connection_for( $this->lock_key( "image:{$id}" ) );

		$this->run_background_worker();

		$this->assertSame( self::QUEUED, $this->media_library_status( $id ) );
		$this->hold_lock_in_other_request( "image:{$id}" );
	}

	public function test_a_new_stats_row_that_reaches_the_database_on_another_connection_is_not_written(): void {
		$id = $this->upload( Images::jpeg() );
		// Every lock check passes, but WordPress reconnected and re-sent each write on a new
		// connection that does not hold the lock. The first such write adds an option row.
		$this->answer_from_new_connection_for( '' );

		$this->assertRestFailure( 'write_failed', 500, $this->optimize_on_server( $id ) );

		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertSame( 0, $this->assertRestOk( $this->rest( 'GET', '/stats' ) )['optimized_count'] );
		$this->hold_lock_in_other_request( 'stats' );
	}

	public function test_a_stats_update_that_reaches_the_database_on_another_connection_is_not_written(): void {
		$this->assertRestOk( $this->optimize_on_server( $this->upload( Images::jpeg() ) ) );
		// An earlier request stopped between writing the totals and the record, so the
		// next write goes straight to updating the existing totals row.
		update_option( 'kilobyte_io_counter_write_pending', true, false );
		$id = $this->upload( Images::jpeg() );
		$this->answer_from_new_connection_for( '' );

		$this->assertRestFailure( 'write_failed', 500, $this->optimize_on_server( $id ) );

		$this->assertSame( array(), $this->delivered_copies( $id ) );
		$this->assertSame( 1, $this->assertRestOk( $this->rest( 'GET', '/stats' ) )['optimized_count'] );
	}

	/**
	 * Makes lock requests and lock checks report a database connection other than the real one,
	 * as they would after WordPress reconnects. Writes still run on the real connection.
	 *
	 * @param string $lock_key Only shift queries naming this lock key, or every lock query when empty.
	 * @return void
	 */
	private function answer_from_new_connection_for( string $lock_key ): void {
		add_filter(
			'query',
			static function ( $sql ) use ( $lock_key ) {
				if ( ! is_string( $sql ) || 0 !== strpos( $sql, 'SELECT ' ) || false === strpos( $sql, 'CONNECTION_ID()' ) ) {
					return $sql;
				}
				if ( '' !== $lock_key && false === strpos( $sql, $lock_key ) ) {
					return $sql;
				}
				return str_replace( 'CONNECTION_ID()', '(CONNECTION_ID() + 1000)', $sql );
			}
		);
	}

	/**
	 * The database lock name the plugin uses for one of its locks.
	 *
	 * @param string $lock_name Plugin lock name.
	 * @return string
	 */
	private function lock_key( string $lock_name ): string {
		global $wpdb;
		return 'kilobyte_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $lock_name );
	}
}
