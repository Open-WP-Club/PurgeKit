import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getLayers } from '../api';

export default function Layers() {
	const [ layers, setLayers ] = useState( [] );
	useEffect( () => {
		getLayers().then( setLayers );
	}, [] );

	return (
		<table className="widefat striped">
			<thead>
				<tr>
					<th>{ __( 'Layer', 'purgekit' ) }</th>
					<th>{ __( 'Order', 'purgekit' ) }</th>
					<th>{ __( 'State', 'purgekit' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ layers.map( ( l ) => (
					<tr key={ l.id }>
						<td>{ l.label }</td>
						<td>{ l.tier }</td>
						<td>{ l.detected ? __( 'Detected', 'purgekit' ) : __( 'Not detected', 'purgekit' ) }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
