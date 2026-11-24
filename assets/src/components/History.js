import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getHistory } from '../api';

export default function History() {
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	useEffect( () => {
		getHistory( page ).then( setData );
	}, [ page ] );

	if ( ! data ) {
		return null;
	}
	if ( data.items.length === 0 ) {
		return <p>{ __( 'No flushes yet.', 'purgekit' ) }</p>;
	}
	return (
		<div>
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
						</tr>
					) ) }
				</tbody>
			</table>
			<Button disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
				{ __( 'Newer', 'purgekit' ) }
			</Button>
			<Button disabled={ page * 20 >= data.total } onClick={ () => setPage( page + 1 ) }>
				{ __( 'Older', 'purgekit' ) }
			</Button>
		</div>
	);
}
