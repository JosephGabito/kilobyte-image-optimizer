import { encodeRaster, type EncodeRequest } from './rasterEncoder';

self.onmessage = async ( event: MessageEvent< EncodeRequest > ) => {
	try {
		const result = await encodeRaster( event.data, ( width, height ) => new OffscreenCanvas( width, height ) );
		self.postMessage( { result } );
	} catch ( error ) {
		self.postMessage( { error: error instanceof Error ? error.message : String( error ) } );
	}
};
