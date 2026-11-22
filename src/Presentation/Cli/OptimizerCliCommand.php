<?php
/**
 * WP-CLI commands for Kilobyte Image Optimizer.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation\Cli;

use Kilobyte\ImageOptimizer\Application\OptimizeOnServerUseCase;
use Kilobyte\ImageOptimizer\Application\PurgeBackupsUseCase;
use Kilobyte\ImageOptimizer\Application\RestoreAttachmentUseCase;
use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;
use Kilobyte\ImageOptimizer\Presentation\OperationErrorMessage;
use WP_CLI;

/**
 * Exposes `wp kilobyte stats`, `optimize`, `restore`, and `purge-backups`.
 */
final class OptimizerCliCommand {

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepositoryInterface
	 */
	private AttachmentRepositoryInterface $attachments;

	/**
	 * Server optimization use case.
	 *
	 * @var OptimizeOnServerUseCase
	 */
	private OptimizeOnServerUseCase $optimize_server;

	/**
	 * Restore use case.
	 *
	 * @var RestoreAttachmentUseCase
	 */
	private RestoreAttachmentUseCase $restore;

	/**
	 * Purge backups use case.
	 *
	 * @var PurgeBackupsUseCase
	 */
	private PurgeBackupsUseCase $purge_backups;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepositoryInterface $attachments     Attachment repository.
	 * @param OptimizeOnServerUseCase       $optimize_server Server optimization use case.
	 * @param RestoreAttachmentUseCase      $restore         Restore use case.
	 * @param PurgeBackupsUseCase           $purge_backups   Purge backups use case.
	 */
	public function __construct(
		AttachmentRepositoryInterface $attachments,
		OptimizeOnServerUseCase $optimize_server,
		RestoreAttachmentUseCase $restore,
		PurgeBackupsUseCase $purge_backups
	) {
		$this->attachments     = $attachments;
		$this->optimize_server = $optimize_server;
		$this->restore         = $restore;
		$this->purge_backups   = $purge_backups;
	}

	/**
	 * Register the WP-CLI command group if WP-CLI is running.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'kilobyte', $this );
	}

	/**
	 * Show library optimization statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--reconcile]
	 * : Rebuild the counters from the attachment records.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kilobyte stats
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public function stats( array $args, array $assoc_args ): void {
		$s = ! empty( $assoc_args['reconcile'] ) ? $this->attachments->reconcile_stats() : $this->attachments->get_library_stats();
		if ( null === $s ) {
			WP_CLI::error( 'Could not refresh image totals. Retry after current jobs finish. If it repeats, check that WordPress can save settings.' );
			return;
		}

		WP_CLI::log( sprintf( 'Total images:     %d', (int) $s['total_images'] ) );
		WP_CLI::log( sprintf( 'Optimized:        %d', (int) $s['optimized_count'] ) );
		WP_CLI::log( sprintf( 'Remaining:        %d', (int) $s['remaining_count'] ) );
		WP_CLI::log( sprintf( 'Transfer savings: %s (%s%%)', (string) size_format( (int) $s['total_saved'], 1 ), (string) $s['saved_percent'] ) );
		WP_CLI::log( sprintf( 'Backups:          %d (%s)', (int) $s['backup_count'], (string) size_format( (int) $s['backup_bytes'], 1 ) ) );
	}

	/**
	 * Optimize remaining Media Library images on the server.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of images to process in this run.
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kilobyte optimize --limit=500
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flag arguments.
	 * @return void
	 */
	public function optimize( array $args, array $assoc_args ): void {
		$limit     = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : 100;
		$processed = 0;
		$saved_sum = 0;
		$before    = 0;

		while ( $processed < $limit ) {
			$batch = $this->attachments->get_queue_page( 'optimize', $before, 50, false );
			$items = is_array( $batch['items'] ) ? $batch['items'] : array();

			foreach ( $items as $item ) {
				if ( $processed >= $limit ) {
					break;
				}
				$result = $this->optimize_server->execute( (int) $item['id'] );
				if ( $result instanceof OperationFailure ) {
					WP_CLI::warning( sprintf( '#%d %s: %s', (int) $item['id'], (string) $item['filename'], OperationErrorMessage::to_wp_error( $result )->get_error_message() ) );
				} elseif ( ! empty( $result['skipped'] ) ) {
					WP_CLI::log( sprintf( '#%d %s: skipped', (int) $item['id'], (string) $item['filename'] ) );
				} else {
					$saved_sum += (int) $result['saved_bytes'];
					WP_CLI::log(
						sprintf(
							'#%d %s -> %s (-%s%%)',
							(int) $item['id'],
							(string) $item['filename'],
							strtoupper( (string) $result['format'] ),
							(string) $result['saved_percent']
						)
					);
				}
				++$processed;
			}

			if ( 0 === (int) $batch['next_before'] ) {
				break;
			}
			$before = (int) $batch['next_before'];
		}

		WP_CLI::success( sprintf( 'Processed %d images. Estimated transfer savings: %s.', $processed, (string) size_format( $saved_sum, 1 ) ) );
	}

