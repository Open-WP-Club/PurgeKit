import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getLayers, purgeLayer, setLayerEnabled, testLayer } from '../api';
import { ago, Badge, STATUS_LABEL, toDate, TONE } from '../ui';

const STALE_DAYS = 7;

/** One line such as "Last purge: OK · 2 hours ago · message". */
function Outcome( { label, result, empty, staleAfterDays } ) {
	const age = result?.at ? ( Date.now() - toDate( result.at ).getTime() ) / 86400000 : 0;
	const stale = staleAfterDays && age > staleAfterDays;
	return (
		<div className="purgekit-layer__line">
			<span className="purgekit-layer__label">{ label }</span>
			{ result ? (
				<>
					{ result.warn && result.status === 'ok' ? (
						<Badge tone="warn">{ __( 'Warning', 'purgekit' ) }</Badge>
					) : (
						<Badge tone={ TONE[ result.status ] || 'muted' }>{ STATUS_LABEL[ result.status ] || result.status }</Badge>
					) }
					{ result.at ? <span className="purgekit-layer__when">{ ago( result.at ) }</span> : null }
					{ stale && <Badge tone="warn">{ __( 'Old, check again', 'purgekit' ) }</Badge> }
					{ result.message ? <span className="purgekit-layer__msg">{ result.message }</span> : null }
				</>
			) : (
				<span className="purgekit-layer__when">{ empty }</span>
			) }
		</div>
	);
}

function Layer( { l, check, working, onAct, onToggle } ) {
	const off = l.enabled === false;
	return (
		<li className="purgekit-layer" id={ `purgekit-layer-${ l.id }` }>
			<span className="purgekit-layer__order" title={ __( 'Purge order', 'purgekit' ) }>{ l.order }</span>
			<div className="purgekit-layer__main">
				<div className="purgekit-layer__name">
					<strong>{ l.label }</strong>
					{ off && <Badge tone="muted">{ __( 'Switched off', 'purgekit' ) }</Badge> }
					{ ! off && l.paused && (
						<span title={ l.paused }>
							<Badge tone="warn">{ __( 'Paused (see Settings)', 'purgekit' ) }</Badge>
						</span>
					) }
				</div>
				<Outcome label={ __( 'Last purge', 'purgekit' ) } result={ l.last } empty={ __( 'No purge recorded yet', 'purgekit' ) } />
				<Outcome label={ __( 'Last check', 'purgekit' ) } result={ check } empty={ __( 'Not checked yet', 'purgekit' ) } staleAfterDays={ STALE_DAYS } />
			</div>
			<div className="purgekit-layer__actions">
				<Button variant="secondary" size="small" isBusy={ working === 'test' } disabled={ !! working } onClick={ () => onAct( l.id, 'test' ) }>
					{ __( 'Test connection', 'purgekit' ) }
				</Button>
				<Button variant="tertiary" size="small" isBusy={ working === 'purge' } disabled={ !! working || off } onClick={ () => onAct( l.id, 'purge' ) }>
					{ __( 'Purge this layer', 'purgekit' ) }
				</Button>
				<Button variant="tertiary" size="small" isBusy={ working === 'toggle' } disabled={ !! working } onClick={ () => onToggle( l.id, off ) }>
					{ off ? __( 'Switch on', 'purgekit' ) : __( 'Switch off', 'purgekit' ) }
				</Button>
			</div>
		</li>
	);
}

