<?php
/**
 * Process automatic upload copies after WordPress finishes the upload.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Presentation\Hooks;

use Kilobyte\ImageOptimizer\Application\OptimizeOnServerUseCase;
use Kilobyte\ImageOptimizer\Domain\Repository\SettingsRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpOperationLock;

/**
 * Runs one shared, bounded worker per site and keeps interrupted uploads retryable.
 */
final class AutomaticUploadOptimization {

	public const EVENT = 'kilobyte_io_optimize_pending_uploads';

	private const FAILURE        = '_kilobyte_io_auto_failure';
	private const SKIP           = '_kilobyte_io_auto_skip';
	private const MAX_TRIES      = 3;
	private const BATCH_SIZE     = 3;
	private const RECOVERY_CHECK = 'kilobyte_io_auto_recovery_checked';
	private const OVERDUE_GRACE  = 5 * MINUTE_IN_SECONDS;

	/** @var WpAttachmentRepository */
	private WpAttachmentRepository $attachments;

	/** @var SettingsRepositoryInterface */
	private SettingsRepositoryInterface $settings;

	/** @var OptimizeOnServerUseCase */
	private OptimizeOnServerUseCase $optimizer;

	/**
	 * @param WpAttachmentRepository      $attachments Attachment storage.
	 * @param SettingsRepositoryInterface $settings    Site preferences.
	 * @param OptimizeOnServerUseCase     $optimizer   Server encoder.
	 */
	public function __construct( WpAttachmentRepository $attachments, SettingsRepositoryInterface $settings, OptimizeOnServerUseCase $optimizer ) {
		$this->attachments = $attachments;
		$this->settings    = $settings;
		$this->optimizer   = $optimizer;
	}

	/**
	 * Finish an upload optimization from its Media Library action.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function retry_now( int $id ) {
		$result = $this->attachments->with_attachment_lock(
			$id,
			function () use ( $id ) {
				$result = $this->optimizer->optimize_while_locked( $id );
				if ( ! $result instanceof OperationFailure ) {
					$this->finish_success( $id, $result );
				}
				return $result;
			}
		);
		return $result ?? new OperationFailure( OperationFailure::BUSY );
	}

	/**
	 * Store a job before asking WordPress to run the worker.
	 *
	 * @param int  $id     Attachment ID.
	 * @param bool $native Require a completed native upload.
	 * @param int  $delay  Seconds to give the browser before the worker picks up the job.
	 * @return bool Whether WordPress accepted the job.
	 */
	public function enqueue( int $id, bool $native = false, int $delay = 10 ): bool {
		try {
			return true === $this->attachments->with_attachment_lock(
				$id,
				function () use ( $id, $native, $delay ): bool {
					if ( $native && ! get_post_meta( $id, WpAttachmentRepository::NATIVE_PENDING, true ) ) {
						return true;
					}
					if ( ! $this->settings->load()->is_enabled() ) {
						return $this->attachments->clear_automatic_job( $id );
					}
					if ( ! WpOperationLock::is_current() ) {
						return false;
					}
					$key    = WpAttachmentRepository::AUTO_PENDING;
					$queued = get_post_meta( $id, $key, true ) || add_post_meta( $id, $key, time() + $delay, true );
					if ( $queued && self::schedule_worker() ) {
						if ( $native && WpOperationLock::is_current() ) {
							delete_post_meta( $id, WpAttachmentRepository::NATIVE_PENDING );
						}
						return true;
					}
					$this->store_status( $id, self::FAILURE, OperationFailure::JOB_NOT_QUEUED );
					return false;
				}
			);
		} catch ( \Throwable $error ) {
			// A scheduling or metadata filter must not interrupt the completed upload.
			return false;
		}
	}

	/**
	 * Ensure there is one scheduled worker for the site.
	 *
	 * @param int $delay Delay in seconds.
	 * @return bool
	 */
	public static function schedule_worker( int $delay = 10 ): bool {
		return false !== wp_next_scheduled( self::EVENT )
			|| wp_schedule_single_event( time() + $delay, self::EVENT )
			|| false !== wp_next_scheduled( self::EVENT );
	}