	/**
	 * Restore one attachment by ID or all restorable images with --all.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to restore.
	 *
	 * [--all]
	 * : Restore all optimized images.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kilobyte restore 42
	 *     wp kilobyte restore --all
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flag arguments.
	 * @return void
	 */
	public function restore( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['all'] ) ) {
			$restored = 0;
			$before   = 0;
			while ( true ) {
				$batch = $this->attachments->get_queue_page( 'restore', $before, 50, false );
				$items = is_array( $batch['items'] ) ? $batch['items'] : array();
				foreach ( $items as $item ) {
					$res = $this->restore->execute( (int) $item['id'] );
					if ( $res instanceof OperationFailure ) {
						WP_CLI::warning( OperationErrorMessage::to_wp_error( $res )->get_error_message() );
					} else {
						$this->report_restore_details( (int) $item['id'], $res );
						++$restored;
					}
				}
				if ( 0 === (int) $batch['next_before'] ) {
					break;
				}
				$before = (int) $batch['next_before'];
			}
			WP_CLI::success( sprintf( 'Restored %d images.', $restored ) );
			return;
		}

		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'Provide an attachment ID or pass --all.' );
			return;
		}

		$res = $this->restore->execute( $id );
		if ( $res instanceof OperationFailure ) {
			WP_CLI::error( OperationErrorMessage::to_wp_error( $res )->get_error_message() );
			return;
		}

		WP_CLI::success( sprintf( 'Restored attachment #%d.', $id ) );
		$this->report_restore_details( $id, $res );
	}

	/**
	 * Report recovery details that matter after the image has been restored.
	 *
	 * @param int                  $id     Attachment ID.
	 * @param array<string, mixed> $result Restore result.
	 * @return void
	 */
	private function report_restore_details( int $id, array $result ): void {
		if ( false === ( $result['thumbnails_ready'] ?? true ) ) {
			WP_CLI::warning( sprintf( '#%d: Some thumbnails are missing. Try Regenerate thumbnails in Media > Optimization, or use the full image if your host cannot make smaller sizes.', $id ) );
		}
		if ( false === ( $result['backup_removed'] ?? true ) ) {
			WP_CLI::log( sprintf( '#%d: The legacy backup is still in kilobyte-backups under uploads.', $id ) );
		}
	}

	/**
	 * Clear references after the backed-up originals have been restored.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kilobyte purge-backups
	 *
	 * @return void
	 */
	public function purge_backups(): void {
		$res = $this->purge_backups->execute();
		if ( $res instanceof OperationFailure ) {
			WP_CLI::error( OperationErrorMessage::to_wp_error( $res )->get_error_message() );
			return;
		}
		WP_CLI::success( 'Cleared the legacy backup references.' );
	}
}
