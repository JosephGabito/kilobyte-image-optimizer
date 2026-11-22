export type OutputFormat = 'webp' | 'avif' | 'original';

export type QueueMode = 'optimize' | 'regenerate' | 'restore';

export interface PluginSettings {
	enabled: boolean;
	format: OutputFormat;
	quality: number;
	max_width: number;
	max_height: number;
	keep_backups: boolean;
	skip_smaller: boolean;
	auto_alt_text: boolean;
	cwv_enhancements: boolean;
	concurrency: number;
}

export interface LibraryStats {
	total_images: number;
	optimized_count: number;
	remaining_count: number;
	total_original: number;
	total_optimized: number;
	total_saved: number;
	saved_percent: number;
	backup_count: number;
	backup_bytes: number;
	restorable_count: number;
	needs_reconcile?: boolean;
}

export interface QueueItem {
	id: number;
	title: string;
	filename: string;
	url: string;
	mime_type: string;
	file_size: number;
	width: number;
	height: number;
}

export interface QueuePage {
	items: QueueItem[];
	skipped_missing?: number;
	next_before: number;
}

export interface BrowserSupport {
	webp: boolean;
	avif: boolean;
}

export interface CompressionResult< EncodedFile > {
	skipped: boolean;
	reason?: 'unsupported_mime' | 'not_smaller' | 'animated' | 'original_format_kept';
	file?: EncodedFile;
	filename?: string;
	mimeType?: string;
	format?: string;
	originalSize: number;
	optimizedSize: number;
	savedBytes?: number;
	sourceHash?: string;
	width?: number;
	height?: number;
}

export interface RestoreResponse {
	restored: boolean;
	mode?: 'sibling' | 'in_place';
	thumbnails_ready?: boolean;
	backup_removed?: boolean;
}

export interface OptimizeResponse {
	id: number;
	skipped?: boolean;
	status?: string;
	original_size: number;
	optimized_size: number;
	saved_bytes: number;
	saved_percent: number;
	format: string;
	delivery_mode?: string;
	has_backup?: boolean;
	engine?: string;
}

export interface LogEntry {
	id: string;
	title: string;
	subtitle?: string;
	metric?: string;
	tone: 'success' | 'error' | 'muted' | 'info';
	action?: string;
	technical?: string;
}

export interface BulkProgress {
	done: number;
	total: number;
	stage: string;
	currentFile?: string;
	eta?: string;
	download?: { loaded: number; total: number };
}

export interface AdminBootstrapConfig {
	workerUrl: string;
	outputMimes: string[];
	canManage: boolean;
	settings: PluginSettings;
}

export interface UploadBootstrapConfig {
	workerUrl: string;
	mediaUrl: string;
	optimizerUrl: string;
	outputMimes: string[];
	settings: PluginSettings;
}

export const DEFAULT_SETTINGS: PluginSettings = {
	enabled: true,
	format: 'webp',
	quality: 82,
	max_width: 2560,
	max_height: 2560,
	keep_backups: true,
	skip_smaller: true,
	auto_alt_text: true,
	cwv_enhancements: true,
	concurrency: 3,
};
