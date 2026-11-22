import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { optimizeQueueItem } from '../../application/optimizeQueueItem';
import { runBulkQueue } from '../../application/runBulkQueue';
import { toFriendlyError } from '../../domain/errors';
import { formatEta } from '../../domain/eta';
import type { BulkPhase, RunSummary } from '../../domain/session';
import type { BulkProgress, LibraryStats, LogEntry, PluginSettings, QueueItem, QueueMode } from '../../domain/types';
import { browserCompressor } from '../../infrastructure/browserCompressor';
import { wpRestClient } from '../../infrastructure/wpRestClient';
import { updateRestoredAttachment } from '../../infrastructure/wpMedia';
import { optimizationSkip } from '../optimizationSkip';
import { restoreDetails } from '../restoreDetails';

interface QueueOptions {
	settings: PluginSettings;
	outputMimes: string[];
	stats: LibraryStats | null;
	begin: () => boolean;
	finish: () => void;
	onError: ( error: unknown ) => void;
	onEmpty: ( message: string ) => void;
	onFinished: () => Promise< void >;
}

export function useOptimizationQueue( {
	settings, outputMimes, stats, begin, finish, onError, onEmpty, onFinished,
}: QueueOptions ) {
	const [ phase, setPhase ] = useState< BulkPhase >( 'idle' );
	const [ activeMode, setActiveMode ] = useState< QueueMode | null >( null );
	const [ retryItems, setRetryItems ] = useState< QueueItem[] >( [] );
	const [ nextPage, setNextPage ] = useState< number | null >( null );
	const [ lastSummary, setLastSummary ] = useState< RunSummary | null >( null );
	const [ stopping, setStopping ] = useState( false );
	const [ progress, setProgress ] = useState< BulkProgress | null >( null );
	const [ logs, setLogs ] = useState< LogEntry[] >( [] );
	const stopRef = useRef( false );
	const scan = useRef< AbortController | null >( null );

	const pushLog = ( entry: Omit< LogEntry, 'id' > ) => {
		setLogs( ( prev ) => [
			{
				id: `${ Date.now() }-${ Math.random() }`,
				...entry,
			},
			...prev.slice( 0, 149 ),
		] );
	};

	const handleStop = () => {
		if ( stopping ) {
			return;
		}
		stopRef.current = true;
		scan.current?.abort();
		setStopping( true );
		setPhase( 'stopping' );
		setProgress( ( prev ) =>
			prev
				? {
						...prev,
						stage: __( 'Stopping after the images in progress...', 'kilobyte-image-optimizer' ),
						currentFile: undefined,
						download: undefined,
						eta: __( 'Finishing images in progress', 'kilobyte-image-optimizer' ),
				  }
				: prev
		);
	};

	const executeQueueRun = async (
		mode: QueueMode, presetItems?: QueueItem[], retry = false, resumeBefore = presetItems ? null : 0
	) => {
		if ( ! begin() ) {
			return;
		}
		stopRef.current = false;
		setStopping( false );
		setPhase( 'running' );
		setActiveMode( mode );
		setLastSummary( null );
		setRetryItems( [] );
		setNextPage( null );
		setLogs( [] );

		const startedAt = Date.now();
		const scanning = __( 'Finding images in your Media Library...', 'kilobyte-image-optimizer' );
		const estimatedTotal = ( resumeBefore === null ? presetItems?.length : undefined ) ??
			( mode === 'restore' ? stats?.restorable_count : mode === 'optimize' ? stats?.remaining_count : stats?.total_images ) ??
			0;
		let done = 0;
		let succeeded = 0;
		let skipped = 0;
		let missing = 0;
		const skippedByReason: Record< string, number > = Object.create( null );
		const failed: QueueItem[] = [];
		const pendingRetry = new Map( presetItems?.map( ( item ) => [ item.id, item ] ) );
		let before: number | null = resumeBefore;
		let pendingPage = presetItems;
		const summarize = (): RunSummary => ( {
			mode,
			succeeded,
			skipped: skipped + missing,
			skippedByReason: { ...skippedByReason, ...( missing ? { unavailable: missing } : {} ) },
			failed: failed.length,
			stopped: stopRef.current,
		} );

		const report = ( stage: string, currentFile?: string, download?: BulkProgress[ 'download' ] ) => {
			if ( stopRef.current ) {
				return;
			}
			setProgress( {
				stage,
				currentFile,
				download,
				done,
				total: Math.max( estimatedTotal, done ),
				eta: estimatedTotal > 0 ? formatEta( done, estimatedTotal, Date.now() - startedAt ) : undefined,
			} );
		};
		report( scanning );

		try {
			while ( ! stopRef.current && ( pendingPage || before !== null ) ) {
				report( scanning );
				let items: QueueItem[];
				if ( pendingPage ) {
					items = pendingPage;
					pendingPage = undefined;
				} else {
					scan.current = new AbortController();
					const page = await wpRestClient.getQueuePage( mode, before ?? 0, scan.current.signal );
					scan.current = null;
					before = page.next_before || null;
					missing += page.skipped_missing ?? 0;
					done += page.skipped_missing ?? 0;
					items = page.items;
					for ( const item of items ) {
						pendingRetry.set( item.id, item );
					}
				}

				// Large photos need more decode memory per worker.
				const serial = retry || items.some( ( item ) => item.width * item.height > 12_000_000 );
				await runBulkQueue< QueueItem >( {
					items,
					concurrency: serial ? 1 : settings.concurrency,
					shouldStop: () => stopRef.current,
					onProgress: () => {},
					onError: ( item, error ) => {
						failed.push( item );
						const parsed = toFriendlyError( error );
						pushLog( {
							title: item.filename,
							subtitle: parsed.friendly,
							metric: __( 'Failed', 'kilobyte-image-optimizer' ),
							tone: 'error',
							action: parsed.action,
							technical: parsed.technical,
						} );
					},
					processItem: async ( item ) => {
						pendingRetry.delete( item.id );
						try {
							if ( mode === 'regenerate' ) {
								report( __( 'Regenerating thumbnails', 'kilobyte-image-optimizer' ), item.filename );
								const result = await wpRestClient.regenerateThumbnails( item.id );
								pushLog( {
									title: item.filename,
									/* translators: %d: thumbnail size count. */
									subtitle: sprintf( __( 'Regenerated %d thumbnail sizes', 'kilobyte-image-optimizer' ), result.sizes ),
									metric: __( 'Done', 'kilobyte-image-optimizer' ),
									tone: 'success',
								} );
							} else if ( mode === 'restore' ) {
								report( __( 'Restoring original image', 'kilobyte-image-optimizer' ), item.filename );
								const restored = await wpRestClient.restoreAttachment( item.id );
								const details = restoreDetails( restored );
								updateRestoredAttachment( item.id, details ?? __( 'Restored', 'kilobyte-image-optimizer' ), restored.mode === 'in_place' );
								pushLog( {
									title: item.filename,
									metric: __( 'Restored', 'kilobyte-image-optimizer' ),
									subtitle: details,
									tone: 'info',
								} );
							} else {
								const stages = {
									download: __( 'Reading image', 'kilobyte-image-optimizer' ),
									browser: __( 'Compressing in your browser', 'kilobyte-image-optimizer' ),
									server: __( 'Compressing on your site', 'kilobyte-image-optimizer' ),
									save: __( 'Saving to the Media Library', 'kilobyte-image-optimizer' ),
								};
								const outcome = await optimizeQueueItem(
									item, settings, browserCompressor, wpRestClient,
									( stage, filename, download ) => report( stages[ stage ], filename, download ),
									outputMimes
								);
								if ( 'skipReason' in outcome ) {
									pushLog( {
										title: outcome.title,
										...optimizationSkip( outcome.skipReason ),
										metric: __( 'Skipped', 'kilobyte-image-optimizer' ),
										tone: 'muted',
									} );
									skipped += 1;
									skippedByReason[ outcome.skipReason ] = ( skippedByReason[ outcome.skipReason ] ?? 0 ) + 1;
									return;
								}
								pushLog( outcome );
							}
							succeeded += 1;
						} finally {
							done += 1;
							setProgress( ( previous ) => previous && ( {
								...previous,
								done,
								total: Math.max( estimatedTotal, done ),
								eta: ! stopRef.current && estimatedTotal > 0 ? formatEta( done, estimatedTotal, Date.now() - startedAt ) : previous.eta,
							} ) );
						}
					},
				} );
			}

			if ( ! done && ! stopRef.current ) {
				onEmpty( __( 'There are no images left for this action.', 'kilobyte-image-optimizer' ) );
			} else {
				setLastSummary( summarize() );
			}
			setPhase( stopRef.current ? 'stopped' : failed.length ? 'completed_with_errors' : 'completed' );
		} catch ( err ) {
			setLastSummary( summarize() );
			if ( stopRef.current ) {
				setPhase( 'stopped' );
			} else {
				setPhase( 'completed_with_errors' );
				onError( err );
			}
		} finally {
			if ( missing > 0 ) {
				pushLog( {
					/* translators: %d: image count. */
					title: sprintf( __( 'Unavailable files (%d)', 'kilobyte-image-optimizer' ), missing ),
					...optimizationSkip( 'unavailable' ),
					count: missing,
					metric: __( 'Skipped', 'kilobyte-image-optimizer' ),
					tone: 'muted',
				} );
			}
			scan.current = null;
			setRetryItems( [ ...failed, ...pendingRetry.values() ] );
			setNextPage( before );
			setActiveMode( null );
			setStopping( false );
			setProgress( null );
			finish();
			await onFinished();
		}
	};

	const clearResults = () => {
		setRetryItems( [] );
		setNextPage( null );
		setLogs( [] );
		setLastSummary( null );
		setPhase( 'idle' );
	};

	const resume = () => executeQueueRun( lastSummary?.mode ?? 'optimize', retryItems, true, nextPage );

	return { phase, activeMode, retryItems, lastSummary, stopping, progress, logs, nextPage, run: executeQueueRun, resume, stop: handleStop, clearResults };
}