	/**
	 * Recover pending jobs if WordPress could not persist the next worker.
	 *
	 * @param bool $activation Check immediately when enabling the plugin.
	 * @return void
	 */
	public static function resume_if_needed( bool $activation = false ): void {
		try {
			if ( wp_next_scheduled( self::EVENT ) || ( ! $activation && get_transient( self::RECOVERY_CHECK ) ) ) {
				return;
			}
			set_transient( self::RECOVERY_CHECK, true, MINUTE_IN_SECONDS );
			if ( self::has_pending() ) {
				self::schedule_worker();
			}
		} catch ( \Throwable $error ) {
			// The next admin visit can retry a temporary scheduling failure.
			return;
		}
	}

	/**
	 * Process a few due jobs, with a scheduled continuation in place before encoding.
	 *
	 * @return void
	 */
	public function process(): void {
		$ran = WpOperationLock::run(
			'automatic_uploads',
			0,
			function (): bool {
				$deadline = microtime( true ) + 4;
				$ids      = $this->pending_ids();
				if ( ! $ids && ! self::has_pending() ) {
					return true;
				}
				if ( ! self::schedule_worker( MINUTE_IN_SECONDS ) ) {
					foreach ( $ids as $id ) {
						$this->store_status( (int) $id, self::FAILURE, OperationFailure::JOB_NOT_QUEUED );
					}
					return true;
				}
				foreach ( $ids as $id ) {
					if ( microtime( true ) >= $deadline ) {
						break;
					}
					$id = (int) $id;
					$this->attachments->with_attachment_lock(
						$id,
						function () use ( $id, $deadline ): void {
							$this->process_upload( $id, $deadline );
						}
					);
				}
				return true;
			}
		);
		if ( null === $ran ) {
			self::schedule_worker( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Read a small batch from the upload queue using its dedicated meta key.
	 *
	 * @return array<int, string>
	 */
	private function pending_ids(): array {
		global $wpdb;
		// Only the background worker reads this key, and each query returns at most a small batch.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) <= %d ORDER BY post_id ASC LIMIT %d",
				WpAttachmentRepository::AUTO_PENDING,
				time(),
				self::BATCH_SIZE
			)
		);
	}

	/**
	 * Check the queue's indexed meta key for due or waiting jobs.
	 *
	 * @return bool
	 */
	private static function has_pending(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", WpAttachmentRepository::AUTO_PENDING )
		);
	}

	/**
	 * Persist a retry before invoking the editor. The marker survives a fatal timeout.
	 *
	 * @param int   $id       Attachment ID.
	 * @param float $deadline Latest time to start an encode in this batch.
	 * @return void
	 */
	private function process_upload( int $id, float $deadline ): void {
		$pending = (int) get_post_meta( $id, WpAttachmentRepository::AUTO_PENDING, true );
		if ( $pending <= 0 || $pending > time() || microtime( true ) >= $deadline || ! WpOperationLock::is_current() ) {
			return;
		}
		if ( ! $this->settings->load()->is_enabled() || 'attachment' !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
			$this->attachments->clear_automatic_job( $id );
			return;
		}
		$tries = (int) get_post_meta( $id, WpAttachmentRepository::AUTO_ATTEMPTS, true );
		if ( $tries >= self::MAX_TRIES ) {
			$this->finish_failure( $id, OperationFailure::JOB_INTERRUPTED );
			return;
		}

		if ( microtime( true ) >= $deadline ) {
			return;
		}
		if ( ! WpOperationLock::is_current() || ! update_post_meta( $id, WpAttachmentRepository::AUTO_PENDING, time() + 5 * MINUTE_IN_SECONDS )
			|| ! WpOperationLock::is_current()
			|| ! update_post_meta( $id, WpAttachmentRepository::AUTO_ATTEMPTS, $tries + 1 )
		) {
			$this->store_status( $id, self::FAILURE, OperationFailure::JOB_NOT_QUEUED );
			return;
		}

		try {
			$result = $this->optimizer->optimize_while_locked( $id, $deadline );
		} catch ( \Throwable $error ) {
			$result = new OperationFailure( OperationFailure::JOB_INTERRUPTED );
		}
		if ( ! WpOperationLock::is_current() ) {
			return;
		}
		if ( $result instanceof OperationFailure ) {
			if ( OperationFailure::JOB_DEFERRED === $result->reason() ) {
				// The encoder never started. Make this job eligible for the next pass.
				if ( update_post_meta( $id, WpAttachmentRepository::AUTO_ATTEMPTS, $tries ) && WpOperationLock::is_current() ) {
					update_post_meta( $id, WpAttachmentRepository::AUTO_PENDING, $pending );
				}
				return;
			}
			if ( $tries + 1 < self::MAX_TRIES && in_array( $result->reason(), array( OperationFailure::ENCODE_FAILED, OperationFailure::WRITE_FAILED, OperationFailure::SOURCE_CHANGED, OperationFailure::JOB_INTERRUPTED ), true ) ) {
				return;
			}
			$this->finish_failure( $id, $result->reason() );
			return;
		}
		$this->finish_success( $id, $result );
	}

