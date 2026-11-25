import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	DropZone,
	FormFileUpload,
	Notice,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { download, update, upload } from '@wordpress/icons';

type OutputMime = 'image/webp' | 'image/avif' | 'image/jpeg';
type PresetId = 'high' | 'balanced' | 'compact';

interface DownloadState {
	url: string;
	filename: string;
}

interface Saving {
	percent: number;
	bytes: number;
}

// The same three compression levels and the same size limit the plugin uses by default.
const PRESET_QUALITY: Record< PresetId, number > = {
	high: 90,
	balanced: 82,
	compact: 72,
};
const MAX_DIMENSION = 2560;

const SAMPLE_URL = 'assets/sample-photo.jpg';
const SAMPLE_STATUS =
	'This demo runs in your browser. The sample photo is a real JPEG file, and nothing is uploaded.';

const NBSP = ' ';

function formatBytes( bytes: number ): string {
	if ( ! bytes || bytes <= 0 ) {
		return `0${ NBSP }B`;
	}
	if ( bytes < 1024 ) {
		return `${ bytes }${ NBSP }B`;
	}
	if ( bytes < 1024 * 1024 ) {
		const kb = bytes / 1024;
		return `${ kb >= 100 ? kb.toFixed( 0 ) : kb.toFixed( 1 ) }${ NBSP }KB`;
	}
	return `${ ( bytes / ( 1024 * 1024 ) ).toFixed( 2 ) }${ NBSP }MB`;
}

function labelForMime( mime: string ): string {
	switch ( mime ) {
		case 'image/avif':
			return 'AVIF';
		case 'image/webp':
			return 'WebP';
		case 'image/jpeg':
			return 'JPEG';
		case 'image/png':
			return 'PNG';
		case 'image/gif':
			return 'GIF';
		case 'image/bmp':
			return 'BMP';
		default:
			return 'image';
	}
}

function extForMime( mime: string ): string {
	if ( mime === 'image/avif' ) {
		return 'avif';
	}
	if ( mime === 'image/jpeg' ) {
		return 'jpg';
	}
	return 'webp';
}

function supportsMime( mime: OutputMime ): Promise< boolean > {
	return new Promise( ( resolve ) => {
		const probe = document.createElement( 'canvas' );
		probe.width = 2;
		probe.height = 2;
		probe.toBlob(
			( blob ) => resolve( Boolean( blob && blob.type === mime ) ),
			mime,
			0.8
		);
	} );
}

