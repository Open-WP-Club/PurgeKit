import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { flush, getLayers, getStatus, purgeLayer, resetStorm, retryFlush } from '../api';
import { ago, Badge, Live, sourceLabel, StatusBadge, triggerParts, useMessage } from '../ui';
import Layers from './Layers';
import Preview from './Preview';
import FlushResult from './FlushResult';
import ConfirmFlush from './ConfirmFlush';

const MODE_LABEL = {
	notify: __( 'Notify me', 'purgekit' ),
	auto: __( 'Automatic', 'purgekit' ),
	scheduled: __( 'Scheduled', 'purgekit' ),
};

function Tile( { title, children, hint, extra } ) {
	return (
		<div className="purgekit-tile">
			<span className="purgekit-tile__title">{ title }</span>
			<div className="purgekit-tile__value">{ children }</div>
			{ hint && <span className="purgekit-tile__hint">{ hint }</span> }
			{ extra }
		</div>
	);
}

/** Seven small bars, one per day; red where something went wrong. */
function Week( { week } ) {
	const max = Math.max( 1, ...week.map( ( d ) => d.total ) );
	const text = week.map( ( d ) => `${ d.date }: ${ d.total }` ).join( ', ' );
	return (
		<span className="purgekit-bars" role="img" aria-label={ sprintf( /* translators: %s: counts per day */ __( 'Flushes per day, last 7 days. %s', 'purgekit' ), text ) }>
			{ week.map( ( d ) => (
				<span key={ d.date } className={ `purgekit-bars__bar${ d.problems > 0 ? ' has-problems' : '' }` } style={ { height: `${ Math.max( 8, ( d.total / max ) * 100 ) }%` } } title={ `${ d.date }: ${ d.total }` } />
			) ) }
		</span>
	);
}

/** Under the layer count: which layers failed (with a retry), which are off but failed, and which warn. */
function LayerNotes( { status, onShow, onRetry, retrying } ) {
	const failing = status.failing_layers || [];
	const off = status.off_failing || [];
	const warn = status.warning_layers || [];
	if ( ! failing.length && ! off.length && ! warn.length ) {
		return null;
	}
	return (
		<ul className="purgekit-tile__notes">
			{ failing.map( ( l ) => (
				<li key={ l.id }>
					<Badge tone="bad">{ l.label }</Badge>
					<Button variant="link" onClick={ () => onShow( l.id ) } title={ l.message }>{ __( 'Show', 'purgekit' ) }</Button>
					<Button variant="link" isBusy={ retrying === l.id } disabled={ !! retrying } onClick={ () => onRetry( l.id ) }>{ __( 'Retry', 'purgekit' ) }</Button>
				</li>
			) ) }
			{ warn.map( ( l ) => (
				<li key={ 'w' + l.id }>
					<Badge tone="warn">{ l.label }</Badge>
					<span className="purgekit-layer__when">{ l.message }</span>
				</li>
			) ) }
			{ off.map( ( l ) => (
				<li key={ 'o' + l.id }>
					<Badge tone="muted">{ l.label }</Badge>
					<span className="purgekit-layer__when">{ __( 'Switched off; its last purge failed.', 'purgekit' ) }</span>
				</li>
			) ) }
		</ul>
	);
}

