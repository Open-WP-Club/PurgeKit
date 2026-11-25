import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSettings, saveSettings } from '../../api';

/** Alert channels whose address is a secret: typed here, sent when changed, never shown again. */
export const CHANNELS = [
	{ id: 'slack', field: 'slack_webhook_url', set: 'slack_webhook_set' },
	{ id: 'discord', field: 'discord_webhook_url', set: 'discord_webhook_set' },
	{ id: 'teams', field: 'teams_webhook_url', set: 'teams_webhook_set' },
	{ id: 'webhook', field: 'alert_webhook_url', set: 'alert_webhook_set' },
];

// Settings a site can inherit from the network defaults (multisite).
const INHERITABLE = [ 'mode', 'schedule', 'skip_if_unchanged', 'warm_enabled' ];

// Everything the form edits. Only what changed is sent: on multisite, saving a key makes the site stop inheriting the network default.
const KEYS = [
	'mode', 'schedule', 'skip_if_unchanged', 'warm_enabled', 'warm_sitemap_limit', 'verify_after_flush', 'cloudflare_zone',
	'varnish_enabled', 'opcache_enabled', 'apcu_enabled', 'transients_enabled', 'builder_assets_enabled', 'nginx_cache_path',
	'nginx_cache_key', 'nginx_cache_levels', 'alert_email', 'alert_on', 'allow_external', 'exclude_paths', 'storm_pause',
	'cache_tags', 'soft_purge',
];

/** All the state of the settings screen, so every section can stay small. */
export default function useSettingsForm() {
	const [ s, setS ] = useState( null );
	const [ loaded, setLoaded ] = useState( null );
	const [ token, setToken ] = useState( '' );
	const [ hooks, setHooks ] = useState( {} );
	const [ prov, setProv ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( null );

	const adopt = ( r ) => {
		setS( r );
		setLoaded( r );
	};
	const reload = () => getSettings().then( adopt );
	useEffect( () => {
		getSettings().then( adopt ).catch( ( e ) => setMessage( { status: 'error', text: e.message } ) );
	}, [] );

	if ( ! s ) {
		return { ready: false, message };
	}

	const set = ( k ) => ( v ) => setS( { ...s, [ k ]: v } );
	const locked = ( key ) => ( s.locked || [] ).includes( key );

	const typed = {};
	( s.providers || [] ).forEach( ( p ) =>
		p.fields.forEach( ( f ) => {
			const v = prov[ f.key ];
			if ( v !== undefined && v !== ( f.kind === 'setting' ? f.value : '' ) ) {
				typed[ f.key ] = v;
			}
		} )
	);
	const changed = KEYS.filter( ( k ) => s[ k ] !== loaded[ k ] && ! locked( k ) );
	const typedHooks = CHANNELS.filter( ( c ) => ( hooks[ c.id ] || '' ) !== '' );
	const dirty = changed.length > 0 || token !== '' || typedHooks.length > 0 || Object.keys( typed ).length > 0;

	const save = async () => {
		setSaving( true );
		setMessage( null );
		const patch = {};
		changed.forEach( ( k ) => {
			patch[ k ] = s[ k ];
		} );
		if ( token !== '' ) {
			patch.cloudflare_token = token;
		}
		if ( Object.keys( typed ).length ) {
			patch.providers = typed;
		}
		typedHooks.forEach( ( c ) => {
			patch[ c.field ] = hooks[ c.id ];
		} );
		try {
			await saveSettings( patch );
			setToken( '' );
			setHooks( {} );
			setProv( {} );
			await reload();
			setMessage( { status: 'success', text: __( 'Saved.', 'purgekit' ) } );
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
		setSaving( false );
	};

	const discard = () => {
		setS( loaded );
		setToken( '' );
		setHooks( {} );
		setProv( {} );
		setMessage( null );
	};

	/** Saves one thing right away (a reset, "adopt this address", removing the Slack webhook). */
	const act = async ( patch ) => {
		await saveSettings( patch );
		await reload();
	};

	/** Only on multisite, only for keys this site overrides. */
	const resetLink = ( key ) =>
		s.multisite && INHERITABLE.includes( key ) && ( s.overridden || [] ).includes( key ) ? (
			<p className="purgekit-setting__help">
				{ __( 'Overridden for this site.', 'purgekit' ) }{ ' ' }
				<Button variant="link" onClick={ () => act( { reset: [ key ] } ) } aria-label={ `${ __( 'Reset to network default', 'purgekit' ) }: ${ key }` }>
					{ __( 'Reset to network default', 'purgekit' ) }
				</Button>
			</p>
		) : null;

	return {
		ready: true, s, setS, set, locked, resetLink, token, setToken, hooks, setHooks, prov, setProv,
		dirty, saving, message, setMessage, save, discard, act, reload,
	};
}
