import { __ } from '@wordpress/i18n';

export default function Reasons( { reasons = {} } ) {
	const rows = Object.entries( reasons );
	if ( ! rows.length ) {
		return null;
	}
	return (
		<details>
			<summary>{ __( 'Why were these pages selected?', 'purgekit' ) }</summary>
			<ul>{ rows.map( ( [ url, why ] ) => (
				<li key={ url }><code>{ url === '*' ? __( 'Entire site', 'purgekit' ) : url }</code><ul>{ why.map( ( reason ) => <li key={ reason }>{ reason }</li> ) }</ul></li>
			) ) }</ul>
			<p>{ __( 'Up to 50 targets are shown.', 'purgekit' ) }</p>
		</details>
	);
}
