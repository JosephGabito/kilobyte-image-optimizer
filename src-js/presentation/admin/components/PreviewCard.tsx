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
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { check, download, media, seen, upload } from '@wordpress/icons';
import { toFriendlyError, type FriendlyError } from '../../../domain/errors';
import { formatBytes } from '../../../domain/format';
import { QUALITY_PRESETS, inferPresetFromQuality, type QualityPresetId } from '../../../domain/presets';
import type { OutputFormat, PluginSettings } from '../../../domain/types';
import { browserCompressor } from '../../../infrastructure/browserCompressor';
import { getWpMedia } from '../../../infrastructure/wpMedia';
import { wpRestClient } from '../../../infrastructure/wpRestClient';

interface PreviewCardProps {
	settings: PluginSettings;
	disabled?: boolean;
	outputMimes?: string[];
	onOptimized?: () => Promise< void > | void;
	onStartSave: () => boolean;
	onFinishSave: () => void;
	onSaveMessage: ( filename: string, message: string, status: 'success' | 'info' ) => void;
	onSaveError: ( error: unknown, filename: string ) => void;
}

interface PreviewSource {
	attachmentId?: number;
	filename: string;
	file: File;
	url?: string;
}

interface PreviewStage {
	label: string;
	percent?: number;
}

interface PreviewState {
	attachmentId?: number;
	filename: string;
	originalUrl: string;
	originalSize: number;
	optimizedUrl?: string;
	optimizedFile?: File;
	optimizedSize?: number;
	savedPercent?: number;
	outputFormat?: string;
	formatMatches?: boolean;
	skipped?: boolean;
	originalFormatKept?: boolean;
	settingsKey?: string;
	serverOnly?: boolean;
	sourceMime?: string;
	sourceHash?: string;
}

function previewSettingsKey( settings: PluginSettings ): string {
	return [ settings.format, settings.quality, settings.max_width, settings.max_height, settings.skip_smaller ].join( ':' );
}

