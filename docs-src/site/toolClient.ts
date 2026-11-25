import './navClient';
import { computeTargetDimensions } from '../../src-js/core/dimensions';
import { formatBytes, labelForMime, resolveInputMime, sanitizeFilename } from '../../src-js/core/format';
import { readRasterDimensions } from '../../src-js/core/rasterHeader';
import type { CoreResizeOptions, EncodableOutputMime, PngOptimizationMode } from '../../src-js/core/types';
import { createZipBlob, MAX_ZIP_BYTES } from '../../src-js/core/zip';
import {
	canUseToolWorker,
	disposeWorkerPool,
	friendlyErrorMessage,
	processImageInBrowser,
} from '../engine/clientEngine';
import type { ToolProcessResult, ToolProcessStage } from '../engine/types';

const TRANSPARENT_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
const MAX_RESIZE_INPUT = 12_000;
const MAX_DROPPED_FOLDER_FILES = 200;
const MAX_BATCH_LANES = 3;
const MAX_INFLIGHT_PIXELS = 45_000_000;

interface EmbeddedToolConfig {
	slug: string;
	accept: string;
	defaultOutputMime: EncodableOutputMime;
	allowedOutputMimes: EncodableOutputMime[];
	defaultQuality: number;
	defaultPngMode: PngOptimizationMode;
	showResize: boolean;
	batchFocused: boolean;
	sampleUrl: string | null;
	autoLoadSample?: boolean;
	workerUrl?: string;
}

interface BatchItemState {
	id: number;
	file: File;
	status: 'queued' | 'processing' | 'done' | 'error' | 'cancelled';
	stage?: ToolProcessStage;
	sourceDims?: [ number, number ] | null;
	removed?: boolean;
	result?: ToolProcessResult;
	downloadUrl?: string;
	thumbUrl?: string;
	error?: string;
}

interface FileSystemEntryLike {
	isFile: boolean;
	isDirectory: boolean;
	file?: ( cb: ( file: File ) => void, err?: ( err: unknown ) => void ) => void;
	createReader?: () => {
		readEntries: ( cb: ( entries: FileSystemEntryLike[] ) => void, err?: ( err: unknown ) => void ) => void;
	};
}

const BOOTSTRAP_SCRIPT_SRC = ( document.currentScript as HTMLScriptElement | null )?.src || '';

function resolveRuntimeUrls( config: EmbeddedToolConfig ): { workerUrl: string; codecBaseUrl: string } {
	const fallbackScript =
		BOOTSTRAP_SCRIPT_SRC ||
		document.querySelector< HTMLScriptElement >( 'script[src*="/dist/tool"], script[src*="/dist/home"]' )?.src ||
		new URL( '/dist/tool.js', window.location.href ).href;
	const workerUrl = config.workerUrl
		? new URL( config.workerUrl, window.location.origin ).href
		: new URL( './tool-worker.js', fallbackScript ).href;
	const codecBaseUrl = new URL( '../codecs', workerUrl ).href;
	return { workerUrl, codecBaseUrl };
}

function encoderBadge( encoder: ToolProcessResult[ 'encoder' ] ): string {
	switch ( encoder ) {
		case 'upng+oxipng':
			return 'Palette + OxiPNG';
		case 'upng':
			return 'Palette PNG';
		case 'oxipng':
			return 'OxiPNG lossless';
		case 'wasm-webp':
			return 'WebP WASM';
		case 'wasm-avif':
			return 'AVIF WASM';
		default:
			return 'Browser native';
	}
}

function stageLabel( stage: ToolProcessStage, mime: EncodableOutputMime, viaWorker: boolean ): string {
	const fmt = labelForMime( mime );
	const scope = viaWorker ? 'locally' : 'on main thread';
	switch ( stage ) {
		case 'validating':
			return 'Checking image headers...';
		case 'decoding':
			return 'Decoding pixels...';
		case 'loading-codec':
			return `Loading ${ fmt } WebAssembly encoder...`;
		case 'verifying':
			return `Verifying ${ fmt } signature...`;
		case 'encoding':
		default:
			return `Encoding ${ fmt } ${ scope }...`;
	}
}

async function createBoundedThumbnailUrl( blob: Blob ): Promise< string | undefined > {
	if ( typeof createImageBitmap !== 'function' ) {
		return undefined;
	}
	try {
		const bitmap = await createImageBitmap( blob );
		const maxSide = 88;
		const scale = Math.min( 1, maxSide / Math.max( 1, bitmap.width, bitmap.height ) );
		const w = Math.max( 1, Math.round( bitmap.width * scale ) );
		const h = Math.max( 1, Math.round( bitmap.height * scale ) );
		const canvas = document.createElement( 'canvas' );
		canvas.width = w;
		canvas.height = h;
		try {
			const ctx = canvas.getContext( '2d' );
			if ( ! ctx ) {
				return undefined;
			}
			ctx.drawImage( bitmap, 0, 0, w, h );
			const thumbBlob = await new Promise< Blob | null >( ( resolve ) =>
				canvas.toBlob( resolve, 'image/webp', 0.72 )
			) || await new Promise< Blob | null >( ( resolve ) =>
				canvas.toBlob( resolve, 'image/jpeg', 0.72 )
			);
			return thumbBlob ? URL.createObjectURL( thumbBlob ) : undefined;
		} finally {
			bitmap.close();
			canvas.width = 0;
			canvas.height = 0;
		}
	} catch {
		return undefined;
	}
}

async function readDroppedFiles(
	dataTransfer: DataTransfer | null
): Promise< { files: File[]; skippedCount: number } > {
	if ( ! dataTransfer ) {
		return { files: [], skippedCount: 0 };
	}
	const items = dataTransfer.items ? Array.from( dataTransfer.items ) : [];
	const entries = items
		.map(
			( item ) =>
				( item as DataTransferItem & { webkitGetAsEntry?: () => FileSystemEntryLike | null } ).webkitGetAsEntry?.() ??
				null
		)
		.filter( Boolean ) as FileSystemEntryLike[];

	if ( entries.length === 0 ) {
		return { files: Array.from( dataTransfer.files || [] ), skippedCount: 0 };
	}

	const collected: File[] = [];
	let skippedCount = 0;

	const walk = async ( entry: FileSystemEntryLike ): Promise< void > => {
		if ( entry.isFile && entry.file ) {
			if ( collected.length >= MAX_DROPPED_FOLDER_FILES ) {
				skippedCount += 1;
				return;
			}
			await new Promise< void >( ( resolve ) => {
				entry.file!(
					( file ) => {
						if ( collected.length < MAX_DROPPED_FOLDER_FILES ) {
							collected.push( file );
						} else {
							skippedCount += 1;
						}
						resolve();
					},
					() => resolve()
				);
			} );
			return;
		}
		if ( entry.isDirectory && entry.createReader ) {
			const reader = entry.createReader();
			while ( true ) {
				const batch = await new Promise< FileSystemEntryLike[] >( ( resolve ) => {
					reader.readEntries(
						( list ) => resolve( list ),
						() => resolve( [] )
					);
				} );
				if ( batch.length === 0 ) {
					break;
				}
				for ( const child of batch ) {
					await walk( child );
				}
			}
		}
	};

	for ( const entry of entries ) {
		await walk( entry );
	}
	if ( collected.length === 0 && skippedCount === 0 ) {
		return { files: Array.from( dataTransfer.files || [] ), skippedCount: 0 };
	}
	return { files: collected, skippedCount };
}

