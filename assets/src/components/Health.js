import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getHealth, runHealth } from '../api';
import { Badge, Group } from '../ui';

const TONE = { good: 'ok', recommended: 'warn', critical: 'bad' };
const LABEL = { good: __( 'Good', 'purgekit' ), recommended: __( 'Should fix', 'purgekit' ), critical: __( 'Needs fixing', 'purgekit' ) };
const ORDER = [ 'critical', 'recommended', 'good' ];

export default function Health( { goTo = () => {} } ) {
	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		getHealth().then( setData ).catch( ( e ) => setError( e.message ) );
	}, [] );

	const run = async () => {
		setBusy( true );
		setError( '' );
		try {
			setData( await runHealth() );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	if ( error && ! data ) {
		return <p className="purgekit-note is-bad" role="alert">{ error }</p>;
	}
	if ( ! data ) {
		return <p className="purgekit-layer__when" role="status">{ __( 'Loading…', 'purgekit' ) }</p>;
	}
	const groups = ORDER.map( ( s ) => ( { s, items: data.checks.filter( ( c ) => c.status === s ) } ) ).filter( ( g ) => g.items.length );
	const bad = data.checks.filter( ( c ) => c.status !== 'good' ).length;

	return (
		<div>
			<p>
				{ __( 'Checks of the saved credentials and of the setup. Running them also asks Cloudflare whether the token is still valid and Slack whether the webhook still exists. Nothing is purged and no message is sent.', 'purgekit' ) }
			</p>
			<div className="purgekit-layers__bar">
				<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ run }>
					{ data.networked ? __( 'Run the checks again', 'purgekit' ) : __( 'Run all checks', 'purgekit' ) }
				</Button>
				<span className="purgekit-layer__when" role="status">
					{ bad === 0 ? __( 'Everything looks fine.', 'purgekit' ) : sprintf( /* translators: %d: number of problems */ __( '%d things to look at.', 'purgekit' ), bad ) }
					{ ! data.networked && ' ' + __( 'Cloudflare and Slack have not been asked yet.', 'purgekit' ) }
				</span>
			</div>
			{ error && <p className="purgekit-note is-bad" role="alert">{ error }</p> }
			{ groups.map( ( g ) => (
				<Group key={ g.s } title={ LABEL[ g.s ] }>
					<ul className="purgekit-layer-list">
						{ g.items.map( ( c ) => (
							<li key={ c.id } className="purgekit-layer">
								<div className="purgekit-layer__main">
									<div className="purgekit-layer__name">
										<Badge tone={ TONE[ c.status ] }>{ LABEL[ c.status ] }</Badge>
										<strong>{ c.label }</strong>
									</div>
									<div className="purgekit-layer__msg">{ c.description }</div>
								</div>
								{ c.tab && c.status !== 'good' && (
									<div className="purgekit-layer__actions">
										<Button variant="secondary" size="small" onClick={ () => goTo( c.tab === 'settings' ? 'settings:connections' : c.tab ) }>
											{ c.tab.startsWith( 'settings' ) ? __( 'Open settings', 'purgekit' ) : __( 'Open dashboard', 'purgekit' ) }
										</Button>
									</div>
								) }
							</li>
						) ) }
					</ul>
				</Group>
			) ) }
		</div>
	);
}
