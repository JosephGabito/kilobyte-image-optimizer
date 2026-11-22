import { __, sprintf } from '@wordpress/i18n';
import {
	ProgressBar,
	__experimentalHStack as HStack,
	__experimentalSpacer as Spacer,
	__experimentalSurface as Surface,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { formatBytes } from '../../../domain/format';
import type { BulkProgress } from '../../../domain/types';

export function QueueProgress( { progress, elapsedSeconds }: { progress: BulkProgress; elapsedSeconds: number } ) {
	const progressPercent = progress.total > 0 ? Math.min( 100, Math.round( ( progress.done / progress.total ) * 100 ) ) : 0;
	return (
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
						<Text size={ 12 } weight={ 500 }>
							{ __( 'Keep this tab open and do not reload until finished.', 'kilobyte-image-optimizer' ) }
						</Text>
					</HStack>
				</VStack>
			</Spacer>
		</Surface>
	);
}
