import type { QueueMode } from './types';

export type BulkPhase =
	| 'idle'
	| 'running'
	| 'stopping'
	| 'completed'
	| 'completed_with_errors'
	| 'stopped';

export interface RunSummary {
	mode: QueueMode;
	succeeded: number;
	skipped: number;
	skippedByReason: Record< string, number >;
	failed: number;
	stopped: boolean;
}

export function getPrimaryOptimizeLabel(
	phase: BulkPhase,
	activeMode: QueueMode | null,
	failedCount: number
): string {
	if ( activeMode === 'optimize' ) {
		return 'Optimizing...';
	}
	if ( ( phase === 'completed_with_errors' || phase === 'stopped' ) && failedCount > 0 ) {
		return failedCount === 1
			? 'Retry 1 failed image'
			: `Retry ${ failedCount } failed images`;
	}
	if ( phase === 'stopped' ) {
		return 'Resume optimization';
	}
	return 'Optimize remaining images';
}
