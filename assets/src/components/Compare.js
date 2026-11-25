import { useEffect, useState } from '@wordpress/element';
import { Button, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getCompare, runProbe } from '../api';
import { ago, Badge, Group } from '../ui';
import { Meter, Spark } from './Layers';

const RANGES = [
	{ value: '7', label: __( 'Last 7 days', 'purgekit' ) },
	{ value: '30', label: __( 'Last 30 days', 'purgekit' ) },
	{ value: '90', label: __( 'Last 90 days', 'purgekit' ) },
];

const STATE = {
	active: { label: __( 'Active', 'purgekit' ), tone: 'ok' },
	off: { label: __( 'Switched off', 'purgekit' ), tone: 'muted' },
	'not-detected': { label: __( 'Not detected', 'purgekit' ), tone: 'muted' },
};

const VERDICT = {
	cached: { label: __( 'Cached', 'purgekit' ), tone: 'ok' },
	blocked: { label: __( 'Not cached', 'purgekit' ), tone: 'bad' },
	uncertain: { label: __( 'Not answered by a cache', 'purgekit' ), tone: 'warn' },
	error: { label: __( 'Could not be fetched', 'purgekit' ), tone: 'muted' },
};

/** What is missing from the stack, judged from which groups have an active layer. */
export const findGaps = ( rows ) => {
	const active = rows.filter( ( r ) => r.state === 'active' );
	const has = ( group ) => active.some( ( r ) => r.tier_label === group );
	const gaps = [];
	if ( ! has( 'CDN / edge' ) ) {
		gaps.push( { tone: 'info', text: __( 'No CDN or edge cache is active. Every visitor is served from your own server, however far away.', 'purgekit' ) } );
	}
	if ( ! has( 'Cache plugin' ) && ! has( 'Host cache' ) && ! has( 'Reverse proxy' ) ) {
		gaps.push( { tone: 'warn', text: __( 'No page cache was detected (no cache plugin, host cache or reverse proxy). Every visit runs PHP and the database.', 'purgekit' ) } );
	}
	const object = active.find( ( r ) => r.id === 'object-cache' );
	if ( object?.figures?.some( ( f ) => f.label === 'Kind' && f.value.startsWith( 'Per request' ) ) ) {
		gaps.push( { tone: 'info', text: __( 'The object cache only lasts for one page load. A persistent one (Redis or Memcached) keeps database results between visits.', 'purgekit' ) } );
	}
	active.filter( ( r ) => r.failed > 0 && r.success_rate !== null && r.success_rate < 80 ).forEach( ( r ) => {
		gaps.push( { tone: 'warn', text: sprintf( /* translators: 1: layer name, 2: percent of purges that worked */ __( '%1$s: only %2$d%% of its purges worked, so visitors may be served old copies.', 'purgekit' ), r.label, r.success_rate ) } );
	} );
	return gaps;
};

const statsCsv = ( days ) => {
	const base = window.purgekitData?.statsCsvUrl;
	return base ? `${ base }&days=${ days }` : null;
};

