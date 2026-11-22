<?php
/**
 * WordPress hooks for image optimization.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

use Kilobyte\ImageOptimizer\Domain\Repository\OptimizationEventsInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;

/**
 * Exposes the optimizer's WordPress integration points.
 */
final class WpOptimizationEvents implements OptimizationEventsInterface {

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function should_optimize( int $attachment_id ): bool {
		/** Filters whether an attachment can be optimized. */
		return (bool) apply_filters( 'kilobyte_io_should_optimize', true, $attachment_id );
	}

	/**
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Stored record.
	 * @return void
	 */
	public function optimized( int $attachment_id, OptimizationRecord $record ): void {
		/** Fires after an optimized copy has been saved. */
		$this->notify( static fn () => do_action( 'kilobyte_io_after_optimize', $attachment_id, $record->to_array() ) );
		$this->variant_changed( $attachment_id );
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function variant_changed( int $attachment_id ): void {
		/** Fires when pages referencing an attachment may need cache invalidation. */
		$this->notify( static fn () => do_action( 'kilobyte_io_variant_changed', $attachment_id ) );
	}

	/**
	 * Isolate optional callbacks from a completed operation.
	 * @param callable $action Integration notification.
	 * @return void
	 */
	private function notify( callable $action ): void {
		global $wp_current_filter;
		$previous = $wp_current_filter;
		try {
			$action();
		} catch ( \Throwable $error ) {
			// Core pops the public hook stack only after a callback returns normally.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_current_filter = $previous;
		}
	}
}
