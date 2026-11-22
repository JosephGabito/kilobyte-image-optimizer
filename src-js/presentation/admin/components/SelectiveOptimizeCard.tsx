import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Icon,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalItem as Item,
	__experimentalItemGroup as ItemGroup,
	__experimentalText as Text,
	__experimentalTruncate as Truncate,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import {
	check,
	chevronLeft,
	chevronRight,
	closeSmall,
	image,
	media,
	update,
} from '@wordpress/icons';
import { formatBytes } from '../../../domain/format';
import { getWpMedia } from '../../../infrastructure/wpMedia';
import type {
	BulkProgress,
	LogEntry,
	QueueItem,
} from '../../../domain/types';
import { ActivityList } from './ActivityList';
import { QueueProgress } from './QueueProgress';

interface SelectiveOptimizeCardProps {
	selected: SelectedMediaItem[];
	onSelectionChange: React.Dispatch< React.SetStateAction< SelectedMediaItem[] > >;
	disabled: boolean;
	running: boolean;
	stopping: boolean;
	stopped: boolean;
	retryItems: QueueItem[];
	progress: BulkProgress | null;
	elapsedSeconds: number;
	logs: LogEntry[];
	onStart: ( items: QueueItem[], retry: boolean ) => void;
	onStop: () => void;
	onClear: () => void;
}

export interface SelectedMediaItem extends QueueItem {
	thumbUrl?: string;
}

const SELECTION_PAGE_SIZE = 5;

