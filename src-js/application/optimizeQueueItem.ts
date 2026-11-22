import { formatBytes, resolveTargetMime } from '../domain/format';
import type { BrowserSupport, CompressionResult, OptimizeResponse, PluginSettings, QueueItem } from '../domain/types';

export interface CompressorPort {
	detectSupport: () => Promise< BrowserSupport >;
	compress: ( file: File, settings: PluginSettings ) => Promise< CompressionResult< File > >;
}

export interface OptimizeApiPort {
	optimizeOnServer: ( id: number ) => Promise< OptimizeResponse >;
	uploadOptimized: ( id: number, file: File, filename: string ) => Promise< OptimizeResponse >;
	markSkipped: ( id: number ) => Promise< void >;
	fetchImageBlob: ( url: string, onProgress?: ( loaded: number, total: number ) => void ) => Promise< Blob >;
}

export type OptimizeStage = 'download' | 'browser' | 'server' | 'save';

export interface OptimizeItemOutcome {
	title: string;
	subtitle: string;
	metric: string;
	tone: 'success' | 'muted';
}

export async function optimizeQueueItem(
	item: QueueItem,
	settings: PluginSettings,
	compressor: CompressorPort,
	api: OptimizeApiPort,
	onStage: ( stage: OptimizeStage, filename: string, download?: { loaded: number; total: number } ) => void,
	outputMimes: string[]
): Promise< OptimizeItemOutcome > {
	const outcome = ( saved: OptimizeResponse ): OptimizeItemOutcome => {
		if ( saved.skipped ) {
			return {
				title: item.filename,
				subtitle: saved.status === 'already_optimal' ? 'Already small enough' : 'Original kept',
				metric: 'Skipped',
				tone: 'muted',
			};
		}
		return {
			title: item.filename,
			subtitle: `${ formatBytes( saved.original_size ) } to ${ formatBytes( saved.optimized_size ) } · ${ saved.format.toUpperCase() }`,
			metric: `-${ saved.saved_percent }%`,
			tone: 'success',
		};
	};
	const onServer = async () => {
		onStage( 'server', item.filename );
		return outcome( await api.optimizeOnServer( item.id ) );
	};

	const target = resolveTargetMime( settings.format, item.mime_type, await compressor.detectSupport() );
	if ( ! target || ! outputMimes.includes( target.mime ) ) {
		return onServer();
	}

	let result: CompressionResult< File >;
	try {
		onStage( 'download', item.filename );
		const blob = await api.fetchImageBlob( item.url, ( loaded, total ) => onStage( 'download', item.filename, { loaded, total } ) );
		const mime = blob.type.split( ';' )[ 0 ];
		if ( mime && mime !== item.mime_type && mime !== 'application/octet-stream' ) {
			return onServer();
		}
		onStage( 'browser', item.filename );
		result = await compressor.compress( new File( [ blob ], item.filename, { type: item.mime_type } ), settings );
	} catch {
		return onServer();
	}

	if ( result.reason === 'unsupported_mime' ) {
		return onServer();
	}
	if ( result.reason === 'animated' ) {
		return {
			title: item.filename,
			subtitle: 'Animation kept',
			metric: 'Skipped',
			tone: 'muted',
		};
	}

	onStage( 'save', item.filename );
	if ( ! result.file || ! result.filename ) {
		await api.markSkipped( item.id );
		return outcome( { id: item.id, skipped: true, status: 'already_optimal' } as OptimizeResponse );
	}

	try {
		return outcome( await api.uploadOptimized( item.id, result.file, result.filename ) );
	} catch ( error ) {
		const code = ( error as { code?: string } )?.code;
		if ( code === 'kilobyte_io_invalid_mime' || code === 'kilobyte_io_invalid_image' ) {
			return onServer();
		}
		throw error;
	}
}
