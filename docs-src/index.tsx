import { createRoot } from '@wordpress/element';
import { LandingPage } from './LandingPage';
import './styles.css';

const rootEl = document.getElementById( 'kilobyte-landing-root' );
if ( rootEl ) {
	const root = createRoot( rootEl );
	root.render( <LandingPage /> );
}