export default function Layers() {
	const [ layers, setLayers ] = useState( [] );
	const [ busy, setBusy ] = useState( {} );
	const [ checks, setChecks ] = useState( {} );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ checkingAll, setCheckingAll ] = useState( false );

	const load = async () => {
		setRefreshing( true );
		try {
			setLayers( await getLayers() );
		} finally {
			setRefreshing( false );
		}
	};
	useEffect( () => {
		load();
	}, [] );

	const act = async ( id, kind ) => {
		setBusy( ( b ) => ( { ...b, [ id ]: kind } ) );
		try {
			if ( kind === 'test' ) {
				const res = await testLayer( id );
				setChecks( ( c ) => ( { ...c, [ id ]: { status: res.status, message: res.message, at: res.at || Math.floor( Date.now() / 1000 ) } } ) );
			} else {
				await purgeLayer( id );
				await load(); // The purge is in the history now, so the last-purge line updates.
			}
		} catch ( e ) {
			setChecks( ( c ) => ( { ...c, [ id ]: { status: 'failed', message: e.message, at: Math.floor( Date.now() / 1000 ) } } ) );
		}
		setBusy( ( b ) => ( { ...b, [ id ]: null } ) );
	};

	const toggle = async ( id, enabled ) => {
		setBusy( ( b ) => ( { ...b, [ id ]: 'toggle' } ) );
		try {
			setLayers( await setLayerEnabled( id, enabled ) );
		} catch ( e ) {
			setChecks( ( c ) => ( { ...c, [ id ]: { status: 'failed', message: e.message, at: Math.floor( Date.now() / 1000 ) } } ) );
		}
		setBusy( ( b ) => ( { ...b, [ id ]: null } ) );
	};

	const detected = layers.filter( ( l ) => l.detected );
	const missing = layers.filter( ( l ) => ! l.detected );

	const checkAll = async () => {
		setCheckingAll( true );
		await Promise.all( detected.map( ( l ) => act( l.id, 'test' ) ) );
		setCheckingAll( false );
	};

	// Groups run from the visitor inwards (CDN first), the order a request travels.
	const groups = [];
	[ ...detected ].sort( ( a, b ) => b.tier - a.tier ).forEach( ( l ) => {
		const name = l.tier_label || __( 'Other', 'purgekit' );
		let g = groups.find( ( x ) => x.name === name );
		if ( ! g ) {
			g = { name, layers: [] };
			groups.push( g );
		}
		g.layers.push( l );
	} );

	return (
		<div className="purgekit-layers">
			<p>
				{ __( 'A visitor’s request passes the layers from top to bottom; the first one holding a copy answers. PurgeKit purges from the bottom up (1, 2, 3…), so a layer never refills from one that still holds the old copy.', 'purgekit' ) }
			</p>
			<div className="purgekit-layers__bar">
				<Button variant="secondary" isBusy={ checkingAll } disabled={ checkingAll || detected.length === 0 } onClick={ checkAll }>
					{ __( 'Check all connections', 'purgekit' ) }
				</Button>
				<Button variant="tertiary" isBusy={ refreshing } disabled={ refreshing } onClick={ load }>
					{ __( 'Refresh', 'purgekit' ) }
				</Button>
				<span className="purgekit-layer__when">
					{ __( 'Detection runs when this page loads and is local only. A connection is checked when you press a button, not by itself.', 'purgekit' ) }
				</span>
			</div>
			{ groups.map( ( g ) => (
				<section key={ g.name } className="purgekit-layers__group">
					<h3>{ g.name }</h3>
					<ul className="purgekit-layer-list">
						{ g.layers.map( ( l ) => (
							<Layer key={ l.id } l={ l } check={ checks[ l.id ] || l.checked } working={ busy[ l.id ] } onAct={ act } onToggle={ toggle } />
						) ) }
					</ul>
				</section>
			) ) }
			{ detected.length === 0 && layers.length > 0 && <p>{ __( 'No cache layer was detected yet. Set one up in Settings.', 'purgekit' ) }</p> }
			{ missing.length > 0 && (
				<details className="purgekit-layers__missing">
					<summary>{ sprintf( /* translators: %d: number of layers */ __( 'Not detected (%d)', 'purgekit' ), missing.length ) }</summary>
					<ul>
						{ missing.map( ( l ) => (
							<li key={ l.id }>
								{ l.label } <small>{ l.tier_label }</small>
							</li>
						) ) }
					</ul>
				</details>
			) }
		</div>
	);
}