export function CompressionLab() {
	const [ activeFormat, setActiveFormat ] = useState< OutputMime >( 'image/webp' );
	const [ preset, setPreset ] = useState< PresetId >( 'balanced' );
	const [ splitPct, setSplitPct ] = useState< number >( 50 );
	const [ support, setSupport ] = useState< { webp: boolean; avif: boolean } | null >( null );

	const [ beforeUrl, setBeforeUrl ] = useState< string | null >( null );
	const [ afterUrl, setAfterUrl ] = useState< string | null >( null );
	const [ ratio, setRatio ] = useState< number >( 2 );
	const [ origBytes, setOrigBytes ] = useState< number | null >( null );
	const [ origMime, setOrigMime ] = useState< string >( 'image/jpeg' );
	const [ optBytes, setOptBytes ] = useState< number | null >( null );
	const [ saving, setSaving ] = useState< Saving | 'none' | null >( null );
	const [ emptyText, setEmptyText ] = useState< string >( 'Loading the sample photo.' );
	const [ downloadState, setDownloadState ] = useState< DownloadState | null >( null );
	const [ statusText, setStatusText ] = useState< string >( SAMPLE_STATUS );
	const [ errorNotice, setErrorNotice ] = useState< {
		status: 'error' | 'warning';
		message: string;
	} | null >( null );

	const wrapRef = useRef< HTMLDivElement | null >( null );
	const sourceBitmapRef = useRef< ImageBitmap | null >( null );
	const sourceBytesRef = useRef< number >( 0 );
	const sourceNameRef = useRef< string >( 'sample-photo' );
	const beforeUrlRef = useRef< string | null >( null );
	const afterUrlRef = useRef< string | null >( null );
	const loadSeqRef = useRef< number >( 0 );
	const encodeSeqRef = useRef< number >( 0 );
	const formatRef = useRef< OutputMime >( activeFormat );
	const qualityRef = useRef< number >( PRESET_QUALITY[ preset ] );

	formatRef.current = activeFormat;
	qualityRef.current = PRESET_QUALITY[ preset ];

	const revokeAfter = useCallback( () => {
		if ( afterUrlRef.current ) {
			URL.revokeObjectURL( afterUrlRef.current );
			afterUrlRef.current = null;
		}
		setAfterUrl( null );
	}, [] );

	const clearAllPreviews = useCallback( () => {
		if ( sourceBitmapRef.current && typeof sourceBitmapRef.current.close === 'function' ) {
			sourceBitmapRef.current.close();
		}
		sourceBitmapRef.current = null;
		sourceBytesRef.current = 0;

		if ( beforeUrlRef.current ) {
			URL.revokeObjectURL( beforeUrlRef.current );
			beforeUrlRef.current = null;
		}
		setBeforeUrl( null );
		revokeAfter();
		setDownloadState( null );
		setOrigBytes( null );
		setOptBytes( null );
		setSaving( null );
	}, [ revokeAfter ] );

	const runEncode = useCallback(
		( requestedFormat: OutputMime, requestedQuality: number ) => {
			const bitmap = sourceBitmapRef.current;
			if ( ! bitmap ) {
				setDownloadState( null );
				return;
			}

			const myEncode = ++encodeSeqRef.current;
			const requestedName = sourceNameRef.current;
			const requestedSourceBytes = sourceBytesRef.current;
			const label = labelForMime( requestedFormat );

			setDownloadState( null );
			setOptBytes( null );
			setSaving( null );
			setErrorNotice( null );

			const canvas = document.createElement( 'canvas' );
			const scale = Math.min( 1, MAX_DIMENSION / Math.max( bitmap.width, bitmap.height ) );
			canvas.width = Math.max( 1, Math.round( bitmap.width * scale ) );
			canvas.height = Math.max( 1, Math.round( bitmap.height * scale ) );

			const ctx = canvas.getContext( '2d' );
			if ( ! ctx ) {
				setErrorNotice( {
					status: 'error',
					message: 'Your browser could not start its image encoder. Reload the page and try again.',
				} );
				return;
			}
			ctx.drawImage( bitmap, 0, 0, canvas.width, canvas.height );

			canvas.toBlob(
				( blob ) => {
					if ( myEncode !== encodeSeqRef.current ) {
						return;
					}

					if ( ! blob || blob.type !== requestedFormat ) {
						revokeAfter();
						setErrorNotice( {
							status: 'warning',
							message: `Your browser could not make a ${ label } copy of this photo. Choose another format to keep comparing.`,
						} );
						return;
					}

					if ( afterUrlRef.current ) {
						URL.revokeObjectURL( afterUrlRef.current );
					}
					const nextAfterUrl = URL.createObjectURL( blob );
					afterUrlRef.current = nextAfterUrl;
					setAfterUrl( nextAfterUrl );
					setOptBytes( blob.size );
					setDownloadState( {
						url: nextAfterUrl,
						filename: `${ requestedName }.${ extForMime( blob.type ) }`,
					} );

					const diff = requestedSourceBytes - blob.size;
					if ( diff > 0 && requestedSourceBytes > 0 ) {
						setSaving( {
							percent: ( diff / requestedSourceBytes ) * 100,
							bytes: diff,
						} );
					} else {
						setSaving( 'none' );
					}
				},
				requestedFormat,
				requestedQuality / 100
			);
		},
		[ revokeAfter ]
	);

	const loadBlob = useCallback(
		async ( blob: Blob, name: string, successStatus: string, myLoad: number ) => {
			++encodeSeqRef.current;
			setDownloadState( null );
			setErrorNotice( null );
			setOptBytes( null );
			setSaving( null );

			let decodedBitmap: ImageBitmap;
			try {
				decodedBitmap = await createImageBitmap( blob );
			} catch {
				if ( myLoad !== loadSeqRef.current ) {
					return;
				}
				clearAllPreviews();
				setEmptyText( 'Choose a JPEG, PNG, WebP, GIF, or BMP photo to compare.' );
				setErrorNotice( {
					status: 'error',
					message: `Your browser could not open ${ name }. Choose a JPEG, PNG, WebP, GIF, or BMP photo.`,
				} );
				return;
			}

			if ( myLoad !== loadSeqRef.current ) {
				if ( typeof decodedBitmap.close === 'function' ) {
					decodedBitmap.close();
				}
				return;
			}

			if ( sourceBitmapRef.current && typeof sourceBitmapRef.current.close === 'function' ) {
				sourceBitmapRef.current.close();
			}
			if ( beforeUrlRef.current ) {
				URL.revokeObjectURL( beforeUrlRef.current );
			}

			const nextBeforeUrl = URL.createObjectURL( blob );
			sourceBitmapRef.current = decodedBitmap;
			sourceBytesRef.current = blob.size;
			sourceNameRef.current = name.replace( /\.[^.]+$/, '' ) || 'photo';
			beforeUrlRef.current = nextBeforeUrl;

			setBeforeUrl( nextBeforeUrl );
			setRatio( decodedBitmap.width / Math.max( 1, decodedBitmap.height ) );
			setOrigBytes( blob.size );
			setOrigMime( blob.type );
			setStatusText( successStatus );
			runEncode( formatRef.current, qualityRef.current );
		},
		[ clearAllPreviews, runEncode ]
	);

	const handleUserFile = useCallback(
		( file: File | undefined ) => {
			if ( ! file ) {
				return;
			}
			const myLoad = ++loadSeqRef.current;

			if ( file.type && ! file.type.startsWith( 'image/' ) ) {
				++encodeSeqRef.current;
				clearAllPreviews();
				setEmptyText( 'Choose a JPEG, PNG, WebP, GIF, or BMP photo to compare.' );
				setErrorNotice( {
					status: 'error',
					message: `${ file.name } is not an image. Choose a JPEG, PNG, WebP, GIF, or BMP photo.`,
				} );
				return;
			}

			void loadBlob(
				file,
				file.name,
				`Showing ${ file.name }. It stays on your device, and nothing is uploaded.`,
				myLoad
			);
		},
		[ clearAllPreviews, loadBlob ]
	);

	const loadSample = useCallback( async () => {
		const myLoad = ++loadSeqRef.current;
		setErrorNotice( null );

		try {
			const res = await fetch( SAMPLE_URL );
			if ( ! res.ok ) {
				throw new Error( 'sample fetch failed' );
			}
			// The sample is compared exactly as it is stored, so the saving shown is the real one.
			const sampleBlob = await res.blob();
			if ( myLoad !== loadSeqRef.current ) {
				return;
			}
			void loadBlob( sampleBlob, 'sample-photo.jpg', SAMPLE_STATUS, myLoad );
		} catch {
			if ( myLoad !== loadSeqRef.current ) {
				return;
			}
			clearAllPreviews();
			setEmptyText( 'The sample photo did not load. Choose your own photo to compare.' );
		}
	}, [ clearAllPreviews, loadBlob ] );

	useEffect( () => {
		let mounted = true;
		void Promise.all( [
			supportsMime( 'image/webp' ),
			supportsMime( 'image/avif' ),
		] ).then( ( [ webp, avif ] ) => {
			if ( ! mounted ) {
				return;
			}
			setSupport( { webp, avif } );
			let next: OutputMime = 'image/jpeg';
			if ( webp ) {
				next = 'image/webp';
			} else if ( avif ) {
				next = 'image/avif';
			}
			if ( next !== formatRef.current ) {
				formatRef.current = next;
				setActiveFormat( next );
				if ( sourceBitmapRef.current ) {
					runEncode( next, qualityRef.current );
				}
			}
		} );

		// The sample photo loads only when the demo is about to scroll into view.
		const el = wrapRef.current;
		let observer: IntersectionObserver | null = null;
		if ( el && 'IntersectionObserver' in window ) {
			observer = new IntersectionObserver(
				( entries ) => {
					if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
						observer?.disconnect();
						void loadSample();
					}
				},
				{ rootMargin: '800px 0px' }
			);
			observer.observe( el );
		} else {
			void loadSample();
		}

		return () => {
			mounted = false;
			observer?.disconnect();
			clearAllPreviews();
		};
	}, [ clearAllPreviews, loadSample, runEncode ] );

	const handleFormatChange = ( value: string | number | undefined ) => {
		const next = String( value ) as OutputMime;
		setActiveFormat( next );
		formatRef.current = next;
		runEncode( next, qualityRef.current );
	};

	const handlePresetChange = ( value: string | number | undefined ) => {
		const next = String( value ) as PresetId;
		if ( ! ( next in PRESET_QUALITY ) ) {
			return;
		}
		setPreset( next );
		qualityRef.current = PRESET_QUALITY[ next ];
		runEncode( formatRef.current, PRESET_QUALITY[ next ] );
	};

	const formatLabel = labelForMime( activeFormat );
	const bothFormats = Boolean( support?.webp && support?.avif );

	let formatNote = 'The smaller copy is made in your browser. Your photo never leaves your device.';
	if ( support && ! bothFormats ) {
		if ( support.webp ) {
			formatNote =
				'Your browser makes WebP copies, so this demo shows WebP. On your site, Kilobyte can also make AVIF copies with your WordPress image editor when your host supports AVIF.';
		} else if ( support.avif ) {
			formatNote =
				'Your browser makes AVIF copies, so this demo shows AVIF. On your site, Kilobyte can also make WebP copies with your WordPress image editor.';
		} else {
			formatNote =
				'Your browser cannot make WebP or AVIF copies, so this demo shows a smaller JPEG. On your site, Kilobyte makes WebP or AVIF copies with your WordPress image editor.';
		}
	}

	let savingValue = '–';
	let savingNote = 'Compared with the original file';
	if ( saving === 'none' ) {
		savingValue = 'No saving';
		savingNote = 'The original is already smaller, so Kilobyte would keep it.';
	} else if ( saving ) {
		savingValue = `${ saving.percent.toFixed( 1 ) }% smaller`;
		savingNote = `${ formatBytes( saving.bytes ) } less to download`;
	}

	return (
		<div ref={ wrapRef }>
			<Card className="kb-lab-card">
				<DropZone
					label="Drop a photo here to compare it"
					onFilesDrop={ ( files ) => handleUserFile( files[ 0 ] ) }
				/>

				<CardHeader>
					<h3 className="kb-h3">Quality preview</h3>

					<div className="kb-lab-buttons">
						<FormFileUpload
							accept="image/jpeg,image/png,image/webp,image/gif,image/bmp"
							onChange={ ( event ) => {
								const file = event.currentTarget.files?.[ 0 ];
								event.currentTarget.value = '';
								handleUserFile( file );
							} }
							render={ ( { openFileDialog } ) => (
								<Button
									variant="secondary"
									icon={ upload }
									onClick={ openFileDialog }
									__next40pxDefaultSize
								>
									Choose your own photo
								</Button>
							) }
						/>
						<Button
							variant="tertiary"
							icon={ update }
							onClick={ () => void loadSample() }
							__next40pxDefaultSize
						>
							Use the sample photo
						</Button>
					</div>
				</CardHeader>

				<CardBody>
					<div className="kb-lab-controls">
						<ToggleGroupControl
							label="Compression"
							value={ preset }
							onChange={ handlePresetChange }
							isBlock
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						>
							<ToggleGroupControlOption value="high" label="High" />
							<ToggleGroupControlOption value="balanced" label="Balanced" />
							<ToggleGroupControlOption value="compact" label="Smallest" />
						</ToggleGroupControl>

						{ bothFormats && (
							<ToggleGroupControl
								label="Format"
								value={ activeFormat }
								onChange={ handleFormatChange }
								isBlock
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							>
								<ToggleGroupControlOption value="image/webp" label="WebP" />
								<ToggleGroupControlOption value="image/avif" label="AVIF" />
							</ToggleGroupControl>
						) }

						<p className={ `kb-small kb-lab-note${ bothFormats ? '' : ' is-wide' }` }>
							{ formatNote }
						</p>
					</div>

					{ errorNotice && (
						<Notice
							status={ errorNotice.status }
							onRemove={ () => setErrorNotice( null ) }
						>
							{ errorNotice.message }
						</Notice>
					) }

					<dl className="kb-lab-stats" aria-live="polite">
						<div className="kb-lab-stat">
							<dt>Original file ({ labelForMime( origMime ) })</dt>
							<dd className="kb-lab-stat-value">
								{ origBytes !== null ? formatBytes( origBytes ) : '–' }
							</dd>
							<dd className="kb-lab-stat-note">Kept exactly as it is</dd>
						</div>

						<div className="kb-lab-stat">
							<dt>Smaller copy ({ formatLabel })</dt>
							<dd className="kb-lab-stat-value">
								{ optBytes !== null ? formatBytes( optBytes ) : '–' }
							</dd>
							<dd className="kb-lab-stat-note">Served to your visitors</dd>
						</div>

						<div className={ `kb-lab-stat${ saving && saving !== 'none' ? ' is-good' : '' }` }>
							<dt>Saving</dt>
							<dd className="kb-lab-stat-value">{ savingValue }</dd>
							<dd className="kb-lab-stat-note">{ savingNote }</dd>
						</div>
					</dl>

					<div
						className="kb-compare-stage"
						style={ {
							aspectRatio: String( ratio ),
							maxWidth: `${ Math.round( 560 * ratio ) }px`,
						} }
					>
						{ beforeUrl ? (
							<img
								src={ beforeUrl }
								alt="The original photo"
								className="kb-compare-img"
							/>
						) : (
							<div className="kb-compare-empty">{ emptyText }</div>
						) }

						{ afterUrl && (
							<div
								className="kb-compare-after"
								style={ { clipPath: `inset(0 0 0 ${ splitPct }%)` } }
							>
								<img
									src={ afterUrl }
									alt={ `The smaller ${ formatLabel } copy` }
									className="kb-compare-img"
								/>
							</div>
						) }

						{ beforeUrl && afterUrl && (
							<>
								<span className="kb-compare-tag kb-compare-tag-left">Original</span>
								<span className="kb-compare-tag kb-compare-tag-right">
									Smaller copy
								</span>

								<input
									type="range"
									min={ 0 }
									max={ 100 }
									value={ splitPct }
									onChange={ ( event ) => setSplitPct( Number( event.target.value ) ) }
									className="kb-compare-range"
									aria-label="Drag to compare the original photo with the smaller copy"
								/>

								<div
									className="kb-compare-divider"
									style={ { left: `${ splitPct }%` } }
									aria-hidden="true"
								>
									<span className="kb-compare-handle">&harr;</span>
								</div>
							</>
						) }
					</div>
				</CardBody>

				<CardFooter>
					<p className="kb-small">{ statusText }</p>

					{ downloadState ? (
						<Button
							variant="secondary"
							icon={ download }
							href={ downloadState.url }
							download={ downloadState.filename }
							__next40pxDefaultSize
						>
							Download the smaller copy
						</Button>
					) : (
						<Button
							variant="secondary"
							icon={ download }
							disabled
							__next40pxDefaultSize
						>
							Download the smaller copy
						</Button>
					) }
				</CardFooter>
			</Card>
		</div>
	);
}