export default function Dashboard( { goTo = () => {} } ) {
	const [ status, setStatus ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ labels, setLabels ] = useState( {} );
	const [ result, setResult ] = useState( null );
	const [ confirm, setConfirm ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ retrying, setRetrying ] = useState( false );
	const [ retryingLayer, setRetryingLayer ] = useState( '' );
	const [ layersEpoch, setLayersEpoch ] = useState( 0 );
	const [ message, setMessage, clear ] = useMessage();

	const load = () => getStatus().then( ( s ) => { setStatus( s ); setLoadError( '' ); } ).catch( ( e ) => setLoadError( e.message ) );
	useEffect( () => {
		load();
		const timer = setInterval( load, 10000 );
		getLayers().then( ( ls ) => setLabels( Object.fromEntries( ls.map( ( l ) => [ l.id, l.label ] ) ) ) ).catch( () => {} );
		return () => clearInterval( timer );
	}, [] );

	const run = async () => {
		setBusy( true );
		setMessage( null );
		try {
			const res = await flush();
			setResult( res );
			setConfirm( false );
			load();
		} catch ( e ) {
			setConfirm( false );
			setMessage( { status: 'error', text: e.message } );
		}
		setBusy( false );
	};

	const retry = async ( layers ) => {
		setRetrying( true );
		try {
			const res = await retryFlush( layers, result.all ? [] : result.urls );
			setResult( res );
			load();
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
		setRetrying( false );
	};

	/** Scrolls to a layer's card further down and marks it for a moment. */
	const showLayer = ( id ) => {
		const el = document.getElementById( `purgekit-layer-${ id }` );
		if ( ! el ) {
			return;
		}
		el.scrollIntoView?.( { behavior: 'smooth', block: 'center' } );
		el.classList.add( 'is-marked' );
		setTimeout( () => el.classList.remove( 'is-marked' ), 2500 );
	};

	const retryLayer = async ( id ) => {
		setRetryingLayer( id );
		try {
			await purgeLayer( id );
			await load();
			setLayersEpoch( ( n ) => n + 1 );
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
		setRetryingLayer( '' );
	};

	const resume = async () => {
		try {
			await resetStorm();
			setMessage( { status: 'success', text: __( 'Automatic flushing is back on.', 'purgekit' ) } );
			load();
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
	};

	if ( loadError && ! status ) {
		return <p className="purgekit-note is-bad" role="alert">{ loadError }</p>;
	}
	if ( ! status ) {
		return <p className="purgekit-layer__when" role="status">{ __( 'Loading…', 'purgekit' ) }</p>;
	}

	const last = status.last_run;
	const day = status.stats?.day || {};
	const dayTotal = Object.values( day ).reduce( ( a, b ) => a + b, 0 );
	const dayProblems = ( day.failed || 0 ) + ( day.partial || 0 );

	return (
		<>
			{ status.storm && (
				<div className="purgekit-banner is-warn" role="alert">
					<div>
						<strong>{ __( 'Flush storm', 'purgekit' ) }</strong>{ ' ' }
						{ sprintf( /* translators: 1: number of flushes, 2: minutes */ __( '%1$d full flushes in %2$d minutes. A cron job or a plugin may be flushing in a loop.', 'purgekit' ), status.storm.count, Math.max( 1, Math.round( status.storm.window / 60 ) ) ) }{ ' ' }
						{ status.storm.paused ? __( 'Automatic flushing is paused and changes stay queued.', 'purgekit' ) : __( 'Automatic flushing is still on; you can pause it in Settings → Protection.', 'purgekit' ) }
					</div>
					<Button variant="secondary" onClick={ resume }>{ status.storm.paused ? __( 'Resume automatic flushing', 'purgekit' ) : __( 'Dismiss', 'purgekit' ) }</Button>
				</div>
			) }
			{ status.paused && (
				<div className="purgekit-banner">
					<div>
						<strong>{ __( 'CDN purges are paused.', 'purgekit' ) }</strong> { status.paused }
					</div>
					<Button variant="secondary" onClick={ () => goTo( 'settings:protection' ) }>{ __( 'Open Protection', 'purgekit' ) }</Button>
				</div>
			) }

			<div className="purgekit-tiles">
				<Tile title={ __( 'Last flush', 'purgekit' ) } hint={ last ? `${ sourceLabel( last.source ) } · ${ ago( last.created_at ) }` : undefined }>
					{ last ? <StatusBadge status={ last.status } /> : <span className="purgekit-layer__when">{ __( 'None yet', 'purgekit' ) }</span> }
				</Tile>
				<Tile title={ __( 'Cache layers', 'purgekit' ) } hint={ status.layers_failing > 0 ? sprintf( /* translators: %d: number of layers */ __( '%d failed their last purge', 'purgekit' ), status.layers_failing ) + ( status.failing_layers?.length ? ': ' + status.failing_layers.map( ( l ) => l.label ).join( ', ' ) : '' ) : __( 'Active on this site', 'purgekit' ) } extra={ <LayerNotes status={ status } onShow={ showLayer } onRetry={ retryLayer } retrying={ retryingLayer } /> }>
					<strong className="purgekit-tile__number">{ status.layers_active }</strong>
					{ status.layers_failing > 0 && <Badge tone="bad">{ __( 'Needs attention', 'purgekit' ) }</Badge> }
				</Tile>
				<Tile title={ __( 'Last 24 hours', 'purgekit' ) } hint={ dayProblems > 0 ? sprintf( /* translators: %d: number of flushes */ __( '%d with problems', 'purgekit' ), dayProblems ) : __( 'No problems', 'purgekit' ) }>
					<strong className="purgekit-tile__number">{ dayTotal }</strong>
					{ status.stats?.week && <Week week={ status.stats.week } /> }
				</Tile>
				<Tile
					title={ __( 'Waiting', 'purgekit' ) }
					hint={ sprintf( /* translators: %s: mode */ __( 'Mode: %s', 'purgekit' ), MODE_LABEL[ status.mode ] || status.mode ) + ( status.delayed > 0 ? ' · ' + sprintf( /* translators: %d: number of postponed steps */ __( '%d postponed', 'purgekit' ), status.delayed ) : '' ) }
				>
					<strong className="purgekit-tile__number">{ status.pending }</strong>
					<span className="purgekit-layer__when">{ __( 'pending changes', 'purgekit' ) }</span>
				</Tile>
			</div>

			{ status.warming && <section className="purgekit-card" aria-live="polite">
				<h2>{ __( 'Cache warming', 'purgekit' ) }</h2>
				<p>{ sprintf( /* translators: 1: pending URLs, 2: successful URLs, 3: failed URLs, 4: retries */ __( '%1$d waiting · %2$d successful · %3$d failed · %4$d retries', 'purgekit' ), status.warming.pending, status.warming.successful, status.warming.failed, status.warming.retries ) }</p>
				{ status.warming.pending > 0 && <p>{ __( 'Next batch', 'purgekit' ) }: { new Date( status.warming.next_at * 1000 ).toLocaleString() }</p> }
				{ status.auto_due && <p>{ __( 'Automatic purge scheduled for', 'purgekit' ) }: { new Date( status.auto_due * 1000 ).toLocaleString() }</p> }
			</section> }
			<section className="purgekit-card">
				<h2>{ __( 'Flush', 'purgekit' ) }</h2>
				<p>{ __( 'See what the queued changes would purge first. Purging everything asks for a confirmation.', 'purgekit' ) }</p>
				<Preview
					extra={
						<Button variant="secondary" isDestructive onClick={ () => setConfirm( true ) } disabled={ busy }>
							{ __( 'Flush now', 'purgekit' ) }
						</Button>
					}
				/>
				<Live message={ message } onClear={ clear } />
				{ result && <FlushResult result={ result } labels={ labels } retrying={ retrying } onRetry={ retry } onDismiss={ () => setResult( null ) } /> }
			</section>
			{ confirm && <ConfirmFlush busy={ busy } onConfirm={ run } onCancel={ () => setConfirm( false ) } /> }

			<section className="purgekit-card">
				<h2>{ __( 'Recent flushes', 'purgekit' ) }</h2>
				{ status.recent?.length ? (
					<ul className="purgekit-recent">
						{ status.recent.map( ( row ) => (
							<li key={ row.id } className="purgekit-layer__line">
								<StatusBadge status={ row.status } />
								<strong>{ sourceLabel( row.source ) }</strong>
								<span className="purgekit-layer__when">{ ago( row.created_at ) }</span>
								<span className="purgekit-layer__msg">{ triggerParts( row ).join( ' · ' ) }</span>
							</li>
						) ) }
					</ul>
				) : (
					<p className="purgekit-layer__when">{ __( 'Nothing was flushed yet. Edit a post, or press Flush now, and it shows up here.', 'purgekit' ) }</p>
				) }
				<Button variant="link" onClick={ () => goTo( 'history' ) }>{ __( 'Open the full history', 'purgekit' ) }</Button>
			</section>

			<section className="purgekit-card">
				<h2>{ __( 'Cache layers', 'purgekit' ) }</h2>
				<Layers key={ layersEpoch } />
			</section>
		</>
	);
}