	/**
	 * Persist a skip or clear completed upload work while holding the attachment lock.
	 *
	 * @param int                  $id     Attachment ID.
	 * @param array<string, mixed> $result Optimization result.
	 * @return void
	 */
	private function finish_success( int $id, array $result ): void {
		if ( ! empty( $result['skipped'] ) && ! empty( $result['status'] ) && null === $this->attachments->get_record( $id ) ) {
			if ( ! $this->store_status( $id, self::SKIP, (string) $result['status'] ) ) {
				return;
			}
		}
		if ( WpOperationLock::is_current() ) {
			delete_post_meta( $id, self::FAILURE );
			$this->attachments->clear_automatic_job( $id );
		}
	}

	/**
	 * @param int    $id     Attachment ID.
	 * @param string $reason Failure code.
	 * @return void
	 */
	private function finish_failure( int $id, string $reason ): void {
		if ( $this->store_status( $id, self::FAILURE, $reason ) && WpOperationLock::is_current() ) {
			delete_post_meta( $id, self::SKIP );
			$this->attachments->clear_automatic_job( $id );
		}
	}

	/**
	 * @param int    $id     Attachment ID.
	 * @param string $key    Outcome metadata key.
	 * @param string $reason Outcome code.
	 * @return bool
	 */
	private function store_status( int $id, string $key, string $reason ): bool {
		$value  = array(
			'reason' => $reason,
			'stamp'  => $this->attachments->attachment_source_stamp( $id ),
		);
		$stored = get_post_meta( $id, $key, true );
		return WpOperationLock::is_current() && ( $stored === $value || (bool) update_post_meta( $id, $key, $value ) );
	}

	/**
	 * @param int $id Attachment ID.
	 * @return array{pending: bool, stalled: bool, failure: string, skip: string}
	 */
	public function status( int $id ): array {
		$due = (int) get_post_meta( $id, WpAttachmentRepository::AUTO_PENDING, true );
		return array(
			'pending' => $due > 0,
			'stalled' => $due > 0 && $due <= time() - self::OVERDUE_GRACE,
			'failure' => $this->read_status( $id, self::FAILURE ),
			'skip'    => $this->read_status( $id, self::SKIP ),
		);
	}

	/**
	 * @param int    $id  Attachment ID.
	 * @param string $key Outcome metadata key.
	 * @return string Status for the current file.
	 */
	private function read_status( int $id, string $key ): string {
		$stored = get_post_meta( $id, $key, true );
		if ( is_string( $stored ) ) {
			return $stored;
		}
		return is_array( $stored ) && ( $stored['stamp'] ?? '' ) === $this->attachments->attachment_source_stamp( $id )
			? (string) ( $stored['reason'] ?? '' )
			: '';
	}
}