export function initToolWidget( root: HTMLElement ): void {
	const configEl = root.querySelector< HTMLScriptElement >( '[data-role="tool-config"]' );
	if ( ! configEl?.textContent ) {
		return;
	}
	const config = JSON.parse( configEl.textContent ) as EmbeddedToolConfig;
	const { workerUrl, codecBaseUrl } = resolveRuntimeUrls( config );

	const pickBtn = root.querySelector< HTMLButtonElement >( '[data-role="pick-btn"]' );
	const fileInput = root.querySelector< HTMLInputElement >( '[data-role="file-input"]' );
	const sampleBtn = root.querySelector< HTMLButtonElement >( '[data-role="sample-btn"]' );
	const cancelBtn = root.querySelector< HTMLButtonElement >( '[data-role="cancel-btn"]' );
	const clearBtn = root.querySelector< HTMLButtonElement >( '[data-role="clear-btn"]' );
	const dropzone = root.querySelector< HTMLElement >( '[data-role="dropzone"]' );

	const formatBtns = Array.from( root.querySelectorAll< HTMLButtonElement >( '[data-role="format-btn"]' ) );
	const presetBtns = Array.from( root.querySelectorAll< HTMLButtonElement >( '[data-role="preset-btn"]' ) );
	const presetGroup = root.querySelector< HTMLElement >( '[data-role="preset-group"]' );
	const qualityGroup = root.querySelector< HTMLElement >( '[data-role="quality-group"]' );
	const qualityInput = root.querySelector< HTMLInputElement >( '[data-role="quality-input"]' );
	const qualityVal = root.querySelector< HTMLElement >( '[data-role="quality-val"]' );
	const pngModeGroup = root.querySelector< HTMLElement >( '[data-role="png-mode-group"]' );
	const pngModeBtns = Array.from( root.querySelectorAll< HTMLButtonElement >( '[data-role="png-mode-btn"]' ) );

	const resizeWidthInput = root.querySelector< HTMLInputElement >( '[data-role="resize-width"]' );
	const resizeHeightInput = root.querySelector< HTMLInputElement >( '[data-role="resize-height"]' );
	const resizeAspectInput = root.querySelector< HTMLInputElement >( '[data-role="resize-aspect"]' );
	const resizeNoUpscaleInput = root.querySelector< HTMLInputElement >( '[data-role="resize-no-upscale"]' );
	const sizePresetBtns = Array.from( root.querySelectorAll< HTMLButtonElement >( '[data-role="size-preset"]' ) );

	const noticeEl = root.querySelector< HTMLElement >( '[data-role="notice"]' );
	const singleView = root.querySelector< HTMLElement >( '[data-role="single-view"]' );
	const batchView = root.querySelector< HTMLElement >( '[data-role="batch-view"]' );

	const origLabelEl = root.querySelector< HTMLElement >( '[data-role="orig-label"]' );
	const origSizeEl = root.querySelector< HTMLElement >( '[data-role="orig-size"]' );
	const origDimsEl = root.querySelector< HTMLElement >( '[data-role="orig-dims"]' );
	const optLabelEl = root.querySelector< HTMLElement >( '[data-role="opt-label"]' );
	const optSizeEl = root.querySelector< HTMLElement >( '[data-role="opt-size"]' );
	const optMetaEl = root.querySelector< HTMLElement >( '[data-role="opt-meta"]' );
	const savingsStatEl = root.querySelector< HTMLElement >( '[data-role="savings-stat"]' );
	const savingsValEl = root.querySelector< HTMLElement >( '[data-role="savings-val"]' );
	const savingsNoteEl = root.querySelector< HTMLElement >( '[data-role="savings-note"]' );

	const beforeImg = root.querySelector< HTMLImageElement >( '[data-role="before-img"]' );
	const afterImg = root.querySelector< HTMLImageElement >( '[data-role="after-img"]' );
	const afterWrap = root.querySelector< HTMLElement >( '[data-role="after-wrap"]' );
	const leftTag = root.querySelector< HTMLElement >( '[data-role="left-tag"]' );
	const rightTag = root.querySelector< HTMLElement >( '[data-role="right-tag"]' );
	const splitRange = root.querySelector< HTMLInputElement >( '[data-role="split-range"]' );
	const splitDivider = root.querySelector< HTMLElement >( '[data-role="split-divider"]' );
	const emptyStage = root.querySelector< HTMLElement >( '[data-role="empty-stage"]' );

	const batchHeadline = root.querySelector< HTMLElement >( '[data-role="batch-headline"]' );
	const batchSubline = root.querySelector< HTMLElement >( '[data-role="batch-subline"]' );
	const batchList = root.querySelector< HTMLUListElement >( '[data-role="batch-list"]' );
	const zipBtn = root.querySelector< HTMLButtonElement >( '[data-role="zip-btn"]' );

	const statusLine = root.querySelector< HTMLElement >( '[data-role="status-line"]' );
	const downloadPlaceholder = root.querySelector< HTMLButtonElement >( '[data-role="download-placeholder"]' );
	const downloadPlaceholderLabel = root.querySelector< HTMLElement >( '[data-role="download-placeholder-label"]' );
	const downloadBtn = root.querySelector< HTMLAnchorElement >( '[data-role="download-btn"]' );
	const downloadLabelEl = root.querySelector< HTMLElement >( '[data-role="download-label"]' );
	const wpCallout = root.parentElement?.querySelector< HTMLElement >( '[data-role="wp-callout"]' ) ?? null;

	let activeMime: EncodableOutputMime = config.defaultOutputMime;
	let activeQuality: number = config.defaultQuality;
	let activePngMode: PngOptimizationMode = config.defaultPngMode;
	let singleFile: File | null = null;
	let singleSourceDims: { width: number; height: number } | null = null;
	let beforeUrl: string | null = null;
	let afterUrl: string | null = null;
	let lastZipUrl: string | null = null;
	let batchItems: BatchItemState[] = [];
	const batchRowEls = new Map< number, HTMLLIElement >();
	let batchItemSeq = 0;
	let activeController: AbortController | null = null;
	let runSeq = 0;
	let debounceTimer: number | null = null;
	let elapsedTimer: number | null = null;
	let dragDepth = 0;
	let inBatchMode = config.batchFocused;
	let resizeInvalid = false;
	let zipSeq = 0;

	// Active batch scheduler state (supports live additions & safe mid-run removals without skipping).
	let activeBatchRun: {
		runId: number;
		controller: AbortController;
		mime: EncodableOutputMime;
		quality: number;
		pngMode: PngOptimizationMode;
		resize?: CoreResizeOptions;
		activeLanes: number;
		inflightPixels: number;
		pumping: boolean;
		pumpAgain: boolean;
		usedMainThread: boolean;
	} | null = null;

	const clearPendingDebounce = () => {
		if ( debounceTimer !== null ) {
			window.clearTimeout( debounceTimer );
			debounceTimer = null;
		}
	};

	const clearElapsedTimer = () => {
		if ( elapsedTimer !== null ) {
			window.clearInterval( elapsedTimer );
			elapsedTimer = null;
		}
	};

	const showNotice = ( message: string, tone: 'info' | 'warning' | 'error' | 'success' ) => {
		if ( ! noticeEl ) {
			return;
		}
		noticeEl.setAttribute( 'role', tone === 'error' || tone === 'warning' ? 'alert' : 'status' );
		noticeEl.textContent = message;
		noticeEl.className = `kb-notice is-${ tone }`;
		noticeEl.hidden = false;
	};

	const clearNotice = () => {
		if ( noticeEl ) {
			noticeEl.hidden = true;
			noticeEl.textContent = '';
		}
	};

	const disableDownload = () => {
		if ( downloadBtn ) {
			downloadBtn.removeAttribute( 'href' );
			downloadBtn.removeAttribute( 'download' );
			downloadBtn.hidden = true;
		}
		if ( downloadPlaceholder ) {
			downloadPlaceholder.hidden = inBatchMode;
			downloadPlaceholder.disabled = true;
		}
	};

	const enableDownload = ( url: string, filename: string ) => {
		if ( downloadPlaceholder ) {
			downloadPlaceholder.hidden = true;
		}
		if ( downloadBtn ) {
			downloadBtn.href = url;
			downloadBtn.download = filename;
			downloadBtn.hidden = inBatchMode;
		}
	};

	const setStageVisible = ( visible: boolean, emptyMessage?: string ) => {
		if ( beforeImg ) {
			beforeImg.hidden = ! visible;
			if ( ! visible ) {
				beforeImg.src = TRANSPARENT_PIXEL;
			}
		}
		if ( afterImg ) {
			afterImg.hidden = ! visible;
			if ( ! visible ) {
				afterImg.src = TRANSPARENT_PIXEL;
			}
		}
		if ( leftTag ) {
			leftTag.hidden = ! visible;
		}
		if ( rightTag ) {
			rightTag.hidden = ! visible;
		}
		if ( splitDivider ) {
			splitDivider.hidden = ! visible;
		}
		if ( splitRange ) {
			splitRange.hidden = ! visible;
			splitRange.disabled = ! visible;
		}
		if ( emptyStage ) {
			if ( emptyMessage ) {
				emptyStage.textContent = emptyMessage;
			}
			emptyStage.hidden = visible;
		}
	};

	const revokeSingleUrls = () => {
		if ( beforeUrl ) {
			URL.revokeObjectURL( beforeUrl );
			beforeUrl = null;
		}
		if ( afterUrl ) {
			URL.revokeObjectURL( afterUrl );
			afterUrl = null;
		}
	};

	const revokeLastZipUrl = () => {
		if ( lastZipUrl ) {
			URL.revokeObjectURL( lastZipUrl );
			lastZipUrl = null;
		}
	};

	const revokeItemUrls = ( item: BatchItemState ) => {
		if ( item.downloadUrl ) {
			URL.revokeObjectURL( item.downloadUrl );
			item.downloadUrl = undefined;
		}
		if ( item.thumbUrl ) {
			URL.revokeObjectURL( item.thumbUrl );
			item.thumbUrl = undefined;
		}
	};

	const revokeBatchUrls = () => {
		for ( const item of batchItems ) {
			revokeItemUrls( item );
		}
	};

	const syncSingleOnlyResizePills = () => {
		sizePresetBtns.forEach( ( btn ) => {
			if ( btn.dataset.singleOnly ) {
				btn.hidden = ! singleSourceDims;
			}
		} );
	};

	const validateResizeInputs = (): { valid: boolean; options?: CoreResizeOptions; error?: string } => {
		if ( ! config.showResize ) {
			return { valid: true, options: undefined };
		}
		const wRaw = resizeWidthInput?.value.trim() || '';
		const hRaw = resizeHeightInput?.value.trim() || '';
		let w = 0;
		let h = 0;

		if ( wRaw ) {
			const parsedW = Number( wRaw );
			if ( ! Number.isFinite( parsedW ) || parsedW < 1 || parsedW > MAX_RESIZE_INPUT ) {
				return {
					valid: false,
					error: `Width must be between 1 and ${ MAX_RESIZE_INPUT.toLocaleString() } px (or leave blank for Auto).`,
				};
			}
			w = Math.round( parsedW );
		}
		if ( hRaw ) {
			const parsedH = Number( hRaw );
			if ( ! Number.isFinite( parsedH ) || parsedH < 1 || parsedH > MAX_RESIZE_INPUT ) {
				return {
					valid: false,
					error: `Height must be between 1 and ${ MAX_RESIZE_INPUT.toLocaleString() } px (or leave blank for Auto).`,
				};
			}
			h = Math.round( parsedH );
		}

		if ( ! w && ! h ) {
			return { valid: true, options: undefined };
		}
		return {
			valid: true,
			options: {
				width: w || undefined,
				height: h || undefined,
				maintainAspectRatio: resizeAspectInput ? resizeAspectInput.checked : true,
				preventUpscale: resizeNoUpscaleInput ? resizeNoUpscaleInput.checked : true,
			},
		};
	};

	const syncResizePlaceholders = () => {
		if ( ! resizeWidthInput || ! resizeHeightInput ) {
			return;
		}
		if ( ! singleSourceDims || ! ( resizeAspectInput ? resizeAspectInput.checked : true ) ) {
			resizeWidthInput.placeholder = 'Auto';
			resizeHeightInput.placeholder = 'Auto';
			return;
		}
		const parsed = validateResizeInputs();
		const w = parsed.options?.width ?? 0;
		const h = parsed.options?.height ?? 0;
		if ( w && ! h ) {
			const computedH = Math.max( 1, Math.round( ( singleSourceDims.height * w ) / singleSourceDims.width ) );
			resizeHeightInput.placeholder = `Auto (${ computedH })`;
			resizeWidthInput.placeholder = 'Auto';
		} else if ( h && ! w ) {
			const computedW = Math.max( 1, Math.round( ( singleSourceDims.width * h ) / singleSourceDims.height ) );
			resizeWidthInput.placeholder = `Auto (${ computedW })`;
			resizeHeightInput.placeholder = 'Auto';
		} else {
			resizeWidthInput.placeholder = `Auto (${ singleSourceDims.width })`;
			resizeHeightInput.placeholder = `Auto (${ singleSourceDims.height })`;
		}
	};

	const setWorking = ( working: boolean ) => {
		const cancelWasFocused = ! working && cancelBtn && document.activeElement === cancelBtn;
		if ( cancelBtn ) {
			cancelBtn.hidden = ! working;
		}
		if ( clearBtn ) {
			clearBtn.hidden = working || ( ! singleFile && batchItems.length === 0 );
		}
		if ( cancelWasFocused ) {
			if ( clearBtn && ! clearBtn.hidden ) {
				clearBtn.focus();
			} else {
				pickBtn?.focus();
			}
		}
	};

	const syncQualityEnabledState = () => {
		const isLosslessPng = activeMime === 'image/png' && activePngMode === 'lossless';
		if ( qualityInput ) {
			qualityInput.disabled = isLosslessPng;
		}
		if ( qualityVal ) {
			qualityVal.textContent = isLosslessPng ? 'Lossless' : String( activeQuality );
		}
		qualityGroup?.classList.toggle( 'is-muted', isLosslessPng );
		presetGroup?.classList.toggle( 'is-muted', isLosslessPng );
		presetBtns.forEach( ( btn ) => {
			btn.disabled = isLosslessPng;
		} );
	};

	const syncPngModeVisibility = () => {
		if ( pngModeGroup ) {
			pngModeGroup.hidden = activeMime !== 'image/png';
		}
		syncQualityEnabledState();
	};

	const syncFormatLabels = () => {
		const outLabel = labelForMime( activeMime );
		if ( optLabelEl ) {
			optLabelEl.textContent = `Output (${ outLabel })`;
		}
		if ( rightTag ) {
			rightTag.textContent = outLabel;
		}
		if ( downloadLabelEl ) {
			downloadLabelEl.textContent = `Download ${ outLabel }`;
		}
		if ( downloadPlaceholderLabel ) {
			downloadPlaceholderLabel.textContent = `Download ${ outLabel }`;
		}
		syncPngModeVisibility();
	};

	const updateSplit = ( pct: number ) => {
		const clamped = Math.max( 0, Math.min( 100, pct ) );
		if ( beforeImg ) {
			beforeImg.style.clipPath = `inset(0 ${ 100 - clamped }% 0 0)`;
		}
		if ( afterWrap ) {
			afterWrap.style.clipPath = `inset(0 0 0 ${ clamped }%)`;
		}
		if ( splitDivider ) {
			splitDivider.style.left = `${ clamped }%`;
		}
	};

	const highlightWpCallout = () => {
		if ( wpCallout ) {
			wpCallout.classList.add( 'is-highlighted' );
		}
	};

	const createBatchRowElement = ( item: BatchItemState ): HTMLLIElement => {
		const li = document.createElement( 'li' );
		li.className = 'kb-batch-row';
		li.dataset.itemId = String( item.id );

		const thumbBox = document.createElement( 'div' );
		thumbBox.className = 'kb-batch-thumb-box';
		thumbBox.dataset.part = 'thumb-box';
		li.appendChild( thumbBox );

		const info = document.createElement( 'div' );
		info.className = 'kb-batch-info';
		const nameEl = document.createElement( 'div' );
		nameEl.className = 'kb-batch-name';
		nameEl.dataset.part = 'name';
		const metaEl = document.createElement( 'div' );
		metaEl.className = 'kb-batch-meta';
		metaEl.dataset.part = 'meta';
		info.appendChild( nameEl );
		info.appendChild( metaEl );
		li.appendChild( info );

		const sav = document.createElement( 'div' );
		sav.dataset.part = 'savings';
		li.appendChild( sav );

		const actions = document.createElement( 'div' );
		actions.className = 'kb-batch-actions';
		actions.dataset.part = 'actions';
		li.appendChild( actions );

		return li;
	};

	const patchBatchRow = ( li: HTMLLIElement, item: BatchItemState ) => {
		const thumbBox = li.querySelector< HTMLElement >( '[data-part="thumb-box"]' );
		const nameEl = li.querySelector< HTMLElement >( '[data-part="name"]' );
		const metaEl = li.querySelector< HTMLElement >( '[data-part="meta"]' );
		const sav = li.querySelector< HTMLElement >( '[data-part="savings"]' );
		const actions = li.querySelector< HTMLElement >( '[data-part="actions"]' );

		const safeDisplayName = item.result?.filename ?? sanitizeFilename( item.file.name, 'image' );

		if ( thumbBox ) {
			const existingImg = thumbBox.querySelector< HTMLImageElement >( 'img' );
			const previewSrc = item.thumbUrl || ( item.result && item.result.optimizedSize <= 256 * 1024 ? item.downloadUrl : undefined );
			if ( item.status === 'done' && previewSrc ) {
				if ( existingImg ) {
					if ( existingImg.src !== previewSrc ) {
						existingImg.src = previewSrc;
					}
				} else {
					thumbBox.textContent = '';
					const img = document.createElement( 'img' );
					img.className = 'kb-batch-thumb';
					img.src = previewSrc;
					img.alt = '';
					thumbBox.appendChild( img );
				}
			} else {
				if ( existingImg ) {
					existingImg.remove();
				}
				const badgeText = item.status === 'done' ? '✓' : item.status === 'error' ? '!' : item.status === 'cancelled' ? '–' : '…';
				if ( thumbBox.textContent !== badgeText ) {
					thumbBox.textContent = badgeText;
				}
			}
		}

		if ( nameEl && nameEl.textContent !== safeDisplayName ) {
			nameEl.textContent = safeDisplayName;
		}

		if ( metaEl ) {
			let nextMeta = '';
			if ( item.status === 'done' && item.result ) {
				nextMeta = `${ formatBytes( item.result.originalSize ) } -> ${ formatBytes( item.result.optimizedSize ) } · ${ item.result.width }×${ item.result.height } · ${ encoderBadge( item.result.encoder ) }`;
			} else if ( item.status === 'processing' ) {
				const stageText = item.stage
					? stageLabel( item.stage, activeBatchRun?.mime ?? activeMime, ! activeBatchRun?.usedMainThread )
					: `Processing ${ formatBytes( item.file.size ) }...`;
				nextMeta = stageText;
			} else if ( item.status === 'queued' ) {
				nextMeta = `Queued (${ formatBytes( item.file.size ) })`;
			} else if ( item.status === 'cancelled' ) {
				nextMeta = 'Cancelled';
			} else {
				nextMeta = item.error || 'Failed to process';
			}
			if ( metaEl.textContent !== nextMeta ) {
				metaEl.textContent = nextMeta;
			}
		}

		if ( sav ) {
			if ( item.status === 'done' && item.result ) {
				if ( item.result.savedBytes > 0 ) {
					sav.className = 'kb-batch-savings';
					sav.textContent = `-${ item.result.savedPercent.toFixed( 1 ) }%`;
				} else {
					sav.className = 'kb-batch-savings is-neutral';
					sav.textContent = item.result.savedBytes === 0 ? '0%' : `+${ Math.abs( item.result.savedPercent ).toFixed( 1 ) }%`;
				}
			} else if ( item.status === 'error' ) {
				sav.className = 'kb-batch-savings is-error';
				sav.textContent = 'Error';
			} else {
				sav.className = 'kb-batch-savings is-neutral';
				sav.textContent = item.status === 'processing' ? 'Working...' : '--';
			}
		}

		if ( actions ) {
			const stateKey = `${ item.status }:${ item.downloadUrl || '' }:${ resizeInvalid ? 'inv' : 'ok' }`;
			if ( actions.dataset.stateKey !== stateKey ) {
				actions.dataset.stateKey = stateKey;
				actions.textContent = '';

				if ( item.status === 'done' && item.downloadUrl && item.result ) {
					if ( resizeInvalid ) {
						const disabledBtn = document.createElement( 'button' );
						disabledBtn.type = 'button';
						disabledBtn.className = 'kb-btn kb-btn-secondary';
						disabledBtn.disabled = true;
						disabledBtn.textContent = 'Download';
						actions.appendChild( disabledBtn );
					} else {
						const link = document.createElement( 'a' );
						link.className = 'kb-btn kb-btn-secondary';
						link.href = item.downloadUrl;
						link.download = item.result.filename;
						link.setAttribute( 'aria-label', `Download ${ item.result.filename }` );
						link.textContent = 'Download';
						actions.appendChild( link );
					}
				} else if ( item.status === 'error' || item.status === 'cancelled' ) {
					const retryBtn = document.createElement( 'button' );
					retryBtn.type = 'button';
					retryBtn.className = 'kb-btn kb-btn-secondary';
					retryBtn.textContent = 'Retry';
					retryBtn.setAttribute( 'aria-label', `Retry ${ safeDisplayName }` );
					retryBtn.addEventListener( 'click', () => void retryBatchItem( item.id ) );
					actions.appendChild( retryBtn );
				}

				if ( item.status !== 'processing' ) {
					const removeBtn = document.createElement( 'button' );
					removeBtn.type = 'button';
					removeBtn.className = 'kb-btn kb-btn-ghost kb-batch-remove';
					removeBtn.textContent = 'Remove';
					removeBtn.setAttribute( 'aria-label', `Remove ${ safeDisplayName }` );
					removeBtn.addEventListener( 'click', () => removeBatchItem( item.id ) );
					actions.appendChild( removeBtn );
				}
			}
		}
	};

	const renderBatchUi = () => {
		if ( ! batchList || ! batchHeadline || ! batchSubline || ! zipBtn ) {
			return;
		}

		const activeIds = new Set< number >( batchItems.map( ( i ) => i.id ) );
		for ( const [ id, el ] of batchRowEls.entries() ) {
			if ( ! activeIds.has( id ) ) {
				el.remove();
				batchRowEls.delete( id );
			}
		}

		let completed = 0;
		let totalOrig = 0;
		let totalOpt = 0;
		let errors = 0;
		let cancelled = 0;
		let inFlight = 0;

		for ( const item of batchItems ) {
			if ( item.status === 'done' && item.result ) {
				completed += 1;
				totalOrig += item.result.originalSize;
				totalOpt += item.result.optimizedSize;
			} else if ( item.status === 'error' ) {
				errors += 1;
			} else if ( item.status === 'cancelled' ) {
				cancelled += 1;
			} else {
				inFlight += 1;
			}

			let li = batchRowEls.get( item.id );
			if ( ! li ) {
				li = createBatchRowElement( item );
				batchRowEls.set( item.id, li );
				batchList.appendChild( li );
			}
			patchBatchRow( li, item );
		}

		if ( batchItems.length === 0 ) {
			batchHeadline.textContent = 'No images queued yet.';
			batchSubline.textContent = canUseToolWorker()
				? 'Drop multiple files to process up to 3 concurrently in browser Web Workers.'
				: 'Drop multiple files to process locally in your browser.';
			zipBtn.disabled = true;
			return;
		}

		const saved = totalOrig - totalOpt;
		const pct = totalOrig > 0 ? ( Math.abs( saved ) / totalOrig ) * 100 : 0;
		const suffixParts: string[] = [];
		if ( errors > 0 ) {
			suffixParts.push( `${ errors } skipped` );
		}
		if ( cancelled > 0 ) {
			suffixParts.push( `${ cancelled } cancelled` );
		}
		batchHeadline.textContent = `${ completed } of ${ batchItems.length } ready${ suffixParts.length ? ` (${ suffixParts.join( ', ' ) })` : '' }`;

		if ( completed > 0 ) {
			if ( saved > 0 ) {
				batchSubline.textContent = `${ formatBytes( totalOrig ) } -> ${ formatBytes( totalOpt ) } (-${ pct.toFixed( 1 ) }%, ${ formatBytes( saved ) } saved)`;
			} else if ( saved < 0 ) {
				batchSubline.textContent = `${ formatBytes( totalOrig ) } -> ${ formatBytes( totalOpt ) } (+${ pct.toFixed( 1 ) }% larger)`;
			} else {
				batchSubline.textContent = `${ formatBytes( totalOrig ) } -> ${ formatBytes( totalOpt ) } (same size)`;
			}
		} else if ( inFlight > 0 ) {
			const onMainThread = activeBatchRun?.usedMainThread || ! canUseToolWorker();
			batchSubline.textContent = onMainThread
				? 'Processing locally on the main browser thread...'
				: 'Processing locally inside browser Web Workers...';
		} else if ( cancelled > 0 && errors === 0 ) {
			batchSubline.textContent = 'Batch cancelled before any image completed.';
		} else if ( errors > 0 && cancelled === 0 ) {
			batchSubline.textContent = 'No supported images could be processed. Check each file message below.';
		} else {
			batchSubline.textContent = 'Batch stopped with no downloadable outputs.';
		}

		zipBtn.disabled = completed === 0 || resizeInvalid;
	};

	const finishBatchIfIdle = ( run: NonNullable< typeof activeBatchRun > ) => {
		if ( activeBatchRun !== run || run.runId !== runSeq ) {
			return;
		}
		const hasQueued = batchItems.some( ( item ) => ! item.removed && item.status === 'queued' );
		if ( run.activeLanes > 0 || run.pumping || ( hasQueued && ! run.controller.signal.aborted ) ) {
			return;
		}

		const wasAborted = run.controller.signal.aborted;
		activeBatchRun = null;
		activeController = null;

		if ( wasAborted ) {
			for ( const item of batchItems ) {
				if ( item.status === 'queued' || item.status === 'processing' ) {
					item.status = 'cancelled';
				}
			}
			setWorking( false );
			renderBatchUi();
			showNotice( 'Batch processing cancelled. Already-finished files remain ready to download.', 'info' );
			return;
		}

		setWorking( false );
		renderBatchUi();
		if ( statusLine && batchItems.length > 0 ) {
			statusLine.textContent = `Finished batch locally across ${ batchItems.length } files. Nothing was uploaded.`;
		}
		if ( batchItems.some( ( item ) => item.status === 'done' ) ) {
			highlightWpCallout();
		}
	};

	const pumpBatchQueue = () => {
		const run = activeBatchRun;
		if ( ! run || run.runId !== runSeq ) {
			return;
		}
		if ( run.pumping ) {
			run.pumpAgain = true;
			return;
		}
		run.pumping = true;

		void ( async () => {
			try {
				while ( run.runId === runSeq && activeBatchRun === run ) {
					run.pumpAgain = false;
					if ( run.controller.signal.aborted ) {
						break;
					}
					if ( run.activeLanes >= MAX_BATCH_LANES ) {
						break;
					}
					const next = batchItems.find( ( item ) => ! item.removed && item.status === 'queued' );
					if ( ! next ) {
						break;
					}

					// F16: Measure header dimensions BEFORE admitting the lane and budget
					// max(sourcePixels, targetOutputPixels) so both large sources AND upscaled
					// targets (e.g. 3 x 6000x6000) strictly obey MAX_INFLIGHT_PIXELS.
					if ( next.sourceDims === undefined ) {
						const dims = await readRasterDimensions( next.file ).catch( () => null );
						if ( run.runId !== runSeq || activeBatchRun !== run || run.controller.signal.aborted ) {
							break;
						}
						next.sourceDims = dims && dims[ 0 ] > 0 && dims[ 1 ] > 0 ? dims : null;
					}

					if ( next.removed || next.status !== 'queued' ) {
						continue;
					}

					let itemPixels = 4_000_000;
					if ( next.sourceDims ) {
						const [ srcW, srcH ] = next.sourceDims;
						const srcPixels = srcW * srcH;
						const target = computeTargetDimensions( srcW, srcH, run.resize );
						const outPixels = target.width * target.height;
						itemPixels = Math.max( srcPixels, outPixels );
					} else if ( run.resize?.width && run.resize?.height ) {
						itemPixels = Math.max( itemPixels, run.resize.width * run.resize.height );
					}
					if ( run.activeLanes > 0 && run.inflightPixels + itemPixels > MAX_INFLIGHT_PIXELS ) {
						break;
					}

					next.status = 'processing';
					next.stage = 'validating';
					run.activeLanes += 1;
					run.inflightPixels += itemPixels;
					renderBatchUi();

					void ( async () => {
						try {
							const res = await processImageInBrowser(
								{
									file: next.file,
									outputMime: run.mime,
									quality: run.quality,
									pngMode: run.pngMode,
									resize: run.resize,
									codecBaseUrl,
								},
								{
									workerUrl,
									signal: run.controller.signal,
									onStage: ( stage, viaWorker ) => {
										if ( next.removed || run.runId !== runSeq ) {
											return;
										}
										if ( ! viaWorker ) {
											run.usedMainThread = true;
										}
										next.stage = stage;
										renderBatchUi();
									},
								}
							);

							if ( next.removed || run.runId !== runSeq ) {
								return;
							}

							// F05: Keep locally created URLs private until after async thumbnailing
							// and generation re-check so a superseded run can NEVER revoke a newer run's URLs.
							const localDownloadUrl = URL.createObjectURL( res.blob );
							const localThumbUrl = await createBoundedThumbnailUrl( res.blob );
							if ( next.removed || run.runId !== runSeq ) {
								URL.revokeObjectURL( localDownloadUrl );
								if ( localThumbUrl ) {
									URL.revokeObjectURL( localThumbUrl );
								}
								return;
							}

							revokeItemUrls( next );
							next.result = res;
							next.downloadUrl = localDownloadUrl;
							next.thumbUrl = localThumbUrl;
							next.status = 'done';
							next.stage = undefined;
						} catch ( err ) {
							if ( next.removed || run.runId !== runSeq ) {
								return;
							}
							next.stage = undefined;
							if ( err instanceof DOMException && err.name === 'AbortError' ) {
								next.status = 'cancelled';
							} else {
								next.status = 'error';
								next.error = friendlyErrorMessage( err );
							}
						} finally {
							run.activeLanes -= 1;
							run.inflightPixels = Math.max( 0, run.inflightPixels - itemPixels );
							if ( run.runId === runSeq ) {
								renderBatchUi();
								pumpBatchQueue();
								finishBatchIfIdle( run );
							}
						}
					} )();
				}
			} finally {
				run.pumping = false;
				if ( run.pumpAgain && run.runId === runSeq && activeBatchRun === run ) {
					pumpBatchQueue();
				} else {
					finishBatchIfIdle( run );
				}
			}
		} )();
	};

	const removeBatchItem = ( id: number ) => {
		const idx = batchItems.findIndex( ( item ) => item.id === id );
		if ( idx < 0 ) {
			return;
		}
		const [ removed ] = batchItems.splice( idx, 1 );
		removed.removed = true;
		++zipSeq;
		revokeItemUrls( removed );
		revokeLastZipUrl();
		renderBatchUi();
		if ( activeBatchRun ) {
			pumpBatchQueue();
			finishBatchIfIdle( activeBatchRun );
		} else {
			setWorking( false );
		}
	};

	const retryBatchItem = async ( id: number ) => {
		const item = batchItems.find( ( i ) => i.id === id );
		if ( ! item || item.removed ) {
			return;
		}
		const resizeCheck = validateResizeInputs();
		if ( ! resizeCheck.valid ) {
			showNotice( resizeCheck.error!, 'warning' );
			return;
		}

		// If a batch is already running, re-queue into the live run; otherwise start a batch run for this item.
		++zipSeq;
		item.status = 'queued';
		item.error = undefined;
		revokeItemUrls( item );
		revokeLastZipUrl();
		if ( activeBatchRun && activeBatchRun.runId === runSeq ) {
			renderBatchUi();
			pumpBatchQueue();
			return;
		}
		startBatchRun( false, false );
	};

	const startBatchRun = ( resetExisting: boolean, preserveNotice: boolean ) => {
		clearPendingDebounce();
		clearElapsedTimer();
		const resizeCheck = validateResizeInputs();
		if ( ! resizeCheck.valid ) {
			resizeInvalid = true;
			renderBatchUi();
			showNotice( resizeCheck.error!, 'warning' );
			return;
		}
		resizeInvalid = false;

		const myRun = ++runSeq;
		++zipSeq;
		activeController?.abort();
		const controller = new AbortController();
		activeController = controller;
		inBatchMode = true;

		singleFile = null;
		singleSourceDims = null;
		syncSingleOnlyResizePills();
		syncResizePlaceholders();
		revokeSingleUrls();
		revokeLastZipUrl();
		disableDownload();
		if ( ! preserveNotice ) {
			clearNotice();
		}

		if ( singleView ) {
			singleView.hidden = true;
		}
		if ( batchView ) {
			batchView.hidden = false;
		}

		if ( resetExisting ) {
			// Replace with fresh BatchItemState objects so superseded async workers
			// cannot hold a mutable reference to the new run's row state.
			const filesToRerun = batchItems.map( ( item ) => ( {
				file: item.file,
				sourceDims: item.sourceDims,
			} ) );
			for ( const oldItem of batchItems ) {
				oldItem.removed = true;
				revokeItemUrls( oldItem );
			}
			batchItems = filesToRerun.map( ( entry ) => ( {
				id: ++batchItemSeq,
				file: entry.file,
				sourceDims: entry.sourceDims,
				status: 'queued',
			} ) );
		}

		activeBatchRun = {
			runId: myRun,
			controller,
			mime: activeMime,
			quality: activeQuality,
			pngMode: activePngMode,
			resize: resizeCheck.options,
			activeLanes: 0,
			inflightPixels: 0,
			pumping: false,
			pumpAgain: false,
			usedMainThread: ! canUseToolWorker(),
		};

		setWorking( true );
		renderBatchUi();
		pumpBatchQueue();
	};

	const runSingle = async ( file: File, statusOverride?: string ) => {
		clearPendingDebounce();
		clearElapsedTimer();

		const resizeCheck = validateResizeInputs();
		if ( ! resizeCheck.valid ) {
			resizeInvalid = true;
			activeController?.abort();
			activeController = null;
			setWorking( false );
			disableDownload();
			setStageVisible( false, 'Fix the resize dimensions above to generate a preview.' );
			showNotice( resizeCheck.error!, 'warning' );
			return;
		}
		resizeInvalid = false;

		const myRun = ++runSeq;
		++zipSeq;
		activeController?.abort();
		activeBatchRun = null;
		const controller = new AbortController();
		activeController = controller;
		inBatchMode = false;

		if ( batchItems.length > 0 ) {
			revokeBatchUrls();
			revokeLastZipUrl();
			batchItems = [];
			renderBatchUi();
		}

		if ( singleView ) {
			singleView.hidden = false;
		}
		if ( batchView ) {
			batchView.hidden = true;
		}

		clearNotice();
		disableDownload();
		setWorking( true );
		syncFormatLabels();

		const requestedResize = resizeCheck.options;
		const inMimeLabel = labelForMime( resolveInputMime( file ) );
		const targetMime = activeMime;
		const startedMs = performance.now();
		let currentStageText = `Encoding ${ labelForMime( targetMime ) } locally...`;

		const updateProgressMeta = () => {
			if ( myRun !== runSeq || ! optMetaEl ) {
				return;
			}
			const secs = Math.floor( ( performance.now() - startedMs ) / 1000 );
			optMetaEl.textContent = secs >= 1 ? `${ currentStageText } (${ secs }s)` : currentStageText;
		};

		if ( origSizeEl ) {
			origSizeEl.textContent = formatBytes( file.size );
		}
		if ( origLabelEl ) {
			origLabelEl.textContent = `Original (${ inMimeLabel })`;
		}
		if ( leftTag ) {
			leftTag.textContent = `Original (${ inMimeLabel })`;
		}
		if ( optSizeEl ) {
			optSizeEl.textContent = '...';
		}
		if ( optMetaEl ) {
			optMetaEl.textContent = currentStageText;
		}
		if ( savingsValEl ) {
			savingsValEl.textContent = '--';
		}
		if ( savingsNoteEl ) {
			savingsNoteEl.textContent = 'Measuring output...';
		}
		savingsStatEl?.classList.remove( 'is-good' );

		elapsedTimer = window.setInterval( updateProgressMeta, 1000 );

		try {
			const result = await processImageInBrowser(
				{
					file,
					outputMime: targetMime,
					quality: activeQuality,
					pngMode: activePngMode,
					resize: requestedResize,
					codecBaseUrl,
				},
				{
					workerUrl,
					signal: controller.signal,
					onStage: ( stage, viaWorker ) => {
						if ( myRun !== runSeq ) {
							return;
						}
						currentStageText = stageLabel( stage, targetMime, viaWorker );
						updateProgressMeta();
					},
				}
			);

			if ( myRun !== runSeq || resizeInvalid ) {
				return;
			}

			clearElapsedTimer();
			revokeSingleUrls();
			beforeUrl = URL.createObjectURL( file );
			afterUrl = URL.createObjectURL( result.blob );
			singleSourceDims = { width: result.sourceWidth, height: result.sourceHeight };
			syncSingleOnlyResizePills();
			syncResizePlaceholders();

			if ( beforeImg ) {
				beforeImg.src = beforeUrl;
			}
			if ( afterImg ) {
				afterImg.src = afterUrl;
			}
			setStageVisible( true );
			updateSplit( Number( splitRange?.value || 50 ) );

			if ( origDimsEl ) {
				origDimsEl.textContent = `${ result.sourceWidth } × ${ result.sourceHeight } px`;
			}
			if ( optSizeEl ) {
				optSizeEl.textContent = formatBytes( result.optimizedSize );
			}
			if ( optMetaEl ) {
				optMetaEl.textContent = `${ result.width } × ${ result.height } px · ${ encoderBadge( result.encoder ) } · ${ result.durationMs } ms`;
			}

			if ( result.savedBytes > 0 ) {
				savingsStatEl?.classList.add( 'is-good' );
				if ( savingsValEl ) {
					savingsValEl.textContent = `-${ result.savedPercent.toFixed( 1 ) }%`;
				}
				if ( savingsNoteEl ) {
					savingsNoteEl.textContent = `${ formatBytes( result.savedBytes ) } smaller`;
				}
			} else if ( result.savedBytes === 0 ) {
				savingsStatEl?.classList.remove( 'is-good' );
				if ( savingsValEl ) {
					savingsValEl.textContent = '0%';
				}
				if ( savingsNoteEl ) {
					savingsNoteEl.textContent = 'Same size as original';
				}
			} else {
				savingsStatEl?.classList.remove( 'is-good' );
				if ( savingsValEl ) {
					savingsValEl.textContent = `+${ Math.abs( result.savedPercent ).toFixed( 1 ) }%`;
				}
				if ( savingsNoteEl ) {
					savingsNoteEl.textContent = 'Original is already smaller';
				}
			}

			enableDownload( afterUrl, result.filename );
			if ( result.notice ) {
				showNotice( result.notice, 'info' );
			} else if (
				requestedResize &&
				( requestedResize.width || requestedResize.height ) &&
				requestedResize.preventUpscale !== false &&
				! result.resized
			) {
				showNotice(
					`Original is already smaller than or equal to the requested dimensions; kept at ${ result.sourceWidth } × ${ result.sourceHeight } px because Never upscale is enabled.`,
					'info'
				);
			}

			if ( statusLine ) {
				const safeName = sanitizeFilename( file.name, 'image' );
				statusLine.textContent = statusOverride || `Processed ${ safeName } locally in ${ result.durationMs } ms. Nothing was uploaded.`;
			}
			highlightWpCallout();
		} catch ( error ) {
			if ( myRun !== runSeq ) {
				return;
			}
			clearElapsedTimer();
			if ( error instanceof DOMException && error.name === 'AbortError' ) {
				revokeSingleUrls();
				setStageVisible( false, 'Processing cancelled. Adjust settings or drop another image to run again.' );
				if ( optSizeEl ) {
					optSizeEl.textContent = '--';
				}
				if ( optMetaEl ) {
					optMetaEl.textContent = 'Cancelled';
				}
				if ( savingsValEl ) {
					savingsValEl.textContent = '--';
				}
				if ( savingsNoteEl ) {
					savingsNoteEl.textContent = 'Processing cancelled';
				}
				showNotice( 'Processing cancelled.', 'info' );
				return;
			}
			revokeSingleUrls();
			singleSourceDims = null;
			syncSingleOnlyResizePills();
			syncResizePlaceholders();
			setStageVisible( false, 'Could not preview this file. Drop or choose another supported image.' );
			if ( origDimsEl ) {
				origDimsEl.textContent = 'Unreadable image';
			}
			if ( optSizeEl ) {
				optSizeEl.textContent = '--';
			}
			if ( optMetaEl ) {
				optMetaEl.textContent = 'Processing failed';
			}
			if ( savingsValEl ) {
				savingsValEl.textContent = '--';
			}
			if ( savingsNoteEl ) {
				savingsNoteEl.textContent = 'No output generated';
			}
			showNotice( friendlyErrorMessage( error ), 'error' );
		} finally {
			if ( myRun === runSeq ) {
				clearElapsedTimer();
				activeController = null;
				setWorking( false );
			}
		}
	};

	const rerunActive = () => {
		clearPendingDebounce();
		const resizeCheck = validateResizeInputs();
		if ( ! resizeCheck.valid ) {
			resizeInvalid = true;
			clearElapsedTimer();
			++runSeq;
			++zipSeq;
			activeController?.abort();
			activeController = null;
			activeBatchRun = null;
			for ( const item of batchItems ) {
				if ( item.status === 'processing' ) {
					item.status = 'queued';
					item.stage = undefined;
				}
			}
			setWorking( false );
			disableDownload();
			if ( batchItems.length > 0 ) {
				renderBatchUi();
			}
			showNotice( resizeCheck.error!, 'warning' );
			return;
		}
		resizeInvalid = false;
		clearNotice();
		if ( singleFile ) {
			void runSingle( singleFile );
		} else if ( batchItems.length > 0 ) {
			startBatchRun( true, false );
		}
	};

	const scheduleRerun = ( delayMs = 250 ) => {
		clearPendingDebounce();
		debounceTimer = window.setTimeout( () => {
			debounceTimer = null;
			rerunActive();
		}, delayMs );
	};

	const handleIncomingFiles = ( fileList: FileList | File[] | null, skippedFolderCount = 0 ) => {
		clearPendingDebounce();
		const files = fileList ? Array.from( fileList ) : [];
		if ( files.length === 0 ) {
			if ( skippedFolderCount > 0 ) {
				showNotice( `Folder drop is capped at ${ MAX_DROPPED_FOLDER_FILES } files at once.`, 'warning' );
			}
			return;
		}

		const hasSkipped = skippedFolderCount > 0;
		const showFolderWarning = () => {
			if ( hasSkipped ) {
				showNotice(
					`Folder drop is limited to ${ MAX_DROPPED_FOLDER_FILES } files at once. Loaded ${ files.length } files and omitted ${ skippedFolderCount } additional file${ skippedFolderCount === 1 ? '' : 's' }.`,
					'warning'
				);
			}
		};

		const alreadyInBatch = inBatchMode && batchItems.length > 0;
		if ( config.batchFocused || files.length > 1 || alreadyInBatch ) {
			const newItems: BatchItemState[] = files.map( ( file ) => ( {
				id: ++batchItemSeq,
				file,
				status: 'queued',
			} ) );

			if ( alreadyInBatch ) {
				// Preserve existing batch items whether idle or actively running!
				batchItems = [ ...batchItems, ...newItems ];
				++zipSeq;
				revokeLastZipUrl();
				showFolderWarning();
				if ( activeBatchRun && activeBatchRun.runId === runSeq ) {
					renderBatchUi();
					pumpBatchQueue();
				} else {
					startBatchRun( false, hasSkipped );
				}
				return;
			}

			revokeBatchUrls();
			batchItems = newItems;
			showFolderWarning();
			startBatchRun( false, hasSkipped );
			return;
		}

		singleFile = files[ 0 ];
		void runSingle( singleFile );
	};

	const loadSamplePhoto = async () => {
		if ( ! config.sampleUrl ) {
			return;
		}
		clearPendingDebounce();
		clearElapsedTimer();
		const myRun = ++runSeq;
		++zipSeq;
		activeController?.abort();
		activeBatchRun = null;
		const controller = new AbortController();
		activeController = controller;
		inBatchMode = false;

		if ( singleView ) {
			singleView.hidden = false;
		}
		if ( batchView ) {
			batchView.hidden = true;
		}
		clearNotice();
		disableDownload();
		setWorking( true );
		setStageVisible( false, 'Loading sample photo locally...' );

		try {
			const res = await fetch( config.sampleUrl, { signal: controller.signal } );
			if ( ! res.ok ) {
				throw new Error( 'sample_fetch_failed' );
			}
			const blob = await res.blob();
			if ( myRun !== runSeq || controller.signal.aborted ) {
				return;
			}
			const file = new File( [ blob ], 'sample-photo.jpg', { type: 'image/jpeg' } );
			singleFile = file;
			activeController = null;
			await runSingle(
				file,
				'Previewing the sample photo locally in your browser. Drop your own photo to test it.'
			);
		} catch ( err ) {
			if ( myRun !== runSeq ) {
				return;
			}
			activeController = null;
			setWorking( false );
			if ( err instanceof DOMException && err.name === 'AbortError' ) {
				setStageVisible( false, 'Sample loading cancelled. Drop or choose an image above.' );
				showNotice( 'Processing cancelled.', 'info' );
				return;
			}
			setStageVisible( false, 'Could not load the sample photo. Drop or choose your own image above.' );
			showNotice( 'Could not load the sample photo. Pick or drop your own image above.', 'warning' );
		}
	};

	// Wire events
	pickBtn?.addEventListener( 'click', () => fileInput?.click() );

	dropzone?.addEventListener( 'click', () => fileInput?.click() );
	dropzone?.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			fileInput?.click();
		}
	} );

	fileInput?.addEventListener( 'change', () => {
		handleIncomingFiles( fileInput.files );
		fileInput.value = '';
	} );

	sampleBtn?.addEventListener( 'click', () => void loadSamplePhoto() );

	cancelBtn?.addEventListener( 'click', () => {
		clearPendingDebounce();
		clearElapsedTimer();
		const controllerToAbort = activeController;
		controllerToAbort?.abort();
		if ( activeBatchRun && activeBatchRun.controller === controllerToAbort ) {
			finishBatchIfIdle( activeBatchRun );
		}
	} );

	clearBtn?.addEventListener( 'click', () => {
		clearPendingDebounce();
		clearElapsedTimer();
		++runSeq;
		++zipSeq;
		activeController?.abort();
		activeController = null;
		activeBatchRun = null;
		resizeInvalid = ! validateResizeInputs().valid;
		inBatchMode = config.batchFocused;
		singleFile = null;
		singleSourceDims = null;
		syncSingleOnlyResizePills();
		syncResizePlaceholders();
		revokeSingleUrls();
		revokeBatchUrls();
		revokeLastZipUrl();
		batchItems = [];
		disableDownload();
		clearNotice();
		setWorking( false );

		if ( singleView ) {
			singleView.hidden = config.batchFocused;
		}
		if ( batchView ) {
			batchView.hidden = ! config.batchFocused;
		}

		setStageVisible( false, 'Drop or choose an image above to preview before and after locally.' );
		if ( splitRange ) {
			splitRange.value = '50';
		}
		updateSplit( 50 );
		if ( origLabelEl ) {
			origLabelEl.textContent = 'Original';
		}
		if ( leftTag ) {
			leftTag.textContent = 'Original';
		}
		if ( origSizeEl ) {
			origSizeEl.textContent = '--';
		}
		if ( origDimsEl ) {
			origDimsEl.textContent = 'Waiting for image';
		}
		if ( optSizeEl ) {
			optSizeEl.textContent = '--';
		}
		if ( optMetaEl ) {
			optMetaEl.textContent = 'Local encoder ready';
		}
		if ( savingsValEl ) {
			savingsValEl.textContent = '--';
		}
		if ( savingsNoteEl ) {
			savingsNoteEl.textContent = 'Select an image to measure';
		}
		if ( statusLine ) {
			statusLine.textContent = '100% local browser processing. Your image files never leave your device.';
		}
		savingsStatEl?.classList.remove( 'is-good' );
		renderBatchUi();
	} );

	formatBtns.forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			const next = btn.dataset.mime as EncodableOutputMime | undefined;
			if ( ! next || next === activeMime ) {
				return;
			}
			activeMime = next;
			formatBtns.forEach( ( b ) => {
				const isSelected = b === btn;
				b.classList.toggle( 'is-active', isSelected );
				b.setAttribute( 'aria-pressed', String( isSelected ) );
			} );
			syncFormatLabels();
			rerunActive();
		} );
	} );

	presetBtns.forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			if ( btn.disabled ) {
				return;
			}
			const q = Number( btn.dataset.quality || 82 );
			activeQuality = q;
			if ( qualityInput ) {
				qualityInput.value = String( q );
			}
			if ( qualityVal ) {
				qualityVal.textContent = String( q );
			}
			presetBtns.forEach( ( b ) => {
				const isSelected = b === btn;
				b.classList.toggle( 'is-active', isSelected );
				b.setAttribute( 'aria-pressed', String( isSelected ) );
			} );
			rerunActive();
		} );
	} );

	qualityInput?.addEventListener( 'input', () => {
		activeQuality = Number( qualityInput.value );
		if ( qualityVal ) {
			qualityVal.textContent = String( activeQuality );
		}
		presetBtns.forEach( ( b ) => {
			const matches = Number( b.dataset.quality ) === activeQuality;
			b.classList.toggle( 'is-active', matches );
			b.setAttribute( 'aria-pressed', String( matches ) );
		} );
		scheduleRerun( batchItems.length > 0 ? 350 : 250 );
	} );

	pngModeBtns.forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			const mode = btn.dataset.mode as PngOptimizationMode | undefined;
			if ( ! mode || mode === activePngMode ) {
				return;
			}
			activePngMode = mode;
			pngModeBtns.forEach( ( b ) => {
				const isSelected = b === btn;
				b.classList.toggle( 'is-active', isSelected );
				b.setAttribute( 'aria-pressed', String( isSelected ) );
			} );
			syncQualityEnabledState();
			rerunActive();
		} );
	} );

	const handleResizeInput = () => {
		syncResizePlaceholders();
		const check = validateResizeInputs();
		if ( ! check.valid ) {
			resizeInvalid = true;
			clearPendingDebounce();
			clearElapsedTimer();
			// F09: Cancel any in-flight encode or ZIP build immediately so stale results
			// cannot restore Download or emit a ZIP while resize dimensions are invalid.
			++runSeq;
			++zipSeq;
			activeController?.abort();
			activeController = null;
			activeBatchRun = null;
			for ( const item of batchItems ) {
				if ( item.status === 'processing' ) {
					item.status = 'queued';
					item.stage = undefined;
				}
			}
			setWorking( false );
			disableDownload();
			if ( batchItems.length > 0 ) {
				renderBatchUi();
			}
			showNotice( check.error!, 'warning' );
			return;
		}
		resizeInvalid = false;
		clearNotice();
		scheduleRerun( 350 );
	};

	resizeWidthInput?.addEventListener( 'input', handleResizeInput );
	resizeHeightInput?.addEventListener( 'input', handleResizeInput );
	resizeAspectInput?.addEventListener( 'change', () => {
		syncResizePlaceholders();
		rerunActive();
	} );
	resizeNoUpscaleInput?.addEventListener( 'change', rerunActive );

	sizePresetBtns.forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			if ( ! resizeWidthInput || ! resizeHeightInput ) {
				return;
			}
			let changed = false;
			if ( btn.dataset.clear ) {
				changed = resizeWidthInput.value !== '' || resizeHeightInput.value !== '';
				resizeWidthInput.value = '';
				resizeHeightInput.value = '';
			} else if ( btn.dataset.width ) {
				changed = resizeWidthInput.value !== btn.dataset.width || resizeHeightInput.value !== '';
				resizeWidthInput.value = btn.dataset.width;
				resizeHeightInput.value = '';
			} else if ( btn.dataset.scale && singleSourceDims ) {
				const scale = Number( btn.dataset.scale );
				const nextW = String( Math.max( 1, Math.round( singleSourceDims.width * scale ) ) );
				changed = resizeWidthInput.value !== nextW || resizeHeightInput.value !== '';
				resizeWidthInput.value = nextW;
				resizeHeightInput.value = '';
			}
			if ( changed ) {
				syncResizePlaceholders();
				rerunActive();
			}
		} );
	} );

	splitRange?.addEventListener( 'input', () => {
		updateSplit( Number( splitRange.value ) );
	} );

	zipBtn?.addEventListener( 'click', async () => {
		if ( resizeInvalid || ! validateResizeInputs().valid ) {
			zipBtn.disabled = true;
			return;
		}
		const completed = batchItems.filter( ( item ) => ! item.removed && item.status === 'done' && item.result );
		if ( completed.length === 0 ) {
			return;
		}
		clearNotice();

		const projectedBytes = completed.reduce(
			( sum, item ) => sum + item.result!.blob.size + item.result!.filename.length * 2 + 128,
			22
		);
		if ( projectedBytes > MAX_ZIP_BYTES ) {
			showNotice( friendlyErrorMessage( new Error( 'zip_too_large' ) ), 'error' );
			return;
		}

		const myZipRun = ++zipSeq;
		zipBtn.disabled = true;
		try {
			const zipBlob = await createZipBlob(
				completed.map( ( item ) => ( {
					name: item.result!.filename,
					blob: item.result!.blob,
				} ) )
			);
			if ( myZipRun !== zipSeq || resizeInvalid ) {
				return;
			}
			revokeLastZipUrl();
			lastZipUrl = URL.createObjectURL( zipBlob );
			const a = document.createElement( 'a' );
			a.href = lastZipUrl;
			a.download = `kilobyte-${ config.slug }.zip`;
			document.body.appendChild( a );
			a.click();
			a.remove();
		} catch ( err ) {
			if ( myZipRun === zipSeq ) {
				showNotice( friendlyErrorMessage( err ), 'error' );
			}
		} finally {
			if ( myZipRun === zipSeq ) {
				zipBtn.disabled = resizeInvalid || ! batchItems.some( ( item ) => ! item.removed && item.status === 'done' && item.result );
			}
		}
	} );

	// Drag and drop on the whole card + dropzone (supports files & dropped folders).
	const hasDraggedFiles = ( dt: DataTransfer | null ): boolean => {
		return Boolean( dt?.types && Array.from( dt.types ).includes( 'Files' ) );
	};

	root.addEventListener( 'dragenter', ( e ) => {
		if ( ! hasDraggedFiles( e.dataTransfer ) ) {
			return;
		}
		e.preventDefault();
		dragDepth += 1;
		root.classList.add( 'is-dragover' );
		dropzone?.classList.add( 'is-dragover' );
	} );
	root.addEventListener( 'dragover', ( e ) => {
		if ( ! hasDraggedFiles( e.dataTransfer ) ) {
			return;
		}
		e.preventDefault();
		if ( e.dataTransfer ) {
			e.dataTransfer.dropEffect = 'copy';
		}
	} );
	root.addEventListener( 'dragleave', () => {
		dragDepth = Math.max( 0, dragDepth - 1 );
		if ( dragDepth === 0 ) {
			root.classList.remove( 'is-dragover' );
			dropzone?.classList.remove( 'is-dragover' );
		}
	} );
	root.addEventListener( 'dragend', () => {
		dragDepth = 0;
		root.classList.remove( 'is-dragover' );
		dropzone?.classList.remove( 'is-dragover' );
	} );
	root.addEventListener( 'drop', async ( e ) => {
		if ( ! hasDraggedFiles( e.dataTransfer ) ) {
			return;
		}
		e.preventDefault();
		dragDepth = 0;
		root.classList.remove( 'is-dragover' );
		dropzone?.classList.remove( 'is-dragover' );
		const { files, skippedCount } = await readDroppedFiles( e.dataTransfer );
		handleIncomingFiles( files, skippedCount );
	} );

	// Preserve bfcache: only revoke object URLs and workers when not persisted.
	window.addEventListener( 'pagehide', ( event ) => {
		if ( event.persisted ) {
			return;
		}
		clearPendingDebounce();
		clearElapsedTimer();
		activeController?.abort();
		revokeSingleUrls();
		revokeBatchUrls();
		revokeLastZipUrl();
		disposeWorkerPool();
	} );

	syncFormatLabels();
	syncSingleOnlyResizePills();
	disableDownload();
	setStageVisible( false );
	updateSplit( 50 );
	if ( config.sampleUrl && config.autoLoadSample !== false && ! config.batchFocused ) {
		void loadSamplePhoto();
	}
}

// Auto-bootstrap on standalone tool pages.
document.querySelectorAll< HTMLElement >( '[data-tool-widget]' ).forEach( ( el ) => {
	initToolWidget( el );
} );
