import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormFileUpload,
	Icon,
	ProgressBar,
	__experimentalGrid as Grid,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalSurface as Surface,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { media, seen, upload } from '@wordpress/icons';
import { toFriendlyError, type FriendlyError } from '../../../domain/errors';
import { formatBytes } from '../../../domain/format';
import type { PluginSettings } from '../../../domain/types';
import { browserCompressor } from '../../../infrastructure/browserCompressor';
import { wpRestClient } from '../../../infrastructure/wpRestClient';

interface PreviewCardProps {
	settings: PluginSettings;
}

interface PreviewSource {
	filename: string;
	file: File;
	url?: string;
}

interface PreviewStage {
	label: string;
	percent?: number;
}

interface PreviewState {
	filename: string;
	originalUrl: string;
	originalSize: number;
	optimizedUrl?: string;
	optimizedSize?: number;
	savedPercent?: number;
	outputFormat?: string;
	formatMatches?: boolean;
	skipped?: boolean;
}

declare global {
	interface Window {
		wp?: {
			media?: ( options: Record< string, unknown > ) => {
				on: ( event: string, cb: () => void ) => void;
				state: () => {
					get: ( key: string ) => {
						first: () => {
							toJSON: () => {
								url: string;
								filename?: string;
								mime?: string;
								filesizeInBytes?: number;
							};
						};
					};
				};
				open: () => void;
			};
		};
	}
}

