import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getLayers, purgeLayer, setLayerEnabled, testLayer } from '../api';

const STATUS_LABEL = {
	ok: __( 'OK', 'purgekit' ),
	failed: __( 'Failed', 'purgekit' ),
	partial: __( 'Partial', 'purgekit' ),
};

const when = ( at ) => {
	const d = new Date( at.replace( ' ', 'T' ) + 'Z' ); // History stores GMT.
	return Number.isNaN( d.getTime() ) ? at : d.toLocaleString();
};

export default function Layers() {
	const [ layers, setLayers ] = useState( [] );
	const [ busy, setBusy ] = useState( {} );
	const [ results, setResults ] = useState( {} );

	const load = () => getLayers().then( setLayers );
	useEffect( () => {
		load();
	}, [] );

	const toggle = async ( id, enabled ) => {
		setBusy( ( b ) => ( { ...b, [ id ]: 'toggle' } ) );
		try {
			setLayers( await setLayerEnabled( id, enabled ) );
		} catch ( e ) {
			setResults( ( r ) => ( { ...r, [ id ]: { kind: 'toggle', status: 'failed', message: e.message } } ) );
		}
		setBusy( ( b ) => ( { ...b, [ id ]: null } ) );
	};

	const act = async ( id, kind ) => {
		setBusy( ( b ) => ( { ...b, [ id ]: kind } ) );
		try {
			const res = kind === 'test' ? await testLayer( id ) : await purgeLayer( id );
			// A purge returns a whole run; this layer's own line is the one to show.
			const line = kind === 'purge' ? res.results?.find( ( r ) => r.layer === id ) || { status: res.status, message: '' } : res;
			setResults( ( r ) => ( { ...r, [ id ]: { kind, ...line } } ) );
			if ( kind === 'purge' ) {
				load();
			}
		} catch ( e ) {
			setResults( ( r ) => ( { ...r, [ id ]: { kind, status: 'failed', message: e.message } } ) );
		}
		setBusy( ( b ) => ( { ...b, [ id ]: null } ) );
	};

	// Closest to the visitor first, which is the order a request travels.
	const shown = [ ...layers ].sort( ( a, b ) => b.tier - a.tier );

	return (
		<div>
			<p>
				{ __( 'A visitor’s request passes the layers from top to bottom; the first one holding a copy answers. PurgeKit purges from the bottom up (1, 2, 3…), so a layer never refills from one that still holds the old copy.', 'purgekit' ) }
			</p>
			<table className="widefat striped">
				<thead>
					<tr>
						<th>{ __( 'Purge order', 'purgekit' ) }</th>
						<th>{ __( 'Layer', 'purgekit' ) }</th>
						<th>{ __( 'State', 'purgekit' ) }</th>
						<th>{ __( 'Last purge', 'purgekit' ) }</th>
						<th>{ __( 'Actions', 'purgekit' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ shown.map( ( l ) => {
						const r = results[ l.id ];
						const working = busy[ l.id ];
						return (
							<tr key={ l.id }>
								<td>{ l.order ?? '—' }</td>
								<td>
									{ l.label }
									<br />
									<small>{ l.tier_label }</small>
								</td>
								<td>
									{ l.detected ? __( 'Detected', 'purgekit' ) : __( 'Not detected', 'purgekit' ) }
									{ l.enabled === false && <div>{ __( 'Switched off', 'purgekit' ) }</div> }
									{ l.paused && l.enabled !== false && <div title={ l.paused }>{ __( 'Paused (see Settings)', 'purgekit' ) }</div> }
								</td>
								<td>
									{ l.last ? (
										<span>
											<strong>{ STATUS_LABEL[ l.last.status ] || l.last.status }</strong>
											{ ' · ' + when( l.last.at ) }
											{ l.last.message ? ` · ${ l.last.message }` : '' }
										</span>
									) : (
										__( 'No purge recorded yet', 'purgekit' )
									) }
								</td>
								<td>
									{ l.detected && (
										<>
											<Button variant="secondary" size="small" isBusy={ working === 'test' } disabled={ !! working } onClick={ () => act( l.id, 'test' ) }>
												{ __( 'Test connection', 'purgekit' ) }
											</Button>{ ' ' }
											<Button variant="tertiary" size="small" isBusy={ working === 'purge' } disabled={ !! working || l.enabled === false } onClick={ () => act( l.id, 'purge' ) }>
												{ __( 'Purge this layer', 'purgekit' ) }
											</Button>{ ' ' }
										</>
									) }
									{ l.detected && (
										<Button variant="tertiary" size="small" isBusy={ working === 'toggle' } disabled={ !! working } onClick={ () => toggle( l.id, l.enabled === false ) }>
											{ l.enabled === false ? __( 'Switch on', 'purgekit' ) : __( 'Switch off', 'purgekit' ) }
										</Button>
									) }
									{ r && ! working && (
										<div>
											{ r.kind === 'purge' ? __( 'Purge: ', 'purgekit' ) : '' }
											{ r.status === 'ok' ? r.message || __( 'OK', 'purgekit' ) : r.message || r.status }
										</div>
									) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