function Table( { rows } ) {
	return (
		<div className="purgekit-table-scroll">
			<table className="widefat striped purgekit-compare">
				<thead>
					<tr>
						<th>{ __( 'Layer', 'purgekit' ) }</th>
						<th>{ __( 'State', 'purgekit' ) }</th>
						<th>{ __( 'Purges', 'purgekit' ) }</th>
						<th>{ __( 'Failed', 'purgekit' ) }</th>
						<th>{ __( 'Success', 'purgekit' ) }</th>
						<th>{ __( 'Average', 'purgekit' ) }</th>
						<th>{ __( 'Slowest', 'purgekit' ) }</th>
						<th>{ __( 'Hit rate', 'purgekit' ) }</th>
						<th>{ __( 'Memory', 'purgekit' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( r ) => (
						<tr key={ r.id } className={ r.state === 'active' ? '' : 'is-quiet' }>
							<td>
								<strong>{ r.label }</strong>
								<br />
								<small>{ r.tier_label }</small>
							</td>
							<td><Badge tone={ STATE[ r.state ]?.tone || 'muted' }>{ STATE[ r.state ]?.label || r.state }</Badge></td>
							<td>{ r.purges }</td>
							<td>{ r.failed > 0 ? <strong className="purgekit-bad">{ r.failed }</strong> : r.failed }</td>
							<td>{ r.success_rate === null ? '–' : `${ r.success_rate }%` }</td>
							<td>{ r.avg_ms ? `${ r.avg_ms } ms` : '–' }</td>
							<td>{ r.slowest_ms ? `${ r.slowest_ms } ms` : '–' }</td>
							<td>{ r.hit_rate === null ? '–' : <>{ `${ r.hit_rate }%` }<Meter percent={ r.hit_rate } kind="quality" label={ `${ r.label } ${ __( 'hit rate', 'purgekit' ) }` } /></> }</td>
							<td>{ r.memory === null ? '–' : <>{ `${ r.memory }%` }<Meter percent={ r.memory } kind="usage" label={ `${ r.label } ${ __( 'memory', 'purgekit' ) }` } /></> }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/** Bars of the same figure for every layer that has it, so the layers can be read against each other. */
function Bars( { rows, field, kind, title } ) {
	const list = rows.filter( ( r ) => r[ field ] !== null && r[ field ] !== undefined );
	if ( list.length === 0 ) {
		return null;
	}
	return (
		<div>
			<h4>{ title }</h4>
			<ul className="purgekit-impact__list">
				{ list.map( ( r ) => (
					<li key={ r.id }>
						<span className="purgekit-impact__name">{ r.label }</span>
						<Meter percent={ r[ field ] } kind={ kind } label={ `${ r.label } ${ title }` } />
						<span className="purgekit-layer__when">{ `${ r[ field ] }%` }</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

function Pages( { data, onRun, running, goTo } ) {
	const probe = data.probe;
	return (
		<Group title={ __( 'Pages of this site', 'purgekit' ) }>
			<p className="purgekit-setting__help">
				{ __( 'PurgeKit fetches a few of your pages like a visitor does (twice, so the first visit can fill a cache) and notes whether a cache answered the second one. The home page, the newest posts and pages, and the shop page are checked.', 'purgekit' ) }
			</p>
			<div className="purgekit-layers__bar">
				<Button variant="secondary" isBusy={ running } disabled={ running } onClick={ onRun }>
					{ running ? __( 'Checking… this can take a minute', 'purgekit' ) : __( 'Check now', 'purgekit' ) }
				</Button>
				{ probe && <span className="purgekit-layer__when">{ sprintf( /* translators: %s: how long ago */ __( 'Last checked %s', 'purgekit' ), ago( probe.at ) ) }</span> }
				{ ! data.probe_enabled && (
					<span className="purgekit-layer__when">
						{ __( 'The hourly check is switched off.', 'purgekit' ) }{ ' ' }
						<Button variant="link" onClick={ () => goTo( 'settings:alerts' ) }>{ __( 'Switch it on', 'purgekit' ) }</Button>
					</span>
				) }
			</div>
			{ ! probe && <p>{ __( 'Nothing was checked yet. Press Check now.', 'purgekit' ) }</p> }
			{ probe && (
				<>
					<div className="purgekit-compare__summary">
						<strong className="purgekit-tile__number">{ probe.total ? `${ probe.percent }%` : '–' }</strong>
						<span>{ sprintf( /* translators: 1: cached pages, 2: checked pages */ __( '%1$d of %2$d pages answered by a cache', 'purgekit' ), probe.cached, probe.total ) }</span>
						<Spark points={ data.probe_series } label={ __( 'Pages answered from a cache', 'purgekit' ) } fixed />
					</div>
					<ul className="purgekit-layer-list">
						{ probe.pages.map( ( p ) => (
							<li key={ p.url } className="purgekit-layer">
								<div className="purgekit-layer__main">
									<div className="purgekit-layer__name">
										<Badge tone={ VERDICT[ p.verdict ]?.tone || 'muted' }>{ VERDICT[ p.verdict ]?.label || p.verdict }</Badge>
										<a href={ p.url } target="_blank" rel="noreferrer">{ p.url }</a>
									</div>
									{ p.evidence?.length > 0 && <div className="purgekit-layer__when">{ p.evidence.join( ' · ' ) }</div> }
									{ p.verdict !== 'cached' && p.reasons?.map( ( reason ) => <div key={ reason } className="purgekit-layer__msg">{ reason }</div> ) }
									{ p.verdict !== 'cached' && ! p.reasons?.length && p.message && <div className="purgekit-layer__msg">{ p.message }</div> }
								</div>
							</li>
						) ) }
					</ul>
				</>
			) }
		</Group>
	);
}

export default function Compare( { goTo = () => {} } ) {
	const [ days, setDays ] = useState( '30' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ running, setRunning ] = useState( false );

	useEffect( () => {
		setError( '' );
		getCompare( Number( days ) ).then( setData ).catch( ( e ) => setError( e.message ) );
	}, [ days ] );

	const run = async () => {
		setRunning( true );
		try {
			const r = await runProbe();
			setData( ( d ) => ( { ...d, probe: r.probe, probe_series: r.probe_series } ) );
		} catch ( e ) {
			setError( e.message );
		}
		setRunning( false );
	};

	if ( error && ! data ) {
		return <p className="purgekit-note is-bad" role="alert">{ error }</p>;
	}
	if ( ! data ) {
		return <p className="purgekit-layer__when" role="status">{ __( 'Loading…', 'purgekit' ) }</p>;
	}
	const gaps = findGaps( data.layers );
	const link = statsCsv( days );
	return (
		<div>
			{ error && <p className="purgekit-note is-bad" role="alert">{ error }</p> }
			<div className="purgekit-layers__bar">
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Period', 'purgekit' ) } value={ days } options={ RANGES } onChange={ setDays } />
				{ link && <a href={ link }>{ __( 'Export CSV', 'purgekit' ) }</a> }
			</div>

			{ gaps.length > 0 && (
				<Group title={ __( 'Where there is no cache', 'purgekit' ) }>
					<ul className="purgekit-compare__gaps">
						{ gaps.map( ( g ) => (
							<li key={ g.text }>
								<Badge tone={ g.tone === 'warn' ? 'warn' : 'muted' }>{ g.tone === 'warn' ? __( 'Gap', 'purgekit' ) : __( 'Note', 'purgekit' ) }</Badge> { g.text }
							</li>
						) ) }
					</ul>
				</Group>
			) }

			<Group title={ __( 'Layers side by side', 'purgekit' ) }>
				<Table rows={ data.layers } />
				<div className="purgekit-layerbars">
					<Bars rows={ data.layers } field="hit_rate" kind="quality" title={ __( 'Hit rate', 'purgekit' ) } />
					<Bars rows={ data.layers } field="memory" kind="usage" title={ __( 'Memory used', 'purgekit' ) } />
				</div>
				<p className="purgekit-setting__help">
					{ __( 'Purge figures come from the history of the period. Hit rate and memory are live figures from the cache itself, for the layers that report them (OPcache, APCu, Redis, Memcached). A CDN keeps its own statistics, which PurgeKit does not read.', 'purgekit' ) }
				</p>
			</Group>

			<Pages data={ data } onRun={ run } running={ running } goTo={ goTo } />
		</div>
	);
}