export function PreviewCard( { settings }: PreviewCardProps ) {
	const [ stage, setStage ] = useState< PreviewStage | null >( null );
	const [ elapsedSec, setElapsedSec ] = useState( 0 );
	const [ error, setError ] = useState< FriendlyError | null >( null );
	const [ preview, setPreview ] = useState< PreviewState | null >( null );
	const source = useRef< PreviewSource | null >( null );
	const request = useRef< AbortController | null >( null );
	const objectUrls = useRef< string[] >( [] );
	const currentSettings = useRef( settings );
	const timerRef = useRef< number | null >( null );
	currentSettings.current = settings;

	const cancelPreview = () => {
		request.current?.abort();
		objectUrls.current.forEach( ( url ) => URL.revokeObjectURL( url ) );
		objectUrls.current = [];
		if ( timerRef.current !== null ) {
			clearInterval( timerRef.current );
			timerRef.current = null;
		}
	};

	const beginPreview = () => {
		cancelPreview();
		const controller = new AbortController();
		request.current = controller;
		setError( null );
		setElapsedSec( 0 );
		const startedAt = Date.now();
		timerRef.current = window.setInterval( () => {
			setElapsedSec( Math.floor( ( Date.now() - startedAt ) / 100 ) / 10 );
		}, 100 );
		return controller.signal;
	};

	const finishPreview = ( signal: AbortSignal ) => {
		if ( signal.aborted ) {
			return;
		}
		if ( timerRef.current !== null ) {
			clearInterval( timerRef.current );
			timerRef.current = null;
		}
		setStage( null );
	};

	const createPreviewUrl = ( file: Blob ) => {
		const url = URL.createObjectURL( file );
		objectUrls.current.push( url );
		return url;
	};

	const compressSource = async ( src: PreviewSource, signal: AbortSignal ) => {
		const active = currentSettings.current;
		const originalUrl = src.url || createPreviewUrl( src.file );
		setPreview( { filename: src.filename, originalUrl, originalSize: src.file.size } );
		setStage( { label: __( 'Compressing image...', 'kilobyte-image-optimizer' ) } );

		try {
			const result = await browserCompressor.compress( src.file, active, signal, true );
			if ( signal.aborted ) {
				return;
			}
			if ( result.reason === 'animated' || result.reason === 'unsupported_mime' ) {
				throw new Error( result.reason === 'animated' ? 'animated_image' : 'unsupported_format' );
			}
			const savedPercent = result.originalSize > 0
				? Math.max( 0, Math.round( ( ( result.originalSize - result.optimizedSize ) / result.originalSize ) * 1000 ) / 10 )
				: 0;

			setPreview( {
				filename: src.filename,
				originalUrl,
				originalSize: result.originalSize,
				optimizedUrl: result.file ? createPreviewUrl( result.file ) : originalUrl,
				optimizedSize: result.optimizedSize,
				savedPercent,
				outputFormat: result.format?.toUpperCase(),
				formatMatches: active.format === 'original' || result.format === active.format,
				skipped: result.skipped,
			} );
		} catch ( error ) {
			if ( ! signal.aborted ) {
				setError( toFriendlyError( error ) );
			}
		} finally {
			finishPreview( signal );
		}
	};

	const loadFromUrl = async ( url: string, filename: string, mime?: string, knownBytes?: number ) => {
		const signal = beginPreview();
		source.current = null;
		setPreview( { filename, originalUrl: url, originalSize: knownBytes ?? 0 } );
		setStage( { label: __( 'Reading image...', 'kilobyte-image-optimizer' ) } );

		try {
			const blob = await wpRestClient.fetchImageBlob( url, ( loaded, total ) => {
				if ( signal.aborted ) {
					return;
				}
				setStage( {
					label: sprintf(
						/* translators: %s: how much of the image has loaded. */
						__( 'Reading image (%s loaded)...', 'kilobyte-image-optimizer' ),
						formatBytes( loaded )
					),
					percent: total > 0 ? Math.min( 100, Math.round( ( loaded / total ) * 100 ) ) : undefined,
				} );
			}, signal );

			if ( ! signal.aborted ) {
				const file = new File( [ blob ], filename, { type: blob.type || mime || 'image/jpeg' } );
				source.current = { filename, file, url };
				await compressSource( source.current, signal );
			}
		} catch ( error ) {
			if ( ! signal.aborted ) {
				setError( toFriendlyError( error ) );
				finishPreview( signal );
			}
		}
	};

	const handleOpenMediaPicker = () => {
		const wpMedia = window.wp?.media;
		if ( ! wpMedia ) {
			return;
		}
		const frame = wpMedia( {
			title: __( 'Choose an image to preview', 'kilobyte-image-optimizer' ),
			button: { text: __( 'Preview this image', 'kilobyte-image-optimizer' ) },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const selected = frame.state().get( 'selection' ).first()?.toJSON();
			if ( selected?.url ) {
				loadFromUrl(
					selected.url,
					selected.filename || 'image.jpg',
					selected.mime,
					selected.filesizeInBytes
				);
			}
		} );
		frame.open();
	};

	const handleLocalFileChange = ( event: React.ChangeEvent< HTMLInputElement > ) => {
		const picked = event.currentTarget.files?.[ 0 ];
		if ( ! picked ) {
			return;
		}
		event.currentTarget.value = '';
		source.current = { filename: picked.name, file: picked };
		compressSource( source.current, beginPreview() );
	};

	const handleClear = () => {
		cancelPreview();
		source.current = null;
		setStage( null );
		setError( null );
		setPreview( null );
	};

	useEffect( () => {
		if ( source.current ) {
			compressSource( source.current, beginPreview() );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ settings.format, settings.quality, settings.max_width, settings.max_height, settings.skip_smaller ] );

	useEffect( () => cancelPreview, [] );

	return (
		<Card>
			<CardHeader>
				<HStack justify="space-between" wrap>
					<HStack spacing={ 2 } expanded={ false }>
						<Icon icon={ seen } size={ 20 } />
						<Heading level={ 3 } size={ 15 }>
							{ __( 'Quality preview', 'kilobyte-image-optimizer' ) }
						</Heading>
					</HStack>

					{ preview && (
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
							'Pick any photo to compare before and after in your browser. Nothing is saved or changed on your site.',
							'kilobyte-image-optimizer'
						) }
					</Text>

					<HStack spacing={ 2 } justify="flex-start" wrap>
						<Button
							variant="secondary"
							size="compact"
							icon={ media }
							disabled={ Boolean( stage ) }
							onClick={ handleOpenMediaPicker }
						>
							{ __( 'Choose from library', 'kilobyte-image-optimizer' ) }
						</Button>

						<FormFileUpload
							accept="image/jpeg,image/png,image/webp,image/avif,image/bmp"
							onChange={ handleLocalFileChange }
							render={ ( { openFileDialog } ) => (
								<Button
									variant="tertiary"
									size="compact"
									icon={ upload }
									disabled={ Boolean( stage ) }
									onClick={ openFileDialog }
								>
									{ __( 'Pick from computer', 'kilobyte-image-optimizer' ) }
								</Button>
							) }
						/>
					</HStack>

					{ error && (
						<Surface variant="secondary">
							<VStack spacing={ 1 } style={ { padding: 12 } }>
								<Text size={ 13 } weight={ 600 } isDestructive>
									{ error.friendly }
								</Text>
								<Text size={ 12 } variant="muted">
									{ error.action }
								</Text>
								<details>
									<summary style={ { cursor: 'pointer', fontSize: 11 } }>
										{ __( 'Technical details', 'kilobyte-image-optimizer' ) }
									</summary>
									<Text size={ 11 } variant="muted">
										{ error.technical }
									</Text>
								</details>
							</VStack>
						</Surface>
					) }

					{ preview && (
						<VStack spacing={ 3 }>
							<Grid columns={ 2 } gap={ 4 }>
								<Surface variant="secondary">
									<VStack spacing={ 2 } style={ { padding: 12 } }>
										<HStack justify="space-between">
											<Text size={ 12 } weight={ 600 }>
												{ __( 'Original', 'kilobyte-image-optimizer' ) }
											</Text>
											<Text size={ 12 } variant="muted">
												{ preview.originalSize > 0
													? formatBytes( preview.originalSize )
													: '' }
											</Text>
										</HStack>
										<img
											src={ preview.originalUrl }
											alt={ preview.filename }
											style={ {
												width: '100%',
												maxHeight: 220,
												objectFit: 'contain',
												display: 'block',
											} }
										/>
									</VStack>
								</Surface>

								<Surface variant="secondary">
									<VStack spacing={ 2 } style={ { padding: 12 } }>
										<HStack justify="space-between">
											<Text size={ 12 } weight={ 600 }>
												{ preview.outputFormat
													? sprintf(
															/* translators: %s: output format */
															__( 'Optimized (%s)', 'kilobyte-image-optimizer' ),
															preview.outputFormat
													  )
													: __( 'Optimized', 'kilobyte-image-optimizer' ) }
											</Text>
											<Text size={ 12 } weight={ 600 }>
												{ stage
													? `${ elapsedSec.toFixed( 1 ) }s`
													: preview.skipped
													? __( 'Original is already small enough', 'kilobyte-image-optimizer' )
													: preview.optimizedSize !== undefined
													? `${ formatBytes( preview.optimizedSize ) } (-${ preview.savedPercent }%)`
													: '' }
											</Text>
										</HStack>

										{ stage ? (
											<VStack
												spacing={ 2 }
												justify="center"
												style={ { minHeight: 160 } }
											>
												<Text size={ 12 } variant="muted">
													{ stage.label }
												</Text>
												<ProgressBar value={ stage.percent } />
											</VStack>
										) : (
											preview.optimizedUrl && (
												<img
													src={ preview.optimizedUrl }
													alt={ preview.filename }
													style={ {
														width: '100%',
														maxHeight: 220,
														objectFit: 'contain',
														display: 'block',
													} }
												/>
											)
										) }
									</VStack>
								</Surface>
							</Grid>

							{ preview.outputFormat && ! preview.formatMatches && (
								<Text size={ 12 } variant="muted">
									{ __( 'Your browser used a different format for this preview.', 'kilobyte-image-optimizer' ) }
								</Text>
							) }

							<Text size={ 12 } variant="muted">
								{ sprintf(
									/* translators: %s: image filename */
									__( 'Previewing %s. Adjust format or compression level below to compare live.', 'kilobyte-image-optimizer' ),
									preview.filename
								) }
							</Text>
						</VStack>
					) }
				</VStack>
			</CardBody>
		</Card>
	);
}
