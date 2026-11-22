import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Icon,
	ProgressBar,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	__experimentalSurface as Surface,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { check, copy, image, undo, update } from '@wordpress/icons';
import { toFriendlyError, type FriendlyError } from '../../../domain/errors';
import { formatBytes } from '../../../domain/format';
import { buildClientSummary } from '../../../domain/presets';
import {
	getPrimaryOptimizeLabel,
	type BulkPhase,
	type RunSummary,
} from '../../../domain/session';
import { ActivityList } from './ActivityList';
import { GuidanceBanner } from './GuidanceBanner';
import type {
	BrowserSupport,
	BulkProgress,
	LibraryStats,
	LogEntry,
	OutputFormat,
	QueueMode,
} from '../../../domain/types';

interface BulkOptimizerCardProps {
	stats: LibraryStats | null;
	support: BrowserSupport | null;
	format: OutputFormat;
	autoEnabled: boolean;
	phase: BulkPhase;
	activeMode: QueueMode | null;
	failedCount: number;
	lastSummary: RunSummary | null;
	stopping: boolean;
	progress: BulkProgress | null;
	elapsedSeconds: number;
	logs: LogEntry[];
	onStart: ( mode: QueueMode ) => void;
	onRetryFailed: () => void;
	onStop: () => void;
	onRequestRestore: () => void;
}

