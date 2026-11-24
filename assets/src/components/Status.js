import { useEffect, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getStatus, flush } from '../api';
import Preview from './Preview';

export default function Status() {
	const [ status, setStatus ] = useState( null );
	const [ message, setMessage ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () => getStatus().then( setStatus ).catch( ( e ) => setMessage( { error: true, text: e.message } ) );
	useEffect( () => {
		load();
	}, [] );

	const run = async () => {
		setBusy( true );
		setMessage( null );
		try {
			const res = await flush();
			setMessage( { error: res.status === 'failed', text: sprintf( /* translators: %s: result status */ __( 'Flush %s', 'purgekit' ), res.status ) } );
			load();
		} catch ( e ) {
			setMessage( { error: true, text: e.message } );
		}
		setBusy( false );
	};

	return (
		<div>
			{ status && (
				<p>
					{ sprintf( /* translators: 1: pending changes, 2: mode */ __( '%1$d pending changes. Mode: %2$s.', 'purgekit' ), status.pending, status.mode ) }
				</p>
			) }
			<Button variant="primary" onClick={ run } isBusy={ busy } disabled={ busy }>
				{ __( 'Flush now', 'purgekit' ) }
			</Button>{ ' ' }
			<Preview />
			<p className="description">
				{ __( 'Preview shows what the queued changes would purge. Flush now purges everything.', 'purgekit' ) }
			</p>
			{ message && (
				<Notice status={ message.error ? 'error' : 'success' } isDismissible={ false }>
					{ message.text }
				</Notice>
			) }
		</div>
	);
}
