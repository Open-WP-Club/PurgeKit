import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getLayers, saveSettings, testLayer } from '../api';
import { Badge, Choice, StatusBadge } from '../ui';
import { MODES } from './settings/General';

const STEPS = [ __( 'What was found', 'purgekit' ), __( 'How to react', 'purgekit' ), __( 'Check the connections', 'purgekit' ) ];

/** A short first screen: shows what was detected, lets the person pick a mode and checks that the layers answer. */
export default function Wizard( { onDone } ) {
	const [ step, setStep ] = useState( 0 );
	const [ layers, setLayers ] = useState( null );
	const [ mode, setMode ] = useState( null );
	const [ checks, setChecks ] = useState( {} );
	const [ checking, setChecking ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		getLayers().then( setLayers ).catch( ( e ) => setError( e.message ) );
	}, [] );

	const detected = ( layers || [] ).filter( ( l ) => l.detected );
	const hasCdn = detected.some( ( l ) => l.tier >= 50 );
	const recommended = detected.length > 0 ? 'auto' : 'notify';
	const picked = mode || recommended;

	const options = MODES.map( ( m ) => ( { ...m, badge: m.value === recommended ? __( 'Recommended', 'purgekit' ) : undefined } ) );

	const checkAll = async () => {
		setChecking( true );
		await Promise.all(
			detected.map( async ( l ) => {
				try {
					const r = await testLayer( l.id );
					setChecks( ( c ) => ( { ...c, [ l.id ]: r } ) );
				} catch ( e ) {
					setChecks( ( c ) => ( { ...c, [ l.id ]: { status: 'failed', message: e.message } } ) );
				}
			} )
		);
		setChecking( false );
	};

	const finish = async ( skip ) => {
		setSaving( true );
		try {
			await saveSettings( skip ? { onboarded: true } : { mode: picked, onboarded: true } );
			onDone( skip ? null : picked );
		} catch ( e ) {
			setError( e.message );
		}
		setSaving( false );
	};

	return (
		<section className="purgekit-card purgekit-wizard" aria-labelledby="pk-wizard-title">
			<h2 id="pk-wizard-title">{ __( 'Welcome to PurgeKit', 'purgekit' ) }</h2>
			<ol className="purgekit-steps" aria-label={ __( 'Setup steps', 'purgekit' ) }>
				{ STEPS.map( ( t, i ) => (
					<li key={ t } className={ i === step ? 'is-current' : i < step ? 'is-done' : '' } aria-current={ i === step ? 'step' : undefined }>
						<span>{ i + 1 }</span> { t }
					</li>
				) ) }
			</ol>
			{ error && <p className="purgekit-note is-bad" role="alert">{ error }</p> }

			{ step === 0 && (
				<div>
					<p>{ __( 'PurgeKit purges every cache in front of your site in the right order, from the inside out. This is what it found on this site:', 'purgekit' ) }</p>
					{ layers === null ? (
						<p className="purgekit-layer__when" role="status">{ __( 'Looking…', 'purgekit' ) }</p>
					) : detected.length === 0 ? (
						<p className="purgekit-note is-warn">{ __( 'No cache layer was detected. That is fine if the site is not cached. A CDN such as Cloudflare can be connected in Settings → Connections.', 'purgekit' ) }</p>
					) : (
						<ul className="purgekit-layer-list">
							{ detected.map( ( l ) => (
								<li key={ l.id } className="purgekit-layer">
									<span className="purgekit-layer__order">{ l.order }</span>
									<div className="purgekit-layer__main">
										<div className="purgekit-layer__name">
											<strong>{ l.label }</strong> <Badge tone="muted">{ l.tier_label }</Badge>
										</div>
									</div>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }

			{ step === 1 && (
				<Choice legend={ __( 'When something on the site changes', 'purgekit' ) } value={ picked } options={ options } onChange={ setMode } />
			) }

			{ step === 2 && (
				<div>
					<p>{ __( 'Test that every detected layer answers. Nothing is purged.', 'purgekit' ) }</p>
					<Button variant="secondary" isBusy={ checking } disabled={ checking || detected.length === 0 } onClick={ checkAll }>
						{ __( 'Check connections', 'purgekit' ) }
					</Button>
					<ul className="purgekit-layer-list">
						{ detected.map( ( l ) => (
							<li key={ l.id } className="purgekit-layer__line">
								<strong>{ l.label }</strong>
								{ checks[ l.id ] ? <StatusBadge status={ checks[ l.id ].status } /> : <span className="purgekit-layer__when">{ __( 'Not checked', 'purgekit' ) }</span> }
								{ checks[ l.id ]?.message && <span className="purgekit-layer__msg">{ checks[ l.id ].message }</span> }
							</li>
						) ) }
					</ul>
					{ ! hasCdn && <p className="purgekit-layer__when">{ __( 'No CDN is connected yet. If you use Cloudflare, Fastly or similar, add it in Settings → Connections later.', 'purgekit' ) }</p> }
				</div>
			) }

			<div className="purgekit-wizard__nav">
				<Button variant="link" onClick={ () => finish( true ) } disabled={ saving }>{ __( 'Skip setup', 'purgekit' ) }</Button>
				<span className="purgekit-wizard__spacer" />
				{ step > 0 && <Button variant="tertiary" onClick={ () => setStep( step - 1 ) }>{ __( 'Back', 'purgekit' ) }</Button> }
				{ step < STEPS.length - 1 ? (
					<Button variant="primary" onClick={ () => setStep( step + 1 ) } disabled={ layers === null }>{ __( 'Next', 'purgekit' ) }</Button>
				) : (
					<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ () => finish( false ) }>
						{ sprintf( /* translators: %s: mode name */ __( 'Finish: %s', 'purgekit' ), MODES.find( ( m ) => m.value === picked )?.label ) }
					</Button>
				) }
			</div>
		</section>
	);
}