export function SelectiveOptimizeCard( {
	selected,
	onSelectionChange,
	disabled,
	running,
	stopping,
	stopped,
	retryItems,
	progress,
	elapsedSeconds,
	logs,
	onStart,
	onStop,
	onClear,
}: SelectiveOptimizeCardProps ) {
	const [ selectionPage, setSelectionPage ] = useState( 1 );
	const retryIds = new Set( retryItems.map( ( item ) => item.id ) );
	const retrySelection = selected.filter( ( item ) => retryIds.has( item.id ) );
	const totalPages = Math.max( 1, Math.ceil( selected.length / SELECTION_PAGE_SIZE ) );
	const safePage = Math.min( selectionPage, totalPages );
	const pageStart = ( safePage - 1 ) * SELECTION_PAGE_SIZE;
	const visibleSelected = selected.slice( pageStart, pageStart + SELECTION_PAGE_SIZE );

	const handleSelectImages = () => {
		const wpMedia = getWpMedia();
		if ( ! wpMedia ) {
			return;
		}
		const frame = wpMedia( {
			title: __( 'Select images to optimize', 'kilobyte-image-optimizer' ),
			button: { text: __( 'Use selected images', 'kilobyte-image-optimizer' ) },
			library: { type: 'image' },
			multiple: 'add',
		} );
		frame.on( 'select', () => {
			const selection = frame.state().get( 'selection' );
			const models = Array.isArray( selection?.models ) ? selection.models : [];
			const next: SelectedMediaItem[] = [];
			const seenIds = new Set< number >();

			for ( const model of models ) {
				const json = model.toJSON();
				if ( typeof json.id !== 'number' || json.id <= 0 || ! json.url || seenIds.has( json.id ) ) {
					continue;
				}
				seenIds.add( json.id );
				next.push( {
					id: json.id,
					title: json.title || json.filename || `#${ json.id }`,
					filename: json.filename || `image-${ json.id }.jpg`,
					url: json.url,
					mime_type: json.mime || 'image/jpeg',
					file_size: json.filesizeInBytes || 0,
					width: json.width || 0,
					height: json.height || 0,
					thumbUrl: json.sizes?.thumbnail?.url || json.url,
				} );
			}
			if ( next.length > 0 ) {
				onSelectionChange( ( current ) => {
					const currentIds = new Set( current.map( ( item ) => item.id ) );
					return [ ...current, ...next.filter( ( item ) => ! currentIds.has( item.id ) ) ];
				} );
			}
		} );
		frame.open();
	};

	const handleRemove = ( id: number ) => {
		onSelectionChange( ( current ) => current.filter( ( item ) => item.id !== id ) );
		setSelectionPage( Math.min( safePage, Math.max( 1, Math.ceil( ( selected.length - 1 ) / SELECTION_PAGE_SIZE ) ) ) );
	};

	const handleClear = () => {
		onSelectionChange( [] );
		setSelectionPage( 1 );
		onClear();
	};

	return (
		<Card>
			<CardHeader>
				<HStack justify="space-between" wrap>
					<HStack spacing={ 2 } expanded={ false }>
						<Icon icon={ image } size={ 20 } />
						<Heading level={ 3 } size={ 15 }>
							{ __( 'Selective optimize', 'kilobyte-image-optimizer' ) }
						</Heading>
					</HStack>

					{ selected.length > 0 && ! running && (
						<Button
							variant="tertiary"
							size="compact"
							onClick={ handleClear }
						>
							{ __( 'Clear', 'kilobyte-image-optimizer' ) }
						</Button>
					) }
				</HStack>
			</CardHeader>

			<CardBody>
				<VStack spacing={ 4 }>
					<Text variant="muted" size={ 13 }>
						{ __(
							'Choose specific photos from your Media Library to optimize without running a full bulk job.',
							'kilobyte-image-optimizer'
						) }
					</Text>

					<HStack spacing={ 2 } justify="flex-start" alignment="center" wrap>
						{ ! running ? (
							<>
								{ retrySelection.length > 0 ? (
									<Button
										variant="primary"
										icon={ update }
										disabled={ disabled }
										onClick={ () => onStart( retrySelection, true ) }
										__next40pxDefaultSize
									>
										{ sprintf(
											/* translators: %d: number of failed images */
											stopped
												? _n( 'Continue %d image', 'Continue %d images', retrySelection.length, 'kilobyte-image-optimizer' )
												: _n( 'Retry %d failed image', 'Retry %d failed images', retrySelection.length, 'kilobyte-image-optimizer' ),
											retrySelection.length
										) }
									</Button>
								) : (
									<Button
										variant={ selected.length > 0 ? 'primary' : 'secondary' }
										icon={ selected.length > 0 ? check : media }
										disabled={ disabled }
										onClick={
											selected.length > 0
												? () => onStart( selected, false )
												: handleSelectImages
										}
										__next40pxDefaultSize
									>
										{ selected.length > 0
											? sprintf(
													/* translators: %d: number of selected images */
													_n(
														'Optimize %d selected image',
														'Optimize %d selected images',
														selected.length,
														'kilobyte-image-optimizer'
													),
													selected.length
											  )
											: __( 'Choose images', 'kilobyte-image-optimizer' ) }
									</Button>
								) }

								{ retrySelection.length > 0 && retrySelection.length < selected.length && (
									<Button variant="tertiary" size="compact" disabled={ disabled } onClick={ () => onStart( selected, false ) }>
										{ __( 'Optimize selection', 'kilobyte-image-optimizer' ) }
									</Button>
								) }

								{ selected.length > 0 && (
									<Button
										variant="tertiary"
										icon={ media }
										disabled={ disabled }
										onClick={ handleSelectImages }
										__next40pxDefaultSize
									>
										{ __( 'Add more', 'kilobyte-image-optimizer' ) }
									</Button>
								) }
							</>
						) : (
							<Button
								variant="secondary"
								isDestructive
								isBusy={ stopping }
								disabled={ stopping }
								onClick={ onStop }
								__next40pxDefaultSize
							>
								{ stopping
									? __( 'Stopping...', 'kilobyte-image-optimizer' )
									: __( 'Stop', 'kilobyte-image-optimizer' ) }
							</Button>
						) }
					</HStack>

					{ progress && <QueueProgress progress={ progress } elapsedSeconds={ elapsedSeconds } /> }

					{ selected.length > 0 && ! running && (
						<VStack spacing={ 2 }>
							<ItemGroup isSeparated>
								{ visibleSelected.map( ( item ) => (
									<Item key={ item.id }>
										<HStack justify="space-between" alignment="center" spacing={ 3 }>
											<HStack justify="flex-start" spacing={ 3 } alignment="center" style={ { minWidth: 0 } }>
												{ item.thumbUrl && (
													<img
														src={ item.thumbUrl }
														alt={ item.filename }
														loading="lazy"
														style={ {
															width: 36,
															height: 36,
															objectFit: 'cover',
															borderRadius: 2,
															display: 'block',
															flexShrink: 0,
														} }
													/>
												) }
												<VStack spacing={ 0 } style={ { minWidth: 0 } }>
													<Truncate numberOfLines={ 1 }>
														<Text size={ 13 } weight={ 500 }>
															{ item.filename }
														</Text>
													</Truncate>
													{ item.file_size > 0 && (
														<Text size={ 12 } variant="muted">
															{ formatBytes( item.file_size ) }
														</Text>
													) }
												</VStack>
											</HStack>

											<Button
												icon={ closeSmall }
												label={ __( 'Remove image', 'kilobyte-image-optimizer' ) }
												size="small"
												onClick={ () => handleRemove( item.id ) }
											/>
										</HStack>
									</Item>
								) ) }
							</ItemGroup>

							{ totalPages > 1 && (
								<HStack justify="space-between" alignment="center">
									<Text size={ 12 } variant="muted">
										{ sprintf(
											/* translators: 1: first item number, 2: last item number, 3: total selected images */
											__( 'Showing %1$d–%2$d of %3$d selected', 'kilobyte-image-optimizer' ),
											pageStart + 1,
											Math.min( pageStart + SELECTION_PAGE_SIZE, selected.length ),
											selected.length
										) }
									</Text>
									<HStack spacing={ 1 } expanded={ false }>
										<Button
											icon={ chevronLeft }
											label={ __( 'Previous page', 'kilobyte-image-optimizer' ) }
											size="small"
											disabled={ safePage <= 1 }
											onClick={ () => setSelectionPage( Math.max( 1, safePage - 1 ) ) }
										/>
										<Text size={ 12 } variant="muted">
											{ `${ safePage } / ${ totalPages }` }
										</Text>
										<Button
											icon={ chevronRight }
											label={ __( 'Next page', 'kilobyte-image-optimizer' ) }
											size="small"
											disabled={ safePage >= totalPages }
											onClick={ () => setSelectionPage( Math.min( totalPages, safePage + 1 ) ) }
										/>
									</HStack>
								</HStack>
							) }
						</VStack>
					) }

					{ logs.length > 0 && <ActivityList items={ logs } /> }
				</VStack>
			</CardBody>
		</Card>
	);
}
