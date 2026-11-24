<?php
/**
 * Shared steps for the library-wide REST endpoint tests.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Tests\Integration\Library;

use Kilobyte\ImageOptimizer\Application\OptimizeOnServerUseCase;
use Kilobyte\ImageOptimizer\Application\PurgeBackupsUseCase;
use Kilobyte\ImageOptimizer\Application\RestoreAttachmentUseCase;
use Kilobyte\ImageOptimizer\Application\SaveOptimizedVariant;
use Kilobyte\ImageOptimizer\Domain\Policy\CompressionPolicy;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\OptionSettingsRepository;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpFilesystemBackupStorage;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpImageEditorCompressor;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpOptimizationEvents;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpVariantRetirement;
use Kilobyte\ImageOptimizer\Presentation\Cli\OptimizerCliCommand;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reads library totals, saves preferences and arranges records left by older plugin versions.
 */
abstract class LibraryTestCase extends TestCase {

	/**
	 * Reads the library totals the dashboard shows.
	 *
	 * @return array<string, mixed>
	 */
	protected function stats(): array {
		return $this->assertRestOk( $this->rest( 'GET', '/stats' ) );
	}

	/**
	 * Reads one page of the bulk queue.
	 *
	 * @param string               $mode   Queue mode.
	 * @param array<string, mixed> $params Extra query parameters.
	 * @return array<string, mixed>
	 */
	protected function queue( string $mode, array $params = array() ): array {
		return $this->assertRestOk( $this->rest( 'GET', '/queue', array( 'mode' => $mode ) + $params ) );
	}

	/**
	 * IDs of the images on a queue page, in the order the queue lists them.
	 *
	 * @param array<string, mixed> $page Queue page.
	 * @return int[]
	 */
	protected function ids_in( array $page ): array {
		return array_column( $page['items'], 'id' );
	}

	/**
	 * Saves preferences the way the admin screen does, as a JSON body.
	 *
	 * @param string $json Raw request body.
	 * @return WP_REST_Response
	 */
	protected function save_preferences( string $json ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::REST_NAMESPACE . '/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $json );
		return rest_do_request( $request );
	}

	/**
	 * Runs `wp kilobyte stats --reconcile`, which rebuilds the totals from every image record.
	 *
	 * @param bool $expect_error Whether the command is expected to stop with an error.
	 * @return void
	 */
	protected function run_cli_stats_reconcile( bool $expect_error = false ): void {
		require_once dirname( __DIR__, 2 ) . '/Support/WpCli.php';
		$repository = new WpAttachmentRepository();
		$events     = new WpOptimizationEvents();
		$retirement = new WpVariantRetirement( $repository );
		$backups    = new WpFilesystemBackupStorage();
		$command    = new OptimizerCliCommand(
			$repository,
			new OptimizeOnServerUseCase(
				$repository,
				$events,
				new OptionSettingsRepository(),
				new WpImageEditorCompressor(),
				new CompressionPolicy(),
				new SaveOptimizedVariant( $repository, $events, $retirement )
			),
			new RestoreAttachmentUseCase( $repository, $events, $backups, $retirement ),
			new PurgeBackupsUseCase( $backups, $repository )
		);
		$error      = null;
		ob_start();
		try {
			$command->stats( array(), array( 'reconcile' => true ) );
		} catch ( \Throwable $stopped ) {
			// WP-CLI stops a command that reports an error.
			$error = $stopped;
		} finally {
			ob_end_clean();
		}
		if ( $expect_error ) {
			$this->assertNotNull( $error, 'The command should have reported an error.' );
		} elseif ( null !== $error ) {
			throw $error;
		}
	}

	/**
	 * Writes the record an older plugin version left after replacing an image in place
	 * and moving the original into uploads/kilobyte-backups.
	 *
	 * @param int                  $id        Attachment ID.
	 * @param array<string, mixed> $overrides Record fields to change.
	 * @return void
	 */
	protected function arrange_legacy_in_place_record( int $id, array $overrides = array() ): void {
		update_post_meta(
			$id,
			WpAttachmentRepository::META_KEY,
			$overrides + array(
				'original_size'  => 1000,
				'optimized_size' => 400,
				'format'         => 'jpg',
				'original_name'  => 'photo.jpg',
				'backup_path'    => 'kilobyte-backups/2024/01/photo.jpg',
				'backup_bytes'   => 1000,
				'delivery_mode'  => 'in_place',
				'optimized_at'   => '2024-01-15 10:00:00',
				'status'         => 'optimized',
				'engine'         => 'browser',
			)
		);
	}

	/**
	 * Copies a file into the legacy backup folder.
	 *
	 * @param string $source   File to copy.
	 * @param string $relative Path inside uploads/kilobyte-backups.
	 * @return string Absolute path of the backup.
	 */
	protected function put_legacy_backup( string $source, string $relative ): string {
		$path = $this->backup_folder() . '/' . $relative;
		wp_mkdir_p( dirname( $path ) );
		$this->assertTrue( copy( $source, $path ), 'The legacy backup could not be written.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		return $path;
	}

	/**
	 * The folder where older plugin versions kept originals.
	 *
	 * @return string
	 */
	protected function backup_folder(): string {
		return wp_upload_dir( null, false )['basedir'] . '/kilobyte-backups';
	}

	/**
	 * Image files in a folder, by name.
	 *
	 * @param string $folder Folder.
	 * @return string[]
	 */
	protected function files_in( string $folder ): array {
		$names = array_map( 'wp_basename', (array) glob( $folder . '/*' ) );
		sort( $names );
		return $names;
	}
}
