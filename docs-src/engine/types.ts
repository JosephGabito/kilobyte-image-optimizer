import type {
	CoreResizeOptions,
	EncodableOutputMime,
	PngOptimizationMode,
} from '../../src-js/core/types';

export interface ToolProcessRequest {
	file: File;
	outputMime: EncodableOutputMime;
	/** Integer 40..95 */
	quality: number;
	pngMode?: PngOptimizationMode;
	maxWidth?: number;
	maxHeight?: number;
	resize?: CoreResizeOptions;
	/** Base URL for self-hosted /codecs/*.wasm files (e.g. "/codecs") */
	codecBaseUrl: string;
	/** Allow forcing WASM codecs in tests/benchmarks even when native exists */
	preferWasm?: boolean;
}

export interface ToolProcessResult {
	blob: Blob;
	filename: string;
	mimeType: EncodableOutputMime;
	originalSize: number;
	optimizedSize: number;
	savedBytes: number;
	savedPercent: number;
	sourceWidth: number;
	sourceHeight: number;
	width: number;
	height: number;
	resized: boolean;
	encoder: 'native' | 'upng' | 'oxipng' | 'upng+oxipng' | 'wasm-webp' | 'wasm-avif';
	durationMs: number;
	keptOriginal: boolean;
	notice?: string;
}

export type ToolProcessStage =
	| 'validating'
	| 'decoding'
	| 'loading-codec'
	| 'encoding'
	| 'verifying';

export interface WorkerRequestMessage {
	id: number;
	request: ToolProcessRequest;
}

export interface WorkerResponseMessage {
	id: number;
	stage?: ToolProcessStage;
	result?: ToolProcessResult;
	error?: string;
}
