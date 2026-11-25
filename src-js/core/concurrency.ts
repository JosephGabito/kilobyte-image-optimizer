import { clampConcurrency } from './dimensions';

export async function runBoundedPool< T, R >(
	items: readonly T[],
	concurrency: number,
	worker: ( item: T, index: number ) => Promise< R >,
	signal?: AbortSignal
): Promise< R[] > {
	if ( signal?.aborted ) {
		throw new DOMException( 'Batch processing cancelled.', 'AbortError' );
	}

	const results = new Array< R >( items.length );
	const limit = Math.min( items.length, clampConcurrency( concurrency ) );
	let cursor = 0;
	let failed = false;

	async function runNext(): Promise< void > {
		while ( cursor < items.length && ! failed ) {
			if ( signal?.aborted ) {
				throw new DOMException( 'Batch processing cancelled.', 'AbortError' );
			}
			const index = cursor++;
			try {
				results[ index ] = await worker( items[ index ], index );
			} catch ( error ) {
				failed = true;
				throw error;
			}
		}
	}

	const lanes: Array< Promise< void > > = [];
	for ( let i = 0; i < limit; i++ ) {
		lanes.push( runNext() );
	}
	await Promise.all( lanes );
	if ( signal?.aborted ) {
		throw new DOMException( 'Batch processing cancelled.', 'AbortError' );
	}
	return results;
}