export function BulkOptimizerCard( {
	stats,
	support,
	format,
	autoEnabled,
	phase,
	activeMode,
	failedCount,
	lastSummary,
	stopping,
	progress,
	elapsedSeconds,
	logs,
	onStart,
	onRetryFailed,
	onStop,
	onRequestRestore,
}: BulkOptimizerCardProps ) {
	const [ copied, setCopied ] = useState( false );
	const [ copyError, setCopyError ] = useState< FriendlyError | null >( null );
	const running = activeMode !== null;
	const isRetryState = ( phase === 'completed_with_errors' || phase === 'stopped' ) && failedCount > 0;
	const primaryLabel = getPrimaryOptimizeLabel( ! isRetryState && phase === 'stopped' && lastSummary?.mode !== 'optimize' ? 'idle' : phase, activeMode, failedCount );

	const progressPercent =
		progress && progress.total > 0
			? Math.round( ( progress.done / progress.total ) * 100 )
			: 0;

	const handleCopySummary = async () => {
		if ( ! stats ) {
			return;
		}
		try {
			setCopyError( null );
			await navigator.clipboard.writeText( buildClientSummary( stats, format ) );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( error ) {
			setCopyError( toFriendlyError( { code: 'clipboard_failed', message: String( error ) } ) );
		}
	};

	return (
		<Card>
			<CardHeader>
				<HStack justify="space-between" wrap>
					<HStack spacing={ 2 } expanded={ false }>
						<Icon icon={ image } size={ 20 } />
						<Heading level={ 3 } size={ 15 }>
							{ __( 'Bulk optimization', 'kilobyte-image-optimizer' ) }
						</Heading>
					</HStack>

					{ stats && stats.optimized_count > 0 && (
						<Button
							variant="tertiary"
							size="compact"
							icon={ copied ? check : copy }
							onClick={ handleCopySummary }
						>
							{ copied
								? __( 'Copied', 'kilobyte-image-optimizer' )
								: __( 'Copy summary', 'kilobyte-image-optimizer' ) }
						</Button>
					) }
				</HStack>
			</CardHeader>

			<CardBody>
				<VStack spacing={ 4 }>
					<GuidanceBanner stats={ stats } autoEnabled={ autoEnabled } />
					{ copyError && (
						<VStack spacing={ 1 }>
							<Text role="status">{ copyError.friendly } { copyError.action }</Text>
							<details>
								<summary>{ __( 'Technical details', 'kilobyte-image-optimizer' ) }</summary>
								<Text>{ copyError.technical }</Text>
							</details>
						</VStack>
					) }

					<HStack spacing={ 3 } justify="flex-start" alignment="center" wrap>
						<Button
							variant="primary"
							__next40pxDefaultSize
							icon={ isRetryState ? update : image }
							isBusy={ activeMode === 'optimize' }
							disabled={
								running ||
								( ! isRetryState && stats !== null && stats.remaining_count === 0 )
							}
							onClick={ () => ( isRetryState ? onRetryFailed() : onStart( 'optimize' ) ) }
						>
							{ primaryLabel }
						</Button>

						<Button
							variant="tertiary"
							size="compact"
							icon={ update }
							isBusy={ activeMode === 'regenerate' }
							disabled={ running }
							onClick={ () => onStart( 'regenerate' ) }
						>
							{ activeMode === 'regenerate'
								? __( 'Regenerating...', 'kilobyte-image-optimizer' )
								: __( 'Regenerate thumbnails', 'kilobyte-image-optimizer' ) }
						</Button>

						{ running && (
							<Button
								variant="tertiary"
								size="compact"
								isDestructive
								isBusy={ stopping }
								disabled={ stopping }
								onClick={ onStop }
							>
								{ stopping
									? __( 'Stopping...', 'kilobyte-image-optimizer' )
									: __( 'Stop', 'kilobyte-image-optimizer' ) }
							</Button>
						) }
					</HStack>

					{ progress && (
						<Surface variant="secondary">
							<Spacer padding={ 3 }>
								<VStack spacing={ 2 }>
									<HStack justify="space-between" wrap>
										<Text size={ 13 } weight={ 600 }>
											{ progress.currentFile
												? `${ progress.stage }: ${ progress.currentFile }`
												: progress.stage }
										</Text>
										<Text size={ 12 } variant="muted">
											{ progress.total > 0
												? `${ progress.done } of ${ progress.total } (${ progressPercent }%)`
												: '' }
										</Text>
									</HStack>

									{ progress.download && (
										<Text size={ 12 } variant="muted">
											{ sprintf(
												/* translators: %s: amount of image data downloaded. */
												__( '%s loaded', 'kilobyte-image-optimizer' ),
												formatBytes( progress.download.loaded )
											) }
										</Text>
									) }
									<ProgressBar
										value={ progress.download
											? ( progress.download.total > 0 ? Math.min( 100, 100 * progress.download.loaded / progress.download.total ) : undefined )
											: ( progress.total > 0 ? progressPercent : undefined ) }
									/>

									<HStack justify="space-between" wrap>
										<Text size={ 12 } variant="muted">
											{ sprintf(
												/* translators: %d: elapsed seconds. */
												__( '%ds elapsed', 'kilobyte-image-optimizer' ), elapsedSeconds
											) }
											{ progress.eta ? ` · ${ progress.eta }` : '' }
										</Text>
										{ running && (
											<Text size={ 12 } weight={ 500 }>
												{ __(
													'Keep this tab open and do not reload until finished.',
													'kilobyte-image-optimizer'
												) }
											</Text>
										) }
									</HStack>
								</VStack>
							</Spacer>
						</Surface>
					) }

					{ ! running && lastSummary && (
						<Surface variant="secondary">
							<Spacer padding={ 3 }>
								<Text size={ 13 } weight={ 500 }>
									{ lastSummary.failed > 0
										? sprintf(
												/* translators: 1: succeeded count, 2: failed count */
												__( 'Finished with %1$d processed and %2$d failed. Click retry above to try the failed images again.', 'kilobyte-image-optimizer' ),
												lastSummary.succeeded + lastSummary.skipped,
												lastSummary.failed
										  )
										: lastSummary.stopped
										? sprintf(
												/* translators: %d: processed count */
												__( 'Stopped after %d images.', 'kilobyte-image-optimizer' ),
												lastSummary.succeeded + lastSummary.skipped
										  )
										: sprintf(
												/* translators: %d: processed count */
												__( 'Processed %d images.', 'kilobyte-image-optimizer' ),
												lastSummary.succeeded + lastSummary.skipped
										  ) }
								</Text>
							</Spacer>
						</Surface>
					) }

					<ActivityList items={ logs } />
				</VStack>
			</CardBody>

			<CardFooter>
				<HStack justify="space-between" wrap>
					<HStack spacing={ 4 } expanded={ false } wrap>
						<HStack spacing={ 1 } expanded={ false }>
							<Icon icon={ check } size={ 16 } />
							<Text size={ 12 } variant="muted">
								{ support && ! support.webp
									? __( 'Uses your site when needed', 'kilobyte-image-optimizer' )
									: __( 'Compresses in your browser when possible', 'kilobyte-image-optimizer' ) }
							</Text>
						</HStack>
						<HStack spacing={ 1 } expanded={ false }>
							<Icon icon={ check } size={ 16 } />
							<Text size={ 12 } variant="muted">
								{ __( 'Existing links protected', 'kilobyte-image-optimizer' ) }
							</Text>
						</HStack>
					</HStack>

					<HStack spacing={ 2 } expanded={ false }>
						{ stats && stats.restorable_count > 0 && (
							<Button
								variant="tertiary"
								size="compact"
								icon={ undo }
								isBusy={ activeMode === 'restore' }
								disabled={ running }
								onClick={ onRequestRestore }
							>
								{ activeMode === 'restore'
									? __( 'Restoring...', 'kilobyte-image-optimizer' )
									: __( 'Restore all', 'kilobyte-image-optimizer' ) }
							</Button>
						) }
					</HStack>
				</HStack>
			</CardFooter>
		</Card>
	);
}
