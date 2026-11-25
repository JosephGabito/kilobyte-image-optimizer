export type SupportedInputMime =
	| 'image/jpeg'
	| 'image/png'
	| 'image/webp'
	| 'image/avif'
	| 'image/bmp'
	| 'image/gif';

export type EncodableOutputMime =
	| 'image/webp'
	| 'image/avif'
	| 'image/jpeg'
	| 'image/png';

export type PngOptimizationMode = 'lossy' | 'lossless';

export type ResizeMode = 'contain' | 'exact';

export interface CoreBrowserSupport {
	webp: boolean;
	avif: boolean;
	jpeg: boolean;
	png: boolean;
}

export interface CoreResizeOptions {
	width?: number;
	height?: number;
	maxWidth?: number;
	maxHeight?: number;
	maintainAspectRatio?: boolean;
	preventUpscale?: boolean;
	mode?: ResizeMode;
}

export interface CoreEncodeRequest {
	file: File;
	maxWidth: number;
	maxHeight: number;
	mime: string;
	quality: number;
	resize?: CoreResizeOptions;
}

export interface CoreEncodeResult {
	blob: Blob;
	width: number;
	height: number;
	resized: boolean;
	encoder: 'native' | 'upng' | 'oxipng' | 'upng+oxipng' | 'wasm-webp' | 'wasm-avif';
}

export interface ZipInputEntry {
	name: string;
	data: Uint8Array;
	modifiedAt?: Date;
}
