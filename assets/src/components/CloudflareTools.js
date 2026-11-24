import { useState } from '@wordpress/element';
import { Button, Notice, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { cloudflarePurge, getCloudflareStatus, setCloudflareDevMode } from '../api';

const lines = ( text ) => text.split( /\r?\n/ ).map( ( l ) => l.trim() ).filter( Boolean );

const timeLeft = ( seconds ) => {
	const h = Math.floor( seconds / 3600 );
	const m = Math.floor( ( seconds % 3600 ) / 60 );
	return h > 0 ? sprintf( /* translators: 1: hours, 2: minutes */ __( '%1$dh %2$dm', 'purgekit' ), h, m ) : sprintf( /* translators: %d: minutes */ __( '%dm', 'purgekit' ), m );
};

/** Extra Cloudflare actions. Nothing is requested until a button is pressed. */
export default function CloudflareTools() {
	const [ status, setStatus ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ prefixes, setPrefixes ] = useState( '' );
	const [ tags, setTags ] = useState( '' );
	const [ note, setNote ] = useState( null );

	const run = async ( name, fn ) => {
		setBusy( name );
		setNote( null );
		try {
			await fn();
		} catch ( e ) {
			setNote( { status: 'error', text: e.message } );
		}
		setBusy( '' );
	};

	const load = () => run( 'status', async () => setStatus( await getCloudflareStatus() ) );

	const toggleDev = ( on ) =>
		run( 'dev', async () => {
			const dev = await setCloudflareDevMode( on );
			setStatus( ( s ) => ( { ...( s || {} ), development_mode: dev } ) );
			if ( ! dev.ok ) {
				setNote( { status: 'error', text: dev.message } );
			}
		} );

	const purge = () =>
		run( 'purge', async () => {
			const r = await cloudflarePurge( lines( prefixes ), lines( tags ) );
			const failed = r.results.filter( ( x ) => x.status !== 'ok' );
			setNote(
				failed.length
					? { status: 'error', text: failed.map( ( x ) => x.message ).join( ' ' ) }
					: { status: 'success', text: __( 'Purged.', 'purgekit' ) }
			);
		} );

	const dev = status?.development_mode;
	const apo = status?.apo;
	return (
		<div className="purgekit-cloudflare-tools">
			<h3>{ __( 'Cloudflare tools', 'purgekit' ) }</h3>
			<p className="description">
				{ __( 'Development mode and the APO status need a token that may also read zone settings. A token that can only purge works for everything else.', 'purgekit' ) }
			</p>
			<Button variant="secondary" isBusy={ busy === 'status' } disabled={ !! busy } onClick={ load }>
				{ __( 'Check Cloudflare status', 'purgekit' ) }
			</Button>
			{ dev && (
				<p>
					<strong>{ __( 'Development mode:', 'purgekit' ) }</strong>{ ' ' }
					{ dev.ok ? ( dev.on ? sprintf( /* translators: %s: time left */ __( 'on, %s left', 'purgekit' ), timeLeft( dev.seconds_left ) ) : __( 'off', 'purgekit' ) ) : __( 'unavailable', 'purgekit' ) }{ ' ' }
					{ dev.ok && (
						<Button variant="link" isBusy={ busy === 'dev' } disabled={ !! busy } onClick={ () => toggleDev( ! dev.on ) }>
							{ dev.on ? __( 'Turn off', 'purgekit' ) : __( 'Turn on for 3 hours', 'purgekit' ) }
						</Button>
					) }
					{ ! dev.ok && <span className="description"> { dev.message }</span> }
				</p>
			) }
			{ apo && (
				<p>
					<strong>{ __( 'Automatic Platform Optimization:', 'purgekit' ) }</strong>{ ' ' }
					{ apo.ok ? ( apo.enabled ? __( 'on', 'purgekit' ) : __( 'off', 'purgekit' ) ) : __( 'unavailable', 'purgekit' ) }
					{ apo.ok && apo.enabled && ! apo.wp_plugin && <span className="description"> — { __( 'the Cloudflare plugin for WordPress is not installed, so APO cannot purge by tag', 'purgekit' ) }</span> }
					{ ! apo.ok && <span className="description"> { apo.message }</span> }
				</p>
			) }
			<h4>{ __( 'Purge by prefix or tag', 'purgekit' ) }</h4>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'URL prefixes, one per line', 'purgekit' ) }
				value={ prefixes }
				onChange={ setPrefixes }
				placeholder="example.com/blog/"
				help={ __( 'A host and a path, without https:// and without a query string. Purges everything below it.', 'purgekit' ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Cache tags, one per line', 'purgekit' ) }
				value={ tags }
				onChange={ setTags }
				help={ __( 'Matches pages whose Cache-Tag response header contains the tag. Available on every Cloudflare plan; the free plan allows 5 purge requests a minute.', 'purgekit' ) }
			/>
			<Button variant="secondary" isBusy={ busy === 'purge' } disabled={ !! busy || ( lines( prefixes ).length === 0 && lines( tags ).length === 0 ) } onClick={ purge }>
				{ __( 'Purge', 'purgekit' ) }
			</Button>
			{ note && <Notice status={ note.status } isDismissible={ false }>{ note.text }</Notice> }
		</div>
	);
}
