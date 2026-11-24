import { useEffect, useState } from '@wordpress/element';
import { Button, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getHistory } from '../api';

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

function VerificationNote( { v } ) {
	return (
		<span title={ v.message }>
			{ VERIFY_LABELS[ v.status ] || v.status }
			{ v.status !== 'verified' && v.message ? `: ${ v.message }` : '' }
		</span>
	);
}

export default function History() {
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ data, setData ] = useState( null );
	useEffect( () => {
		getHistory( page, status ).then( setData );
	}, [ page, status ] );

	if ( ! data ) {
		return null;
	}
	const link = csvLink( status );
	return (
		<div>
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
			{ link && (
				<p>
					<a href={ link }>{ __( 'Export CSV', 'purgekit' ) }</a>
				</p>
			) }
			{ data.items.length === 0 ? (
				<p>{ __( 'No flushes yet.', 'purgekit' ) }</p>
			) : (
				<table className="widefat striped">
					<tbody>
						{ data.items.map( ( row ) => (
							<tr key={ row.id }>
								<td>{ row.created_at }</td>
								<td>{ row.source }</td>
								<td>{ row.status }</td>
								<td>
									{ row.results
										.filter( ( r ) => r.status === 'failed' )
										.map( ( r ) => (
											<div key={ r.layer }>{ `${ r.layer }: ${ r.message }` }</div>
										) ) }
								</td>
								<td>{ row.verification && <VerificationNote v={ row.verification } /> }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			<Button disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
				{ __( 'Newer', 'purgekit' ) }
			</Button>
			<Button disabled={ page * 20 >= data.total } onClick={ () => setPage( page + 1 ) }>
				{ __( 'Older', 'purgekit' ) }
			</Button>
		</div>
	);
}
