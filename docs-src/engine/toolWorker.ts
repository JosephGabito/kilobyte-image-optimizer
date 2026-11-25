import { processStandaloneImage } from './processor';
import type { WorkerRequestMessage, WorkerResponseMessage } from './types';

self.onmessage = async ( event: MessageEvent< WorkerRequestMessage > ) => {
	const { id, request } = event.data;
	try {
		const result = await processStandaloneImage(
			request,
			( width, height ) => new OffscreenCanvas( width, height ),
			undefined,
			( stage ) => {
				const progress: WorkerResponseMessage = { id, stage };
				self.postMessage( progress );
			}
		);
		const reply: WorkerResponseMessage = { id, result };
		self.postMessage( reply );
	} catch ( error ) {
		const reply: WorkerResponseMessage = {
			id,
			error: error instanceof Error ? error.message : String( error ),
		};
		self.postMessage( reply );
	}
};
