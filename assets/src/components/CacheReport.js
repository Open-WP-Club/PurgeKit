import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

const NOTICE = { cached: 'success', blocked: 'warning', uncertain: 'info', error: 'error' };
const SEVERITY_LABEL = {
	blocker: __( 'Stops caching', 'purgekit' ),
	warning: __( 'May hurt caching', 'purgekit' ),
	info: __( 'Note', 'purgekit' ),
	ok: __( 'Fine', 'purgekit' ),
};
// Worst first, so the reason the page is not cached is at the top.
const ORDER = [ 'blocker', 'warning', 'info', 'ok' ];

/** The answer to "Why isn't it cached?": a verdict, the reasons by importance, and the headers behind them. */
export default function CacheReport( { report } ) {
	const findings = ORDER.flatMap( ( s ) => report.findings.filter( ( f ) => f.severity === s ) );
	const headers = [ ...new Set( [ ...Object.keys( report.first?.headers || {} ), ...Object.keys( report.second?.headers || {} ) ] ) ];
	return (
		<div className="purgekit-cache-report">
			<Notice status={ NOTICE[ report.verdict ] || 'info' } isDismissible={ false }>{ report.message }</Notice>
			{ findings.length > 0 && (
				<ul className="purgekit-findings">
					{ findings.map( ( f, i ) => (
						<li key={ i } className={ `purgekit-finding is-${ f.severity }` }>
							<span className={ `purgekit-badge is-${ f.severity === 'blocker' ? 'bad' : f.severity === 'warning' ? 'warn' : f.severity === 'ok' ? 'ok' : 'muted' }` }>
								{ SEVERITY_LABEL[ f.severity ] }
							</span>{ ' ' }
							{ f.message }
						</li>
					) ) }
				</ul>
			) }
			{ headers.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Header', 'purgekit' ) }</th>
							<th>{ __( 'First visit', 'purgekit' ) } ({ report.first?.status })</th>
							<th>{ __( 'Second visit', 'purgekit' ) } ({ report.second?.status })</th>
						</tr>
					</thead>
					<tbody>
						{ headers.map( ( h ) => (
							<tr key={ h }>
								<td><code>{ h }</code></td>
								<td>{ report.first?.headers?.[ h ] }</td>
								<td>{ report.second?.headers?.[ h ] }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