export function PreviewCard( {
	settings,
	disabled = false,
	outputMimes = [],
	onOptimized,
	onStartSave,
	onFinishSave,
	onSaveMessage,
	onSaveError,
}: PreviewCardProps ) {
	const [ previewFormat, setPreviewFormat ] = useState< OutputFormat >( settings.format );
	const [ previewQuality, setPreviewQuality ] = useState< number >( settings.quality );
	const activeSettings: PluginSettings = {
		...settings,
		format: previewFormat,
		quality: previewQuality,
	};
	const [ stage, setStage ] = useState< PreviewStage | null >( null );
	const [ elapsedSec, setElapsedSec ] = useState( 0 );
	const [ error, setError ] = useState< FriendlyError | null >( null );
	const [ preview, setPreview ] = useState< PreviewState | null >( null );
	const [ optimizing, setOptimizing ] = useState( false );
	const [ optimizedDone, setOptimizedDone ] = useState( false );
	const [ siteRejectedPreview, setSiteRejectedPreview ] = useState( false );
	const source = useRef< PreviewSource | null >( null );
	const request = useRef< AbortController | null >( null );
	const objectUrls = useRef< string[] >( [] );
	const currentSettings = useRef( activeSettings );
	const timerRef = useRef< number | null >( null );
	currentSettings.current = activeSettings;
	const previewIsCurrent = preview?.settingsKey === previewSettingsKey( activeSettings );
	const canSavePreview = Boolean(
		preview?.optimizedFile && outputMimes.includes( preview.optimizedFile.type )
		&& ! siteRejectedPreview && ( preview.sourceMime !== 'image/gif' || preview.sourceHash )
	);

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
		setOptimizedDone( false );
		setSiteRejectedPreview( false );
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
		setPreview( {
			attachmentId: src.attachmentId,
			filename: src.filename,
			originalUrl,
			originalSize: src.file.size,
		} );
		setStage( { label: __( 'Compressing image...', 'kilobyte-image-optimizer' ) } );

		try {
			const result = await browserCompressor.compress( src.file, active, signal, true );
			if ( signal.aborted ) {
				return;
			}
			if ( result.reason === 'unsupported_mime' && src.attachmentId && outputMimes.length > 0 ) {
				setPreview( {
					attachmentId: src.attachmentId,
					filename: src.filename,
					originalUrl,
					originalSize: src.file.size,
					settingsKey: previewSettingsKey( active ),
					serverOnly: true,
				} );
				return;
			}
			if ( result.reason === 'animated' || result.reason === 'unsupported_mime' ) {
				throw new Error( result.reason === 'animated' ? 'animated_image' : 'unsupported_format' );
			}
			const savedPercent = result.originalSize > 0
				? Math.max( 0, Math.round( ( ( result.originalSize - result.optimizedSize ) / result.originalSize ) * 1000 ) / 10 )
				: 0;

			setPreview( {
				attachmentId: src.attachmentId,
				filename: src.filename,
				originalUrl,
				originalSize: result.originalSize,
				optimizedUrl: result.file ? createPreviewUrl( result.file ) : originalUrl,
				optimizedFile: result.file,
				optimizedSize: result.optimizedSize,
				savedPercent,
				outputFormat: result.format?.toUpperCase(),
				formatMatches: active.format === 'original' || result.format === active.format,
				skipped: result.skipped,
				originalFormatKept: result.reason === 'original_format_kept',
				settingsKey: previewSettingsKey( active ),
				sourceMime: src.file.type,
				sourceHash: result.sourceHash,
			} );
		} catch ( error ) {
			if ( ! signal.aborted ) {
				source.current = null;
				setPreview( null );
				setError( toFriendlyError( error ) );
			}
		} finally {
			finishPreview( signal );
		}
	};

	const loadFromUrl = async (
		url: string,
		filename: string,
		mime?: string,
		knownBytes?: number,
		attachmentId?: number
	) => {
		const signal = beginPreview();
		source.current = null;
		setPreview( { attachmentId, filename, originalUrl: url, originalSize: knownBytes ?? 0 } );
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
				source.current = { attachmentId, filename, file, url };
				await compressSource( source.current, signal );
			}
		} catch ( error ) {
			if ( ! signal.aborted ) {
				source.current = null;
				setPreview( null );
				setError( toFriendlyError( error ) );
				finishPreview( signal );
			}
		}
	};

	const handleOpenMediaPicker = () => {
		const wpMedia = getWpMedia();
		if ( ! wpMedia || optimizing ) {
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
					selected.filesizeInBytes,
					typeof selected.id === 'number' && selected.id > 0 ? selected.id : undefined
				);
			}
		} );
		frame.open();
	};

	const handleLocalFileChange = ( event: React.ChangeEvent< HTMLInputElement > ) => {
		const picked = event.currentTarget.files?.[ 0 ];
		if ( ! picked || optimizing ) {
			return;
		}
		event.currentTarget.value = '';
		source.current = { filename: picked.name, file: picked };
		compressSource( source.current, beginPreview() );
	};

	const handleOptimizeNow = async ( useSiteSettings: boolean ) => {
		if ( ! preview?.attachmentId || ! previewIsCurrent || optimizing || disabled || ! onStartSave() ) {
			return;
		}
		const id = preview.attachmentId;
		const filename = preview.filename;
		setOptimizing( true );
		setError( null );
		try {
			let response;
			if ( useSiteSettings ) {
				response = await wpRestClient.optimizeOnServer( id );
			} else if ( canSavePreview && preview.optimizedFile ) {
				const file = preview.optimizedFile;
				response = await wpRestClient.uploadOptimized( id, file, file.name, { preview: true, sourceHash: preview.sourceHash } );
			} else {
				return;
			}

			if ( response.skipped ) {
				let message: string = __( 'The original is already small enough. WordPress kept it.', 'kilobyte-image-optimizer' );
				switch ( response.status ) {
					case 'already_processed':
						message = __( 'The library already has an optimized copy. Restore the original before saving another.', 'kilobyte-image-optimizer' );
						break;
					case 'excluded':
						message = __( 'A site rule excludes this image from optimization.', 'kilobyte-image-optimizer' );
						break;
					case 'animated':
						message = __( 'WordPress kept the original to preserve its animation.', 'kilobyte-image-optimizer' );
						break;
					case 'original_format_kept':
						message = __( 'WordPress kept the GIF format. Choose WebP or AVIF to make a separate copy.', 'kilobyte-image-optimizer' );
						break;
					case 'gif_canvas_kept':
						message = __( 'WordPress kept the full GIF image. Try a browser that can make a WebP or AVIF copy.', 'kilobyte-image-optimizer' );
						break;
				}
				onSaveMessage( filename, message, 'info' );
			} else {
				onSaveMessage(
					filename,
					sprintf(
						/* translators: 1: saved image format, 2: optimized size, 3: percentage saved. */
						__( 'Saved %1$s copy to Media Library (%2$s, %3$s%% smaller).', 'kilobyte-image-optimizer' ),
						response.format.toUpperCase(),
						formatBytes( response.optimized_size ),
						String( response.saved_percent )
					),
					'success'
				);
				setOptimizedDone( true );
			}
			await onOptimized?.();
		} catch ( err ) {
			const code = ( err as { code?: string } )?.code;
			if ( ! useSiteSettings && ( code === 'kilobyte_io_invalid_image' || code === 'kilobyte_io_invalid_mime' ) ) {
				setSiteRejectedPreview( true );
				onSaveMessage(
					filename,
					__( 'WordPress could not save this preview. Choose another format or use the site settings.', 'kilobyte-image-optimizer' ),
					'info'
				);
			} else {
				if ( code === 'kilobyte_io_source_changed' ) {
					setSiteRejectedPreview( true );
				}
				onSaveError( err, filename );
			}
		} finally {
			setOptimizing( false );
			onFinishSave();
		}
	};

	const handleClear = () => {
		if ( optimizing ) {
			return;
		}
		cancelPreview();
		source.current = null;
		setStage( null );
		setError( null );
		setOptimizedDone( false );
		setSiteRejectedPreview( false );
		setPreview( null );
	};

	useEffect( () => {
		setPreviewFormat( settings.format );
		setPreviewQuality( settings.quality );
	}, [ settings.format, settings.quality ] );

	useEffect( () => {
		if ( source.current ) {
			compressSource( source.current, beginPreview() );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ previewFormat, previewQuality, settings.max_width, settings.max_height, settings.skip_smaller ] );

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

					{ ( preview || error ) && (
						<Button
							variant="tertiary"
							size="compact"
							disabled={ optimizing }
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
							'Pick a photo to compare before and after in your browser. You can then choose to save a copy.',
							'kilobyte-image-optimizer'
						) }
					</Text>

					<HStack spacing={ 2 } justify="flex-start" wrap>
						<Button
							variant="secondary"
							size="compact"
							icon={ media }
							disabled={ Boolean( stage ) || optimizing }
							onClick={ handleOpenMediaPicker }
						>
							{ __( 'Choose from library', 'kilobyte-image-optimizer' ) }
						</Button>

						<FormFileUpload
							accept="image/jpeg,image/png,image/webp,image/avif,image/bmp,image/gif"
							onChange={ handleLocalFileChange }
							render={ ( { openFileDialog } ) => (
								<Button
									variant="tertiary"
									size="compact"
									icon={ upload }
									disabled={ Boolean( stage ) || optimizing }
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
								<ToggleGroupControl
									label={ __( 'Format', 'kilobyte-image-optimizer' ) }
									value={ previewFormat }
									disabled={ optimizing }
									onChange={ ( value ) => setPreviewFormat( String( value ) as OutputFormat ) }
									isBlock
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								>
									<ToggleGroupControlOption
										value="webp"
										label={ __( 'WebP', 'kilobyte-image-optimizer' ) }
									/>
									<ToggleGroupControlOption
										value="avif"
										label={ __( 'AVIF', 'kilobyte-image-optimizer' ) }
									/>
									<ToggleGroupControlOption
										value="original"
										label={ __( 'Original', 'kilobyte-image-optimizer' ) }
									/>
								</ToggleGroupControl>

								<ToggleGroupControl
									label={ __( 'Compression', 'kilobyte-image-optimizer' ) }
									value={ inferPresetFromQuality( previewQuality ) }
									disabled={ optimizing }
									onChange={ ( value ) => {
										const preset = QUALITY_PRESETS[ String( value ) as QualityPresetId ];
										if ( preset ) {
											setPreviewQuality( preset.quality );
										}
									} }
									isBlock
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								>
									<ToggleGroupControlOption
										value="high"
										label={ __( 'High', 'kilobyte-image-optimizer' ) }
									/>
									<ToggleGroupControlOption
										value="balanced"
										label={ __( 'Balanced', 'kilobyte-image-optimizer' ) }
									/>
									<ToggleGroupControlOption
										value="compact"
										label={ __( 'Smallest', 'kilobyte-image-optimizer' ) }
									/>
								</ToggleGroupControl>
							</Grid>

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
													: preview.originalFormatKept
													? __( 'Original GIF kept', 'kilobyte-image-optimizer' )
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

							{ preview.originalFormatKept && (
								<Text size={ 12 } variant="muted">
									{ __( 'Choose WebP or AVIF to preview a separate copy of this GIF.', 'kilobyte-image-optimizer' ) }
								</Text>
							) }
							{ preview.attachmentId && ( preview.optimizedFile || preview.serverOnly ) && previewIsCurrent && ! canSavePreview && ! optimizedDone && (
								<Text size={ 12 } variant="muted">
									{ preview.serverOnly
										? __( 'Your browser could not make a preview. You can optimize using your site settings.', 'kilobyte-image-optimizer' )
										: __( 'WordPress cannot save this preview. Choose another format, download the copy, or optimize with your site settings. The site may use a different format or quality.', 'kilobyte-image-optimizer' ) }
								</Text>
							) }

							<HStack justify="space-between" alignment="center" wrap>
								<Text size={ 12 } variant="muted">
									{ sprintf(
										/* translators: %s: image filename */
										__( 'Previewing %s', 'kilobyte-image-optimizer' ),
										preview.filename
									) }
								</Text>

								{ ! stage && previewIsCurrent && ( preview.optimizedFile || preview.serverOnly ) && (
									<>
										{ preview.attachmentId && (
											<Button
												variant="secondary"
												size="compact"
												icon={ check }
												isBusy={ optimizing }
												disabled={ optimizing || optimizedDone || disabled }
												onClick={ () => handleOptimizeNow( ! canSavePreview ) }
											>
												{ optimizing
													? __( 'Optimizing...', 'kilobyte-image-optimizer' )
													: optimizedDone
													? __( 'Optimized', 'kilobyte-image-optimizer' )
													: canSavePreview
													? __( 'Optimize now', 'kilobyte-image-optimizer' )
													: __( 'Optimize with site settings', 'kilobyte-image-optimizer' ) }
											</Button>
										) }
										{ preview.optimizedUrl && preview.optimizedFile && ( ! preview.attachmentId || ! canSavePreview ) && (
											<Button
												variant="secondary"
												size="compact"
												icon={ download }
												href={ preview.optimizedUrl }
												download={ preview.optimizedFile.name || preview.filename }
											>
												{ __( 'Download copy', 'kilobyte-image-optimizer' ) }
											</Button>
										) }
									</>
								) }
							</HStack>
						</VStack>
					) }
				</VStack>
			</CardBody>
		</Card>
	);
}
