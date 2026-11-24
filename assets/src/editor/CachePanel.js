import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { flush, inspectUrl, purgeLayerUrl } from '../api';

const NOTICE = { ok: 'success', stale: 'warning', different: 'info', error: 'error' };

/**
 * Which cache layers hold the page being edited, with a purge button for each. Nothing is requested until
 * the button is pressed: a check fetches the page twice, which is not something to do on every editor load.
 */
export default function CachePanel( { url, published } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ note, setNote ] = useState( null );

	if ( ! published || ! url ) {
		return <p>{ __( 'Publish this page to check which caches hold it.', 'purgekit' ) }</p>;
	}

	const check = async () => {
		setBusy( 'check' );
		setNote( null );
		try {
			setResult( await inspectUrl( url ) );
		} catch ( e ) {
			setResult( null );
			setNote( { status: 'error', text: e.message } );
		}
		setBusy( '' );
	};

	const purge = async ( layer ) => {
		setBusy( layer || 'all' );
		setNote( null );
		try {
			const r = layer ? await purgeLayerUrl( layer, url ) : await flush( [ url ] );
			setNote( r.status === 'ok' ? { status: 'success', text: __( 'Purged. Check again to confirm.', 'purgekit' ) } : { status: 'warning', text: r.message || r.status } );
		} catch ( e ) {
			setNote( { status: 'error', text: e.message } );
		}
		setBusy( '' );
	};

	return (
		<div className="purgekit-editor-panel">
			<p>{ __( 'See which cache serves this page to visitors.', 'purgekit' ) }</p>
			<Button variant="secondary" isBusy={ busy === 'check' } disabled={ !! busy } onClick={ check }>
				{ result ? __( 'Check again', 'purgekit' ) : __( 'Check caches', 'purgekit' ) }
			</Button>{ ' ' }
			<Button variant="tertiary" isBusy={ busy === 'all' } disabled={ !! busy } onClick={ () => purge( '' ) }>
				{ __( 'Purge this page', 'purgekit' ) }
			</Button>
			{ result && (
				<>
					<Notice status={ NOTICE[ result.verdict ] || 'info' } isDismissible={ false }>{ result.message }</Notice>
					{ result.served_by.length > 0 && (
						<ul>
							{ result.served_by.map( ( l ) => (
								<li key={ l.layer }>
									{ l.evidence }{ ' ' }
									<Button variant="link" disabled={ !! busy } isBusy={ busy === l.layer } onClick={ () => purge( l.layer ) }>
										{ __( 'Purge', 'purgekit' ) }
									</Button>
								</li>
							) ) }
						</ul>
					) }
				</>
			) }
			{ note && <Notice status={ note.status } isDismissible={ false }>{ note.text }</Notice> }
		</div>
	);
}
