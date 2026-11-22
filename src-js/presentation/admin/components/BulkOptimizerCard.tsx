import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Icon,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	__experimentalSurface as Surface,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { check, copy, image, undo, update } from '@wordpress/icons';
import { toFriendlyError, type FriendlyError } from '../../../domain/errors';
import { buildClientSummary } from '../../../domain/presets';
import {
	getPrimaryOptimizeLabel,
	type BulkPhase,
	type RunSummary,
} from '../../../domain/session';
import { ActivityList } from './ActivityList';
import { QueueProgress } from './QueueProgress';
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
	disabled?: boolean;
	stats: LibraryStats | null;
	support: BrowserSupport | null;
	format: OutputFormat;
	autoEnabled: boolean;
	phase: BulkPhase;
	activeMode: QueueMode | null;
	failedCount: number;
	hasMore: boolean;
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
	disabled = false,
	stats,
	support,
	format,
	autoEnabled,
	phase,
	activeMode,
	failedCount,
	hasMore,
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
	const isRetryState = ( phase === 'completed_with_errors' || phase === 'stopped' ) && ( failedCount > 0 || hasMore );
	const backupReminderId = ! disabled && ! running && ! isRetryState && stats?.total_images !== 0
		? 'kilobyte-io-bulk-backup-reminder'
		: undefined;
	const primaryLabel = isRetryState && ( phase === 'stopped' || hasMore )
		? __( 'Continue processing images', 'kilobyte-image-optimizer' )
		: ! isRetryState && ! running && stats?.remaining_count === 0
		? __( 'Check for new or changed images', 'kilobyte-image-optimizer' )
		: getPrimaryOptimizeLabel( ! isRetryState && phase === 'stopped' ? 'idle' : phase, activeMode, failedCount );

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

					{ backupReminderId && (
						<Text id={ backupReminderId } size={ 12 } variant="muted">
							{ __( 'Before a bulk run, consider backing up your Media Library. Optimization keeps your original files.', 'kilobyte-image-optimizer' ) }
						</Text>
					) }
					<HStack spacing={ 3 } justify="flex-start" alignment="center" wrap>
						<Button
							variant="primary"
							__next40pxDefaultSize
							icon={ isRetryState ? update : image }
							isBusy={ activeMode === 'optimize' }
							disabled={ disabled || running }
							aria-describedby={ backupReminderId }
							onClick={ () => ( isRetryState ? onRetryFailed() : onStart( 'optimize' ) ) }
						>
							{ primaryLabel }
						</Button>

						<Button
							variant="tertiary"
							size="compact"
							icon={ update }
							isBusy={ activeMode === 'regenerate' }
							disabled={ disabled || running }
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

					{ progress && <QueueProgress progress={ progress } elapsedSeconds={ elapsedSeconds } /> }

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

					<ActivityList items={ logs } skippedByReason={ lastSummary?.skippedByReason } />
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
								disabled={ disabled || running }
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
