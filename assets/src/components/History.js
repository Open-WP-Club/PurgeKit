import { useEffect, useState } from '@wordpress/element';
import { Button, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getHistory, replayFlush, retryFlush } from '../api';
import { ago, Badge, Live, sourceLabel, StatusBadge, toDate, triggerParts, useMessage } from '../ui';

const STATUSES = [
	{ value: '', label: __( 'All', 'purgekit' ) },
	{ value: 'ok', label: __( 'OK', 'purgekit' ) },
	{ value: 'partial', label: __( 'Partial', 'purgekit' ) },
	{ value: 'failed', label: __( 'Failed', 'purgekit' ) },
	{ value: 'skipped', label: __( 'Skipped', 'purgekit' ) },
];

/** The export URL carries a nonce, generated server-side. The status filter is appended here. */
const csvLink = ( status ) => {
	const base = window.purgekitData?.csvUrl;
	if ( ! base ) {
		return null;
	}
	return status ? `${ base }&status=${ encodeURIComponent( status ) }` : base;
};

const VERIFY_LABELS = {
	verified: __( 'Verified', 'purgekit' ),
	stale: __( 'Stale copy', 'purgekit' ),
	unchecked: __( 'Could not check', 'purgekit' ),
};
const VERIFY_TONE = { verified: 'ok', stale: 'bad', unchecked: 'warn' };

function VerificationNote( { v } ) {
	return (
		<div className="purgekit-layer__line">
			<span className="purgekit-layer__label">{ __( 'Check', 'purgekit' ) }</span>
			<Badge tone={ VERIFY_TONE[ v.status ] || 'muted' }>{ VERIFY_LABELS[ v.status ] || v.status }</Badge>
			{ v.status !== 'verified' && v.message && <span className="purgekit-layer__msg">{ v.message }</span> }
		</div>
	);
}

function Row( { row, busy, onReplay, onRetry } ) {
	const failed = row.results.filter( ( r ) => r.status === 'failed' );
	const who = triggerParts( row );
	const exact = toDate( row.created_at );
	const shortened = ! row.all && ( row.urls_total ?? row.urls?.length ?? 0 ) > ( row.urls?.length ?? 0 );
	const repeatable = row.status !== 'skipped' && ( row.all || ( row.urls?.length ?? 0 ) > 0 );
	return (
		<li className="purgekit-layer">
			<div className="purgekit-layer__main">
				<div className="purgekit-layer__name">
					<StatusBadge status={ row.status } />
					<strong>{ sourceLabel( row.source ) }</strong>
					<span className="purgekit-layer__when" title={ Number.isNaN( exact.getTime() ) ? '' : exact.toLocaleString() }>
						{ ago( row.created_at ) || row.created_at }
					</span>
					<span className="purgekit-layer__when">
						{ row.all ? __( 'everything', 'purgekit' ) : sprintf( /* translators: %d: number of URLs */ __( '%d URLs', 'purgekit' ), row.urls_total ?? row.urls?.length ?? 0 ) }
					</span>
					{ row.duration_ms > 0 && <span className="purgekit-layer__when">{ `${ row.duration_ms } ms` }</span> }
				</div>
				{ who.length > 0 && (
					<div className="purgekit-layer__line">
						<span className="purgekit-layer__label">{ __( 'Started by', 'purgekit' ) }</span>
						<span className="purgekit-layer__msg">{ who.join( ' · ' ) }</span>
					</div>
				) }
				{ failed.map( ( r ) => (
					<div key={ r.layer } className="purgekit-layer__line">
						<span className="purgekit-layer__label">{ r.layer }</span>
						<Badge tone="bad">{ __( 'Failed', 'purgekit' ) }</Badge>
						<span className="purgekit-layer__msg">{ r.message }</span>
					</div>
				) ) }
				{ row.verification && <VerificationNote v={ row.verification } /> }
			</div>
			{ repeatable && (
				<div className="purgekit-history__actions">
					{ failed.length > 0 && (
						<Button variant="secondary" size="small" disabled={ !! busy } isBusy={ busy === 'retry' } onClick={ () => onRetry( row, failed.map( ( r ) => r.layer ) ) }>
							{ __( 'Retry failed layers', 'purgekit' ) }
						</Button>
					) }
					<Button
						variant="tertiary"
						size="small"
						disabled={ !! busy || shortened }
						isBusy={ busy === 'replay' }
						title={ shortened ? __( 'The history only kept part of this URL list.', 'purgekit' ) : undefined }
						onClick={ () => onReplay( row ) }
					>
						{ __( 'Run again', 'purgekit' ) }
					</Button>
				</div>
			) }
		</li>
	);
}

export default function History() {
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ working, setWorking ] = useState( {} );
	const [ message, setMessage, clear ] = useMessage();
	const load = () => getHistory( page, status ).then( setData ).catch( ( e ) => setError( e.message ) );
	useEffect( () => {
		setError( '' );
		load();
	}, [ page, status ] );

	const act = async ( row, kind, fn ) => {
		setWorking( ( w ) => ( { ...w, [ row.id ]: kind } ) );
		setMessage( null );
		try {
			const res = await fn();
			setMessage( { status: res.status === 'failed' ? 'error' : 'success', text: sprintf( /* translators: %s: result status */ __( 'Flush %s.', 'purgekit' ), res.status ) } );
			await load();
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
		setWorking( ( w ) => ( { ...w, [ row.id ]: null } ) );
	};
	const replay = ( row ) => act( row, 'replay', () => replayFlush( row.id ) );
	const retry = ( row, layers ) => act( row, 'retry', () => retryFlush( layers, row.all ? [] : row.urls ) );

	if ( error ) {
		return <p className="purgekit-note is-bad" role="alert">{ error }</p>;
	}
	if ( ! data ) {
		return <p className="purgekit-layer__when" role="status">{ __( 'Loading…', 'purgekit' ) }</p>;
	}
	const link = csvLink( status );
	return (
		<div>
			<div className="purgekit-layers__bar">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Show', 'purgekit' ) }
					value={ status }
					options={ STATUSES }
					onChange={ ( v ) => {
						setPage( 1 );
						setStatus( v );
					} }
				/>
				{ link && <a href={ link }>{ __( 'Export CSV', 'purgekit' ) }</a> }
			</div>
			<Live message={ message } onClear={ clear } />
			{ data.items.length === 0 ? (
				<p>{ status ? __( 'No flushes with this status.', 'purgekit' ) : __( 'No flushes yet. Edit a post or press Flush now, and it shows up here.', 'purgekit' ) }</p>
			) : (
				<ul className="purgekit-layer-list">
					{ data.items.map( ( row ) => (
						<Row key={ row.id } row={ row } busy={ working[ row.id ] } onReplay={ replay } onRetry={ retry } />
					) ) }
				</ul>
			) }
			<div className="purgekit-layers__bar">
				<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
					{ __( 'Newer', 'purgekit' ) }
				</Button>
				<Button variant="secondary" disabled={ page * 20 >= data.total } onClick={ () => setPage( page + 1 ) }>
					{ __( 'Older', 'purgekit' ) }
				</Button>
				<span className="purgekit-layer__when">{ sprintf( /* translators: %d: number of flushes */ __( '%d flushes', 'purgekit' ), data.total ) }</span>
			</div>
		</div>
	);
}
