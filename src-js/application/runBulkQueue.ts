import { clampConcurrency } from '../domain/dimensions';

export interface BulkRunnerOptions< T > {
	items: T[];
	concurrency: number;
	shouldStop: () => boolean;
	processItem: ( item: T ) => Promise< void >;
	onProgress: ( completed: number, total: number ) => void;
	onError: ( item: T, err: unknown ) => void;
}

export async function runBulkQueue< T >(
	options: BulkRunnerOptions< T >
): Promise< { completed: number; total: number; stopped: boolean } > {
	const { items, concurrency, shouldStop, processItem, onProgress, onError } = options;
	const total = items.length;
	const workers = Math.min( clampConcurrency( concurrency ), Math.max( 1, total ) );

	let cursor = 0;
	let completed = 0;

	onProgress( 0, total );

	const worker = async () => {
		while ( ! shouldStop() ) {
			const idx = cursor;
			cursor += 1;
			if ( idx >= total ) {
				break;
			}

			const item = items[ idx ];
			try {
				await processItem( item );
			} catch ( err ) {
				onError( item, err );
			}

			completed += 1;
			onProgress( completed, total );
		}
	};

	await Promise.all( Array.from( { length: workers }, worker ) );

	return {
		completed,
		total,
		stopped: shouldStop(),
	};
}
