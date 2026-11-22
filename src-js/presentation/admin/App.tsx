import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Notice,
	TabPanel,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	__experimentalSurface as Surface,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { toFriendlyError, type FriendlyError } from '../../domain/errors';
import {
	DEFAULT_SETTINGS,
	type AdminBootstrapConfig,
	type BrowserSupport,
	type LibraryStats,
	type PluginSettings,
} from '../../domain/types';
import { browserCompressor } from '../../infrastructure/browserCompressor';
import { wpRestClient } from '../../infrastructure/wpRestClient';
import { BulkOptimizerCard } from './components/BulkOptimizerCard';
import { PreviewCard } from './components/PreviewCard';
import { QueueProgress } from './components/QueueProgress';
import { SelectiveOptimizeCard, type SelectedMediaItem } from './components/SelectiveOptimizeCard';
import { SettingsCard } from './components/SettingsCard';
import { StatsCard } from './components/StatsCard';
import { useOptimizationQueue } from './useOptimizationQueue';

type Job = 'preview' | 'selective' | 'bulk';

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
	const [ saving, setSaving ] = useState( false );
	const [ selected, setSelected ] = useState< SelectedMediaItem[] >( [] );
	const [ activeJob, setActiveJob ] = useState< Job | null >( null );
	const activeJobRef = useRef< Job | null >( null );
	const [ confirmAction, setConfirmAction ] = useState< 'restore' | null >( null );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error' | 'info';
		filename?: string;
		message: string;
		action?: string;
		technical?: string;
	} | null >( null );

	const beginJob = ( job: Job ) => {
		if ( activeJobRef.current ) {
			return false;
		}
		activeJobRef.current = job;
		setActiveJob( job );
		setElapsedSeconds( 0 );
		setNotice( null );
		return true;
	};

	const finishJob = () => {
		activeJobRef.current = null;
		setActiveJob( null );
	};

	const setErrorNotice = ( err: unknown, filename?: string ) => {
		const parsed: FriendlyError = toFriendlyError( err );
		setNotice( {
			status: 'error',
			filename,
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
		const onRestored = () => {
			refreshStats();
		};
		window.addEventListener( 'kilobyte:restored', onRestored );
		return () => window.removeEventListener( 'kilobyte:restored', onRestored );
	}, [] );

	useEffect( () => {
		if ( ! activeJob ) {
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
	}, [ activeJob ] );

	const queueOptions = {
		settings: savedSettings,
		outputMimes: cfg?.outputMimes ?? [],
		stats,
		finish: finishJob,
		onError: setErrorNotice,
		onEmpty: ( message: string ) => setNotice( { status: 'success', message } ),
		onFinished: refreshStats,
	};
	const bulk = useOptimizationQueue( { ...queueOptions, begin: () => beginJob( 'bulk' ) } );
	const selective = useOptimizationQueue( { ...queueOptions, begin: () => beginJob( 'selective' ) } );
	const activeQueue = activeJob === 'bulk' ? bulk : activeJob === 'selective' ? selective : null;

	const handleSaveSettings = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const updated = await wpRestClient.saveSettings( settings );
			if ( window.KilobyteIOUploadConfig ) {
				window.KilobyteIOUploadConfig.settings = updated;
			}
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

	const tabs = [
		{ name: 'preview', title: __( 'Preview', 'kilobyte-image-optimizer' ) },
		{ name: 'selective', title: __( 'Selective optimize', 'kilobyte-image-optimizer' ) },
		{ name: 'bulk', title: __( 'Bulk optimize', 'kilobyte-image-optimizer' ) },
		...( cfg?.canManage
			? [ { name: 'preference', title: __( 'Preference', 'kilobyte-image-optimizer' ) } ]
			: [] ),
	];
	const hashTab = window.location.hash.replace( /^#/, '' );
	const initialTab = tabs.some( ( tab ) => tab.name === hashTab ) ? hashTab : 'preview';

	return (
		<Surface variant="primary" as="div">
			<Spacer paddingX={ 6 } paddingTop={ 5 } paddingBottom={ 3 }>
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

			<TabPanel
				tabs={ tabs }
				initialTabName={ initialTab }
				onSelect={ ( tabName ) => {
					if ( window.history?.replaceState ) {
						window.history.replaceState( null, '', `#${ tabName }` );
					}
				} }
			>
				{ ( tab ) => (
					<Spacer paddingX={ 6 } paddingY={ 6 }>
						<VStack spacing={ 5 } style={ { maxWidth: 680, margin: '0 auto' } }>
							{ notice && (
								<Notice
									status={ notice.status }
									onRemove={ () => setNotice( null ) }
								>
									<VStack spacing={ 1 }>
										{ notice.filename && <Text size={ 13 } weight={ 600 }>{ notice.filename }</Text> }
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

							{ activeQueue?.progress && activeJob !== tab.name && (
								<VStack spacing={ 2 }>
									<QueueProgress progress={ activeQueue.progress } elapsedSeconds={ elapsedSeconds } />
									<Button
										variant="secondary"
										size="compact"
										isBusy={ activeQueue.stopping }
										disabled={ activeQueue.stopping }
										onClick={ activeQueue.stop }
									>
										{ activeQueue.stopping ? __( 'Stopping...', 'kilobyte-image-optimizer' ) : __( 'Stop', 'kilobyte-image-optimizer' ) }
									</Button>
								</VStack>
							) }
							{ activeJob === 'preview' && tab.name !== 'preview' && (
								<Notice status="info" isDismissible={ false }>
									{ __( 'Saving an optimized copy. Keep this tab open until it finishes.', 'kilobyte-image-optimizer' ) }
								</Notice>
							) }

							{ tab.name === 'preview' && (
								<PreviewCard
									settings={ settings }
									disabled={ Boolean( activeJob ) }
									outputMimes={ cfg?.outputMimes ?? [] }
									onOptimized={ refreshStats }
									onStartSave={ () => beginJob( 'preview' ) }
									onFinishSave={ finishJob }
									onSaveMessage={ ( filename, message, status ) => setNotice( { filename, message, status } ) }
									onSaveError={ setErrorNotice }
								/>
							) }

							{ tab.name === 'selective' && (
								<SelectiveOptimizeCard
									selected={ selected }
									onSelectionChange={ setSelected }
									disabled={ Boolean( activeJob && activeJob !== 'selective' ) }
									running={ selective.activeMode !== null }
									stopping={ selective.stopping }
									stopped={ selective.lastSummary?.stopped ?? false }
									retryItems={ selective.retryItems }
									progress={ selective.progress }
									elapsedSeconds={ elapsedSeconds }
									logs={ selective.logs }
									onStart={ ( items, retry ) => selective.run( 'optimize', items, retry ) }
									onStop={ selective.stop }
									onClear={ selective.clearResults }
								/>
							) }

							{ tab.name === 'bulk' && (
								<>
									<StatsCard stats={ stats } error={ statsError } onRetry={ refreshStats } />

									<BulkOptimizerCard
										stats={ stats }
										support={ support }
										format={ savedSettings.format }
										autoEnabled={ savedSettings.enabled }
										phase={ bulk.phase }
										disabled={ Boolean( activeJob && activeJob !== 'bulk' ) }
										activeMode={ bulk.activeMode }
										failedCount={ bulk.retryItems.length }
										hasMore={ bulk.nextPage !== null }
										lastSummary={ bulk.lastSummary }
										stopping={ bulk.stopping }
										progress={ bulk.progress }
										elapsedSeconds={ elapsedSeconds }
										logs={ bulk.logs }
										onStart={ bulk.run }
										onRetryFailed={ bulk.resume }
										onStop={ bulk.stop }
										onRequestRestore={ () => setConfirmAction( 'restore' ) }
									/>
								</>
							) }

							{ tab.name === 'preference' && cfg?.canManage && (
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
				) }
			</TabPanel>

			<ConfirmDialog
				isOpen={ confirmAction === 'restore' }
				onConfirm={ () => {
					setConfirmAction( null );
					bulk.run( 'restore' );
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
