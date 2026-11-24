import { useState } from '@wordpress/element';
import { Button, Notice, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { inspectUrl, purgeLayerUrl } from '../api';

const NOTICE = { ok: 'success', stale: 'warning', different: 'info', error: 'error' };

export default function Inspector() {
	const [ url, setUrl ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ purged, setPurged ] = useState( '' );

	const run = async () => {
		setBusy( true );
		setError( '' );
		setPurged( '' );
		try {
			setResult( await inspectUrl( url ) );
		} catch ( e ) {
			setResult( null );
			setError( e.message );
		}
		setBusy( false );
	};

	const purge = async ( layer ) => {
		try {
			const r = await purgeLayerUrl( layer, result.url );
			setPurged( r.status === 'ok' ? __( 'Purged. Inspect again to confirm.', 'purgekit' ) : r.message || r.status );
		} catch ( e ) {
			setPurged( e.message );
		}
	};

	return (
		<div>
			<p>{ __( "Why isn't my change showing? Enter a page on this site to see which cache is serving it.", 'purgekit' ) }</p>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Page URL', 'purgekit' ) }
				type="url"
				value={ url }
				onChange={ setUrl }
				placeholder="https://example.com/page/"
			/>
			<Button variant="primary" isBusy={ busy } disabled={ busy || url === '' } onClick={ run }>
				{ __( 'Inspect', 'purgekit' ) }
			</Button>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ result && (
				<div>
					<Notice status={ NOTICE[ result.verdict ] || 'info' } isDismissible={ false }>{ result.message }</Notice>
					{ result.verdict === 'stale' && result.layer && (
						<Button variant="secondary" onClick={ () => purge( result.layer ) }>
							{ __( 'Purge this layer for this URL', 'purgekit' ) }
						</Button>
					) }
					{ purged && <p>{ purged }</p> }
					{ result.served_by.length > 0 && (
						<ul>
							{ result.served_by.map( ( l ) => (
								<li key={ l.layer }>{ l.evidence }</li>
							) ) }
						</ul>
					) }
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Header', 'purgekit' ) }</th>
								<th>{ __( 'Visitor', 'purgekit' ) } ({ result.visitor.status })</th>
								<th>{ __( 'Fresh', 'purgekit' ) } ({ result.fresh.status })</th>
							</tr>
						</thead>
						<tbody>
							{ [ ...new Set( [ ...Object.keys( result.visitor.headers || {} ), ...Object.keys( result.fresh.headers || {} ) ] ) ].map( ( h ) => (
								<tr key={ h }>
									<td><code>{ h }</code></td>
									<td>{ result.visitor.headers?.[ h ] }</td>
									<td>{ result.fresh.headers?.[ h ] }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}
