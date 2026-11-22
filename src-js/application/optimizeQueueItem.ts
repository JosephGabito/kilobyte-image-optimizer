import { formatBytes, resolveTargetMime } from '../domain/format';
import type { BrowserSupport, CompressionResult, OptimizeResponse, PluginSettings, QueueItem } from '../domain/types';

export interface CompressorPort {
	detectSupport: () => Promise< BrowserSupport >;
	compress: ( file: File, settings: PluginSettings ) => Promise< CompressionResult< File > >;
}

export interface OptimizeApiPort {
	optimizeOnServer: ( id: number ) => Promise< OptimizeResponse >;
	uploadOptimized: ( id: number, file: File, filename: string, options?: { sourceHash?: string } ) => Promise< OptimizeResponse >;
	markSkipped: ( id: number, sourceHash: string ) => Promise< { status: string } >;
	fetchImageBlob: ( url: string, onProgress?: ( loaded: number, total: number ) => void ) => Promise< Blob >;
}

export type OptimizeStage = 'download' | 'browser' | 'server' | 'save';

export type OptimizeItemOutcome =
	| { title: string; skipReason: string }
	| { title: string; subtitle: string; metric: string; tone: 'success' };

export async function optimizeQueueItem(
	item: QueueItem,
	settings: PluginSettings,
	compressor: CompressorPort,
	api: OptimizeApiPort,
	onStage: ( stage: OptimizeStage, filename: string, download?: { loaded: number; total: number } ) => void,
	outputMimes: string[]
): Promise< OptimizeItemOutcome > {
	const skipped = ( status?: string ): OptimizeItemOutcome => ( { title: item.filename, skipReason: status ?? 'unknown' } );
	const outcome = ( saved: OptimizeResponse ): OptimizeItemOutcome => {
		if ( saved.skipped ) {
			return skipped( saved.status );
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

	if ( ! result.sourceHash ) {
		return onServer();
	}

	onStage( 'save', item.filename );
	try {
		if ( ! result.file || ! result.filename ) {
			const saved = await api.markSkipped( item.id, result.sourceHash );
			return skipped( saved.status );
		}
		return outcome( await api.uploadOptimized( item.id, result.file, result.filename, { sourceHash: result.sourceHash } ) );
	} catch ( error ) {
		const code = ( error as { code?: string } )?.code;
		if ( code === 'kilobyte_io_invalid_mime' || code === 'kilobyte_io_invalid_image' || code === 'kilobyte_io_source_changed' ) {
			return onServer();
		}
		throw error;
	}
}
