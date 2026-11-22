import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Notice,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalDivider as Divider,
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	__experimentalSurface as Surface,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { optimizeQueueItem } from '../../application/optimizeQueueItem';
import { runBulkQueue } from '../../application/runBulkQueue';
import { toFriendlyError, type FriendlyError } from '../../domain/errors';
import { formatEta } from '../../domain/eta';
import { restoreDetails } from '../restoreDetails';
import type { BulkPhase, RunSummary } from '../../domain/session';
import {
	DEFAULT_SETTINGS,
	type AdminBootstrapConfig,
	type BrowserSupport,
	type BulkProgress,
	type LibraryStats,
	type LogEntry,
	type PluginSettings,
	type QueueItem,
	type QueueMode,
} from '../../domain/types';
import { browserCompressor } from '../../infrastructure/browserCompressor';
import { wpRestClient } from '../../infrastructure/wpRestClient';
import { BulkOptimizerCard } from './components/BulkOptimizerCard';
import { PreviewCard } from './components/PreviewCard';
import { SettingsCard } from './components/SettingsCard';
import { StatsCard } from './components/StatsCard';

declare global {
	interface Window {
		KilobyteIOAdmin?: AdminBootstrapConfig;
	}
}

export function App() {
	const cfg = window.KilobyteIOAdmin;

	const [ savedSettings, setSavedSettings ] = useState< PluginSettings >(
		cfg?.settings ?? DEFAULT_SETTINGS
	);
	const [ settings, setSettings ] = useState< PluginSettings >(
		cfg?.settings ?? DEFAULT_SETTINGS
	);
	const isSettingsDirty =
		JSON.stringify( settings ) !== JSON.stringify( savedSettings );
	const [ stats, setStats ] = useState< LibraryStats | null >( null );
	const [ statsError, setStatsError ] = useState< FriendlyError | null >( null );
	const [ elapsedSeconds, setElapsedSeconds ] = useState( 0 );
	const [ support, setSupport ] = useState< BrowserSupport | null >( null );
	const [ phase, setPhase ] = useState< BulkPhase >( 'idle' );
	const [ activeMode, setActiveMode ] = useState< QueueMode | null >( null );
	const [ failedItems, setFailedItems ] = useState< QueueItem[] >( [] );
	const [ lastSummary, setLastSummary ] = useState< RunSummary | null >( null );
	const [ stopping, setStopping ] = useState( false );
	const [ progress, setProgress ] = useState< BulkProgress | null >( null );
	const [ logs, setLogs ] = useState< LogEntry[] >( [] );
	const [ saving, setSaving ] = useState( false );
	const [ confirmAction, setConfirmAction ] = useState< 'restore' | null >( null );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		message: string;
		action?: string;
		technical?: string;
	} | null >( null );

	const running = activeMode !== null;
	const stopRef = useRef( false );
	const runningRef = useRef( false );
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

	const setErrorNotice = ( err: unknown ) => {
		const parsed: FriendlyError = toFriendlyError( err );
		setNotice( {
			status: 'error',
			message: parsed.friendly,
			action: parsed.action,
			technical: parsed.technical,
		} );
	};

	const refreshStats = async () => {
		setStatsError( null );
		try {
			setStats( await wpRestClient.getStats() );
		} catch ( err ) {
			setStatsError( toFriendlyError( err ) );
		}
	};

	useEffect( () => {
		browserCompressor.detectSupport().then( setSupport );
		refreshStats();
	}, [] );

	useEffect( () => {
		if ( ! running ) {
			return;
		}
		const handleBeforeUnload = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', handleBeforeUnload );
		const now = Date.now();
		const timer = window.setInterval( () => setElapsedSeconds( Math.floor( ( Date.now() - now ) / 1000 ) ), 1000 );
		return () => {
			clearInterval( timer );
			window.removeEventListener( 'beforeunload', handleBeforeUnload );
		};
	}, [ running ] );

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

	const executeQueueRun = async ( mode: QueueMode, presetItems?: QueueItem[] ) => {
		if ( runningRef.current ) {
			return;
		}
		runningRef.current = true;
		stopRef.current = false;
		setStopping( false );
		setElapsedSeconds( 0 );
		setPhase( 'running' );
		setActiveMode( mode );
		setLastSummary( null );
		setFailedItems( [] );
		setNotice( null );
		setLogs( [] );

		const startedAt = Date.now();
		const scanning = __( 'Finding images in your Media Library...', 'kilobyte-image-optimizer' );
		const estimatedTotal = presetItems?.length ??
			( mode === 'restore' ? stats?.restorable_count : mode === 'optimize' ? stats?.remaining_count : stats?.total_images ) ??
			0;
		let done = 0;
		let succeeded = 0;
		let skipped = 0;
		let missing = 0;
		const failed: QueueItem[] = [];
		const pendingRetry = new Map( presetItems?.map( ( item ) => [ item.id, item ] ) );
		let before = 0;

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
			do {
				report( scanning );
				scan.current = new AbortController();
				const page = presetItems
					? { items: presetItems, skipped_missing: 0, next_before: 0 }
					: await wpRestClient.getQueuePage( mode, before, scan.current.signal );
				scan.current = null;
				before = page.next_before;
				missing += page.skipped_missing ?? 0;

				// Large photos need more decode memory per worker.
				const serial = Boolean( presetItems ) || page.items.some( ( item ) => item.width * item.height > 12_000_000 );
				await runBulkQueue< QueueItem >( {
					items: page.items,
					concurrency: serial ? 1 : savedSettings.concurrency,
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
								pushLog( {
									title: item.filename,
									metric: __( 'Restored', 'kilobyte-image-optimizer' ),
									subtitle: restoreDetails( restored ),
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
									item, savedSettings, browserCompressor, wpRestClient,
									( stage, filename, download ) => report( stages[ stage ], filename, download ),
									cfg?.outputMimes ?? []
								);
								pushLog( outcome );
								if ( outcome.tone === 'muted' ) {
									skipped += 1;
									return;
								}
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
			} while ( before > 0 && ! stopRef.current );

			if ( ! done && ! stopRef.current ) {
				setNotice( {
					status: 'success',
					message: missing
						? __( 'No available files to process. Some images are missing or stored elsewhere.', 'kilobyte-image-optimizer' )
						: __( 'There are no images left for this action.', 'kilobyte-image-optimizer' ),
				} );
			} else {
				setLastSummary( { mode, succeeded, skipped, failed: failed.length, stopped: stopRef.current } );
			}
			setPhase( stopRef.current ? 'stopped' : failed.length ? 'completed_with_errors' : 'completed' );
		} catch ( err ) {
			setLastSummary( { mode, succeeded, skipped, failed: failed.length, stopped: stopRef.current } );
			if ( stopRef.current ) {
				setPhase( 'stopped' );
			} else {
				setPhase( 'completed_with_errors' );
				setErrorNotice( err );
			}
		} finally {
			runningRef.current = false;
			scan.current = null;
			setFailedItems( [ ...failed, ...pendingRetry.values() ] );
			setActiveMode( null );
			setStopping( false );
			setProgress( null );
			await refreshStats();
		}
	};

	const handleBulk = ( mode: QueueMode ) => executeQueueRun( mode );
	const handleRetryFailed = () => executeQueueRun( lastSummary?.mode ?? 'optimize', failedItems );

	const handleSaveSettings = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const updated = await wpRestClient.saveSettings( settings );
			setSettings( updated );
			setSavedSettings( updated );
			setNotice( {
				status: 'success',
				message: __( 'Preference saved.', 'kilobyte-image-optimizer' ),
			} );
		} catch ( err ) {
			setErrorNotice( err );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Surface variant="primary" as="div">
			<Spacer paddingX={ 6 } paddingY={ 5 }>
				<VStack spacing={ 1 }>
					<Heading level={ 1 } size={ 20 }>
						{ __( 'Image optimization', 'kilobyte-image-optimizer' ) }
					</Heading>
					<Text variant="muted" size={ 13 }>
						{ __(
							'Make your images smaller and keep their existing links.',
							'kilobyte-image-optimizer'
						) }
					</Text>
				</VStack>
			</Spacer>

			<Divider />

			<Spacer paddingX={ 6 } paddingY={ 6 }>
				<VStack spacing={ 5 } style={ { maxWidth: 680, margin: '0 auto' } }>
					{ notice && (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							<VStack spacing={ 1 }>
								<Text size={ 13 } weight={ 500 }>
									{ notice.message }
								</Text>
								{ notice.action && (
									<Text size={ 12 } variant="muted">
										{ notice.action }
									</Text>
								) }
								{ notice.technical && (
									<details>
										<summary style={ { cursor: 'pointer', fontSize: 12 } }>
											{ __( 'Technical details', 'kilobyte-image-optimizer' ) }
										</summary>
										<Text size={ 12 } variant="muted">
											{ notice.technical }
										</Text>
									</details>
								) }
							</VStack>
						</Notice>
					) }

					{ stats?.needs_reconcile && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'The image totals may be out of date. Ask your site administrator to run wp kilobyte stats --reconcile.', 'kilobyte-image-optimizer' ) }
						</Notice>
					) }
					<StatsCard stats={ stats } error={ statsError } onRetry={ refreshStats } />

					<PreviewCard settings={ settings } />

					<BulkOptimizerCard
						stats={ stats }
						support={ support }
						format={ savedSettings.format }
						autoEnabled={ savedSettings.enabled }
						phase={ phase }
						activeMode={ activeMode }
						failedCount={ failedItems.length }
						lastSummary={ lastSummary }
						stopping={ stopping }
						progress={ progress }
						elapsedSeconds={ elapsedSeconds }
						logs={ logs }
						onStart={ handleBulk }
						onRetryFailed={ handleRetryFailed }
						onStop={ handleStop }
						onRequestRestore={ () => setConfirmAction( 'restore' ) }
					/>

					{ cfg?.canManage && (
						<SettingsCard
							settings={ settings }
							dirty={ isSettingsDirty }
							saving={ saving }
							onChange={ setSettings }
							onSave={ handleSaveSettings }
						/>
					) }
				</VStack>
			</Spacer>

			<ConfirmDialog
				isOpen={ confirmAction === 'restore' }
				onConfirm={ () => {
					setConfirmAction( null );
					handleBulk( 'restore' );
				} }
				onCancel={ () => setConfirmAction( null ) }
				confirmButtonText={ __( 'Restore all', 'kilobyte-image-optimizer' ) }
			>
				{ __(
					'Use the original files again? The smaller copies will be removed after cached pages have time to update.',
					'kilobyte-image-optimizer'
				) }
			</ConfirmDialog>

		</Surface>
	);
}
