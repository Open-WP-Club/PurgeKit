import { createRoot } from '@wordpress/element';
import App from './App';
import './style.css';

const root = document.getElementById( 'purgekit-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
