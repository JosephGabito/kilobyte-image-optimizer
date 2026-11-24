<?php
/**
 * Originals that older plugin versions moved into uploads/kilobyte-backups: restoring them,
 * and clearing their references with Delete backups once the folder is empty.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Library;

use Kilobyte\ImageOptimizer\Tests\Support\Images;

require_once __DIR__ . '/LibraryTestCase.php';

final class LegacyBackupsTest extends LibraryTestCase {

	/**
	 * Symbolic links a test made, removed before the uploads folder is cleaned.
	 *
	 * @var string[]
	 */
	private array $links = array();

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
	}

	public function tear_down(): void {
		foreach ( $this->links as $link ) {
			unlink( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
		$this->links = array();
		parent::tear_down();
	}

	public function test_backups_from_an_older_version_appear_in_the_totals_after_a_reconcile(): void {
		$id = $this->upload( Images::jpeg() );
		$this->arrange_legacy_in_place_record( $id );

		$this->run_cli_stats_reconcile();
		$stats = $this->stats();

		$this->assertSame( 1, $stats['backup_count'] );
		$this->assertSame( 1000, $stats['backup_bytes'] );
		$this->assertSame( 1, $stats['restorable_count'] );
	}

	public function test_restoring_puts_the_backed_up_original_back_in_the_media_library(): void {
		$id     = $this->upload( Images::jpeg( 640, 480, 'photo.jpg' ) );
		$backup = $this->put_legacy_backup( Images::jpeg( 320, 240, 'photo.jpg' ), '2024/01/photo.jpg' );
		$this->arrange_legacy_in_place_record( $id, array( 'backup_bytes' => filesize( $backup ) ) );
		$this->run_cli_stats_reconcile();

		$restored = $this->assertRestOk( $this->rest( 'POST', "/restore/{$id}" ) );

		$this->assertTrue( $restored['restored'] );
		$this->assertSame( 'in_place', $restored['mode'] );
		$this->assertSame( hash_file( 'sha256', $backup ), hash_file( 'sha256', (string) get_attached_file( $id ) ) );
		$this->assertFileExists( $backup, 'The legacy backup is kept after a restore.' );
		$stats = $this->stats();
		$this->assertSame( 0, $stats['backup_count'] );
		$this->assertSame( 0, $stats['restorable_count'] );
	}

	public function test_a_backup_file_that_is_missing_cannot_be_restored(): void {
		$id = $this->upload( Images::jpeg() );
		$this->arrange_legacy_in_place_record( $id );

		$this->assertRestFailure( 'missing_backup', 404, $this->rest( 'POST', "/restore/{$id}" ) );
	}

	public function test_a_backup_path_outside_the_backup_folder_is_not_used(): void {
		$id = $this->upload( Images::jpeg( 640, 480, 'photo.jpg' ) );
		// The record points at the image itself, which is not a backup.
		$this->arrange_legacy_in_place_record( $id, array( 'backup_path' => _wp_relative_upload_path( (string) get_attached_file( $id ) ) ) );

		$this->assertRestFailure( 'missing_backup', 404, $this->rest( 'POST', "/restore/{$id}" ) );
	}

	public function test_a_backup_path_that_climbs_out_of_the_backup_folder_is_not_used(): void {
		$id = $this->upload( Images::jpeg( 640, 480, 'photo.jpg' ) );
		$this->put_legacy_backup( Images::jpeg( 320, 240 ), '2024/01/photo.jpg' );
		$relative = _wp_relative_upload_path( (string) get_attached_file( $id ) );
		$this->arrange_legacy_in_place_record( $id, array( 'backup_path' => 'kilobyte-backups/../' . $relative ) );

		$this->assertRestFailure( 'missing_backup', 404, $this->rest( 'POST', "/restore/{$id}" ) );
	}

	public function test_a_backup_that_is_a_link_to_another_file_is_not_used(): void {
		$id     = $this->upload( Images::jpeg( 640, 480, 'photo.jpg' ) );
		$folder = $this->backup_folder() . '/2024/01';
		wp_mkdir_p( $folder );
		$this->link( Images::jpeg( 320, 240 ), $folder . '/photo.jpg' );
		$this->arrange_legacy_in_place_record( $id );

		$this->assertRestFailure( 'missing_backup', 404, $this->rest( 'POST', "/restore/{$id}" ) );
	}

	public function test_a_backup_folder_that_is_a_link_is_not_used(): void {
		$id      = $this->upload( Images::jpeg( 640, 480, 'photo.jpg' ) );
		$outside = dirname( Images::jpeg( 320, 240, 'photo.jpg' ) );
		$this->link( $outside, $this->backup_folder() );
		$this->arrange_legacy_in_place_record( $id, array( 'backup_path' => 'kilobyte-backups/photo.jpg' ) );

		$this->assertRestFailure( 'missing_backup', 404, $this->rest( 'POST', "/restore/{$id}" ) );
	}

	public function test_delete_backups_is_refused_while_the_backup_folder_still_holds_originals(): void {
		$id = $this->upload( Images::jpeg() );
		$this->put_legacy_backup( Images::jpeg(), 'photos/january/photo.jpg' );
		$this->arrange_legacy_in_place_record( $id, array( 'backup_path' => 'kilobyte-backups/photos/january/photo.jpg' ) );
		$this->run_cli_stats_reconcile();

		$this->assertRestFailure( 'backups_protected', 409, $this->rest( 'POST', '/backups/purge' ) );

		$this->assertSame( 1, $this->stats()['backup_count'] );
		$this->assertSame( array( $id ), $this->ids_in( $this->queue( 'restore' ) ) );
	}

	public function test_an_original_at_the_top_of_the_backup_folder_also_blocks_delete_backups(): void {
		$this->put_legacy_backup( Images::jpeg(), 'photo.jpg' );

		$this->assertRestFailure( 'backups_protected', 409, $this->rest( 'POST', '/backups/purge' ) );
	}

	public function test_delete_backups_clears_the_references_once_the_originals_are_archived(): void {
		$id     = $this->upload( Images::jpeg() );
		$backup = $this->put_legacy_backup( Images::jpeg(), 'photos/january/photo.jpg' );
		$this->arrange_legacy_in_place_record( $id, array( 'backup_path' => 'kilobyte-backups/photos/january/photo.jpg' ) );
		$this->run_cli_stats_reconcile();
		// The site owner moves the originals somewhere safe, leaving the empty folders behind.
		unlink( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

		$result = $this->assertRestOk( $this->rest( 'POST', '/backups/purge' ) );

		$this->assertSame(
			array(
				'purged'      => true,
				'freed_bytes' => 0,
			),
			$result
		);
		$stats = $this->stats();
		$this->assertSame( 0, $stats['backup_count'] );
		$this->assertSame( 0, $stats['backup_bytes'] );
		$this->assertSame( 0, $stats['restorable_count'] );
		$this->assertSame( 1, $stats['optimized_count'], 'The image still counts as optimized.' );
		$this->assertSame( 1000, $stats['total_original'] );
		$this->assertSame( array(), $this->ids_in( $this->queue( 'restore' ) ) );
		$this->assertRestFailure( 'no_backup', 404, $this->rest( 'POST', "/restore/{$id}" ) );
	}

	public function test_a_reconcile_after_delete_backups_does_not_bring_the_backups_back(): void {
		$id = $this->upload( Images::jpeg() );
		$this->arrange_legacy_in_place_record( $id );
		$this->assertRestOk( $this->rest( 'POST', '/backups/purge' ) );

		$this->run_cli_stats_reconcile();
		$stats = $this->stats();

		$this->assertSame( 0, $stats['backup_count'] );
		$this->assertSame( 0, $stats['restorable_count'] );
		$this->assertSame( 1, $stats['optimized_count'] );
	}

	public function test_delete_backups_keeps_copies_that_can_still_be_removed_with_restore(): void {
		$legacy    = $this->upload( Images::jpeg() );
		$optimized = $this->upload( Images::jpeg( 320, 240 ) );
		$this->arrange_legacy_in_place_record( $legacy );
		$this->run_cli_stats_reconcile();
		$this->assertRestOk( $this->optimize_on_server( $optimized ) );

		$this->assertRestOk( $this->rest( 'POST', '/backups/purge' ) );

		$this->assertSame( 1, $this->stats()['restorable_count'] );
		$this->assertSame( array( $optimized ), $this->ids_in( $this->queue( 'restore' ) ) );
	}

	public function test_delete_backups_succeeds_when_there_never_was_a_backup_folder(): void {
		$this->assertSame(
			array(
				'purged'      => true,
				'freed_bytes' => 0,
			),
			$this->assertRestOk( $this->rest( 'POST', '/backups/purge' ) ) 
		);
	}

	public function test_delete_backups_can_run_twice(): void {
		$this->assertRestOk( $this->rest( 'POST', '/backups/purge' ) );

		$this->assertTrue( $this->assertRestOk( $this->rest( 'POST', '/backups/purge' ) )['purged'] );
	}

	public function test_the_guard_files_older_versions_left_do_not_block_delete_backups(): void {
		$this->put_legacy_backup( Images::not_an_image( 'guard.txt' ), 'index.php' );
		$this->put_legacy_backup( Images::not_an_image( 'guard.txt' ), '.htaccess' );
		wp_mkdir_p( $this->backup_folder() . '/photos/empty' );

		$this->assertRestOk( $this->rest( 'POST', '/backups/purge' ) );
	}

	public function test_a_guard_file_name_inside_a_subfolder_is_treated_as_an_original(): void {
		$this->put_legacy_backup( Images::not_an_image( 'guard.txt' ), 'photos/index.php' );

		$this->assertRestFailure( 'backups_protected', 409, $this->rest( 'POST', '/backups/purge' ) );
	}

	public function test_a_link_inside_the_backup_folder_stops_delete_backups(): void {
		wp_mkdir_p( $this->backup_folder() );
		$this->link( dirname( Images::jpeg() ), $this->backup_folder() . '/elsewhere' );

		$this->assertRestFailure( 'purge_failed', 500, $this->rest( 'POST', '/backups/purge' ) );
	}

	public function test_a_backup_folder_that_is_a_link_stops_delete_backups(): void {
		$this->link( dirname( Images::jpeg() ), $this->backup_folder() );

		$this->assertRestFailure( 'purge_failed', 500, $this->rest( 'POST', '/backups/purge' ) );
	}

	public function test_a_file_name_the_folder_check_cannot_trust_stops_delete_backups(): void {
		// WordPress treats a name with a drive letter as unsafe to resolve.
		$this->put_legacy_backup( Images::jpeg(), 'C:photo.jpg' );

		$this->assertRestFailure( 'purge_failed', 500, $this->rest( 'POST', '/backups/purge' ) );
	}

	public function test_an_uploads_folder_that_does_not_exist_stops_delete_backups_and_restores(): void {
		$id = $this->upload( Images::jpeg() );
		$this->arrange_legacy_in_place_record( $id );
		$unmounted = static function ( array $uploads ): array {
			$uploads['basedir'] .= '-unmounted';
			return $uploads;
		};
		add_filter( 'upload_dir', $unmounted );
		try {
			$purge   = $this->rest( 'POST', '/backups/purge' );
			$restore = $this->rest( 'POST', "/restore/{$id}" );
		} finally {
			// The uploads folder must be back before the test cleans it.
			remove_filter( 'upload_dir', $unmounted );
		}

		$this->assertRestFailure( 'purge_failed', 500, $purge );
		$this->assertRestFailure( 'missing_backup', 404, $restore );
	}

	public function test_a_backup_folder_that_cannot_be_read_stops_delete_backups(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'File permissions do not stop the root user.' );
		}
		$locked = $this->backup_folder() . '/photos';
		wp_mkdir_p( $locked );
		chmod( $locked, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPressVIPMinimum.Functions.RestrictedFunctions.chmod_chmod
		try {
			$response = $this->rest( 'POST', '/backups/purge' );
		} finally {
			chmod( $locked, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPressVIPMinimum.Functions.RestrictedFunctions.chmod_chmod
		}

		$this->assertRestFailure( 'purge_failed', 500, $response );
	}

	public function test_a_failed_save_leaves_the_backups_listed(): void {
		$id = $this->upload( Images::jpeg() );
		$this->arrange_legacy_in_place_record( $id );
		$this->run_cli_stats_reconcile();
		// The database refuses to record that the backups were cleared.
		add_filter( 'pre_update_option_kilobyte_io_backups_purged', static fn ( $value, $old_value ) => $old_value, 10, 2 );

		$this->assertRestFailure( 'purge_failed', 500, $this->rest( 'POST', '/backups/purge' ) );

		$stats = $this->stats();
		$this->assertSame( 1, $stats['backup_count'] );
		$this->assertSame( 1, $stats['restorable_count'] );
		$this->assertFalse( $stats['needs_reconcile'] );
		$this->assertSame( array( $id ), $this->ids_in( $this->queue( 'restore' ) ) );
	}

	public function test_delete_backups_whose_totals_cannot_be_saved_asks_for_a_reconcile(): void {
		$id = $this->upload( Images::jpeg() );
		$this->arrange_legacy_in_place_record( $id );
		$this->run_cli_stats_reconcile();
		// The database refuses to save the new totals.
		add_filter( 'pre_update_option_kilobyte_io_stats', static fn ( $value, $old_value ) => $old_value, 10, 2 );

		$this->assertRestFailure( 'purge_failed', 500, $this->rest( 'POST', '/backups/purge' ) );

		$stats = $this->stats();
		$this->assertSame( 1, $stats['backup_count'] );
		$this->assertTrue( $stats['needs_reconcile'] );
		$this->assertSame( array( $id ), $this->ids_in( $this->queue( 'restore' ) ) );
	}

	public function test_delete_backups_that_loses_its_database_connection_changes_nothing(): void {
		$id = $this->upload( Images::jpeg() );
		$this->arrange_legacy_in_place_record( $id );
		$this->run_cli_stats_reconcile();
		$this->reconnect_database();

		$this->assertRestFailure( 'purge_failed', 500, $this->rest( 'POST', '/backups/purge' ) );

		$this->assertSame( 1, $this->stats()['backup_count'] );
		$this->assertSame( array( $id ), $this->ids_in( $this->queue( 'restore' ) ) );
	}

	public function test_another_request_restoring_or_deleting_backups_makes_this_one_wait(): void {
		$this->hold_lock_in_other_request( 'backups' );

		$this->assertRestFailure( 'locked', 409, $this->rest( 'POST', '/backups/purge' ) );
	}

	public function test_an_editor_cannot_delete_backups(): void {
		$this->sign_in_as( 'editor' );

		$this->assertSame( 403, $this->rest( 'POST', '/backups/purge' )->get_status() );
	}

	/**
	 * Makes a symbolic link that is removed again after the test.
	 *
	 * @param string $target Existing file or folder.
	 * @param string $link   Path of the link.
	 * @return void
	 */
	private function link( string $target, string $link ): void {
		$this->assertTrue( symlink( $target, $link ), 'The link could not be made.' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_symlink
		$this->links[] = $link;
	}

	/**
	 * Known bug: folder names made only of digits come back from the directory listing as
	 * numbers, which the folder check rejects, so Delete backups fails with purge_failed.
	 *
	 * @group known-bug
	 */
	public function test_delete_backups_succeeds_when_only_empty_year_and_month_folders_remain(): void {
		$id     = $this->upload( Images::jpeg() );
		$backup = $this->put_legacy_backup( Images::jpeg(), '2024/01/photo.jpg' );
		$this->arrange_legacy_in_place_record( $id, array( 'backup_path' => 'kilobyte-backups/2024/01/photo.jpg' ) );
		$this->run_cli_stats_reconcile();
		// The site owner moves the originals somewhere safe, leaving the empty folders behind.
		unlink( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

		$this->assertTrue( $this->assertRestOk( $this->rest( 'POST', '/backups/purge' ) )['purged'] );
	}

	/**
	 * Known bug: same cause, and the site owner gets "could not clear" instead of being told
	 * that originals remain.
	 *
	 * @group known-bug
	 */
	public function test_delete_backups_is_refused_while_a_year_folder_still_holds_an_original(): void {
		$this->put_legacy_backup( Images::jpeg(), '2024/01/photo.jpg' );

		$this->assertRestFailure( 'backups_protected', 409, $this->rest( 'POST', '/backups/purge' ) );
	}
}
