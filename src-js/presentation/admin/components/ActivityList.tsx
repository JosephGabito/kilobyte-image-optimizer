import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Icon,
	__experimentalHStack as HStack,
	__experimentalItem as Item,
	__experimentalItemGroup as ItemGroup,
	__experimentalText as Text,
	__experimentalTruncate as Truncate,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { caution, check, chevronLeft, chevronRight, info } from '@wordpress/icons';
import type { LogEntry } from '../../../domain/types';
import { optimizationSkip } from '../../optimizationSkip';

interface ActivityListProps {
	items: LogEntry[];
	skippedByReason?: Record< string, number >;
}

const PAGE_SIZE = 5;

export function ActivityList( { items, skippedByReason }: ActivityListProps ) {
	const [ filter, setFilter ] = useState< 'all' | 'error' | 'muted' >( 'all' );
	const [ page, setPage ] = useState( 1 );

	const errorCount = items.filter( ( row ) => row.tone === 'error' ).length;
	const skippedCount = items.reduce( ( count, row ) => count + ( row.tone === 'muted' ? row.count ?? 1 : 0 ), 0 );
	const skipTotals = Object.entries( skippedByReason ?? {} );
	const allSkips = skipTotals.reduce( ( count, [ , total ] ) => count + total, 0 );
	const filtered = filter === 'all' ? items : items.filter( ( row ) => row.tone === filter );

	const totalPages = Math.max( 1, Math.ceil( filtered.length / PAGE_SIZE ) );
	const safePage = Math.min( page, totalPages );
	const start = ( safePage - 1 ) * PAGE_SIZE;
	const visible = filtered.slice( start, start + PAGE_SIZE );

	useEffect( () => {
		if ( ( filter === 'error' && errorCount === 0 ) || ( filter === 'muted' && skippedCount === 0 ) ) {
			setFilter( 'all' );
		}
	}, [ filter, errorCount, skippedCount ] );

	useEffect( () => {
		setPage( 1 );
	}, [ filter ] );

	if ( items.length === 0 ) {
		return null;
	}

	return (
		<VStack spacing={ 2 }>
			{ allSkips > skippedCount && (
				<VStack spacing={ 1 }>
					<Text size={ 13 } weight={ 600 }>{ __( 'Skipped images from the full run', 'kilobyte-image-optimizer' ) }</Text>
					{ skipTotals.map( ( [ reason, total ] ) => (
						<Text key={ reason } size={ 12 }>
							{ sprintf(
								/* translators: 1: Number of images. 2: Reason images were skipped. */
								__( '%1$d · %2$s', 'kilobyte-image-optimizer' ),
								total,
								optimizationSkip( reason ).subtitle
							) }
						</Text>
					) ) }
				</VStack>
			) }
			{ ( errorCount > 0 || skippedCount > 0 || totalPages > 1 ) && (
				<HStack justify="space-between" alignment="center" wrap>
					<HStack spacing={ 1 } expanded={ false }>
						<Button
							variant={ filter === 'all' ? 'secondary' : 'tertiary' }
							size="compact"
							onClick={ () => setFilter( 'all' ) }
						>
							{ sprintf(
								/* translators: %d: total activity count */
								__( 'All (%d)', 'kilobyte-image-optimizer' ),
								items.length
							) }
						</Button>

						{ errorCount > 0 && (
							<Button
								variant={ filter === 'error' ? 'secondary' : 'tertiary' }
								size="compact"
								isDestructive
								onClick={ () => setFilter( 'error' ) }
							>
								{ sprintf(
									/* translators: %d: failed item count */
									__( 'Failed (%d)', 'kilobyte-image-optimizer' ),
									errorCount
								) }
							</Button>
						) }
						{ skippedCount > 0 && (
							<Button
								variant={ filter === 'muted' ? 'secondary' : 'tertiary' }
								size="compact"
								onClick={ () => setFilter( 'muted' ) }
							>
								{ sprintf(
									/* translators: %d: skipped activity count. */
									__( 'Skipped (%d)', 'kilobyte-image-optimizer' ),
									skippedCount
								) }
							</Button>
						) }
					</HStack>

					{ totalPages > 1 && (
						<HStack spacing={ 1 } expanded={ false } alignment="center">
							<Text size={ 12 } variant="muted">
								{ sprintf(
									/* translators: 1: current page, 2: total pages */
									__( 'Page %1$d of %2$d', 'kilobyte-image-optimizer' ),
									safePage,
									totalPages
								) }
							</Text>
							<Button
								variant="tertiary"
								size="compact"
								icon={ chevronLeft }
								label={ __( 'Previous page', 'kilobyte-image-optimizer' ) }
								disabled={ safePage <= 1 }
								onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
							/>
							<Button
								variant="tertiary"
								size="compact"
								icon={ chevronRight }
								label={ __( 'Next page', 'kilobyte-image-optimizer' ) }
								disabled={ safePage >= totalPages }
								onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }
							/>
						</HStack>
					) }
				</HStack>
			) }

			<ItemGroup isSeparated>
				{ visible.map( ( row ) => {
					const icon =
						row.tone === 'success'
							? check
							: row.tone === 'error'
							? caution
							: info;

					return (
						<Item key={ row.id }>
							<VStack spacing={ 1 }>
								<HStack justify="space-between" alignment="top" spacing={ 3 }>
									<HStack spacing={ 2 } alignment="top" style={ { minWidth: 0, flex: 1 } }>
										<Icon icon={ icon } size={ 18 } style={ { flexShrink: 0, marginTop: 1 } } />
										<VStack spacing={ 0 } style={ { minWidth: 0, flex: 1 } }>
											<Truncate
												limit={ 48 }
												ellipsizeMode="middle"
												style={ {
													fontSize: 13,
													fontWeight: 500,
													color: row.tone === 'error' ? '#cc1818' : undefined,
												} }
											>
												{ row.title }
											</Truncate>
											{ row.subtitle && (
												<Text size={ 12 } variant="muted">
													{ row.subtitle }
												</Text>
											) }
										</VStack>
									</HStack>

									{ row.metric && (
										<Text
											size={ 12 }
											weight={ 600 }
											variant={ row.tone === 'muted' ? 'muted' : undefined }
											isDestructive={ row.tone === 'error' }
											style={ { flexShrink: 0 } }
										>
											{ row.metric }
										</Text>
									) }
								</HStack>

								{ row.action && (
									<Text size={ 12 } variant="muted" style={ { paddingLeft: 26 } }>
										{ row.action }
									</Text>
								) }

								{ row.technical && (
									<details style={ { paddingLeft: 26 } }>
										<summary style={ { cursor: 'pointer', fontSize: 11 } }>
											{ __( 'Technical details', 'kilobyte-image-optimizer' ) }
										</summary>
										<Text size={ 11 } variant="muted">
											{ row.technical }
										</Text>
									</details>
								) }
							</VStack>
						</Item>
					);
				} ) }
			</ItemGroup>
		</VStack>
	);
}
