import { createRoot } from '@wordpress/element';
import { browserCompressor } from '../../infrastructure/browserCompressor';
import { App } from './App';

const rootEl = document.getElementById( 'kilobyte-io-root' );
if ( rootEl ) {
	browserCompressor.setWorkerUrl( window.KilobyteIOAdmin?.workerUrl ?? '' );
	const root = createRoot( rootEl );
	root.render( <App /> );
}
