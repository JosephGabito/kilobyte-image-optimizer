import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	Spinner,
	__experimentalGrid as Grid,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import type { FriendlyError } from '../../../domain/errors';
import { formatBytes } from '../../../domain/format';
import type { LibraryStats } from '../../../domain/types';

interface StatsCardProps {
	stats: LibraryStats | null;
	error: FriendlyError | null;
	onRetry: () => void;
}

export function StatsCard( { stats, error, onRetry }: StatsCardProps ) {
	return (
		<Card>
			<CardBody>
				{ error ? (
					<VStack spacing={ 2 }>
						<Text role="status">{ error.friendly } { error.action }</Text>
						<details>
							<summary>{ __( 'Technical details', 'kilobyte-image-optimizer' ) }</summary>
							<Text>{ error.technical }</Text>
						</details>
						<Button variant="secondary" size="compact" onClick={ onRetry }>
							{ __( 'Reload image totals', 'kilobyte-image-optimizer' ) }
						</Button>
					</VStack>
				) : ! stats ? (
					<HStack justify="center">
						<Spinner />
						<Text>{ __( 'Loading image totals...', 'kilobyte-image-optimizer' ) }</Text>
					</HStack>
				) : (
					<Grid columns={ 4 } gap={ 4 }>
						<VStack spacing={ 1 }>
							<Text size={ 12 } weight={ 500 } variant="muted">
								{ __( 'Transfer savings', 'kilobyte-image-optimizer' ) }
							</Text>
							<Heading level={ 2 } size={ 22 }>
								{ formatBytes( stats.total_saved ) }
							</Heading>
							<Text size={ 12 } variant="muted">
								{ stats.saved_percent > 0
									? sprintf(
											/* translators: %s: average percent saved */
											__( '%s%% smaller', 'kilobyte-image-optimizer' ),
											String( stats.saved_percent )
									  )
									: __( 'Ready', 'kilobyte-image-optimizer' ) }
							</Text>
						</VStack>

						<VStack spacing={ 1 }>
							<Text size={ 12 } weight={ 500 } variant="muted">
								{ __( 'Processed', 'kilobyte-image-optimizer' ) }
							</Text>
							<Heading level={ 2 } size={ 22 }>
								{ stats.optimized_count.toLocaleString() }
							</Heading>
							<Text size={ 12 } variant="muted">
								{ sprintf(
									/* translators: %s: total image count */
									__( 'of %s images', 'kilobyte-image-optimizer' ),
									stats.total_images.toLocaleString()
								) }
							</Text>
						</VStack>

						<VStack spacing={ 1 }>
							<Text size={ 12 } weight={ 500 } variant="muted">
								{ __( 'Remaining', 'kilobyte-image-optimizer' ) }
							</Text>
							<Heading level={ 2 } size={ 22 }>
								{ stats.remaining_count.toLocaleString() }
							</Heading>
							<Text size={ 12 } variant="muted">
								{ stats.remaining_count === 0
									? __( 'All done', 'kilobyte-image-optimizer' )
									: __( 'Remaining', 'kilobyte-image-optimizer' ) }
							</Text>
						</VStack>

						<VStack spacing={ 1 }>
							<Text size={ 12 } weight={ 500 } variant="muted">
								{ __( 'Originals', 'kilobyte-image-optimizer' ) }
							</Text>
							<Heading level={ 2 } size={ 22 }>
								{ stats.restorable_count.toLocaleString() }
							</Heading>
							<Text size={ 12 } variant="muted">
								{ stats.backup_bytes > 0
									? sprintf(
											/* translators: %s: formatted backup size */
											__( '%s in backups', 'kilobyte-image-optimizer' ),
											formatBytes( stats.backup_bytes )
									  )
									: __( 'Restorable', 'kilobyte-image-optimizer' ) }
							</Text>
						</VStack>
					</Grid>
				) }
			</CardBody>
		</Card>
	);
}
