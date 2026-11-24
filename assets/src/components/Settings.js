import { useEffect, useState } from '@wordpress/element';
import { Button, SelectControl, TextareaControl, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSettings, saveSettings, rotateWebhookKey, findCloudflareZone, testAlerts } from '../api';
import Portability from './Portability';
import CloudflareTools from './CloudflareTools';

const MODES = [
	{ value: 'notify', label: __( 'Notify me', 'purgekit' ) },
	{ value: 'auto', label: __( 'Flush automatically', 'purgekit' ) },
	{ value: 'scheduled', label: __( 'Scheduled flush', 'purgekit' ) },
];
// Settings a site can inherit from the network defaults (multisite).
const INHERITABLE = [ 'mode', 'schedule', 'skip_if_unchanged', 'warm_enabled' ];
const SCHEDULES = [ 'hourly', '3h', '6h', '12h', 'daily', 'weekly' ].map( ( v ) => ( { value: v, label: v } ) );

export default function Settings() {
	const [ s, setS ] = useState( null );
	const [ loaded, setLoaded ] = useState( null );
	const [ token, setToken ] = useState( '' );
	const [ key, setKey ] = useState( null );
	const [ hookUrl, setHookUrl ] = useState( '' );
	const [ zoneMsg, setZoneMsg ] = useState( '' );
	const [ slack, setSlack ] = useState( '' );
	const [ alertMsg, setAlertMsg ] = useState( '' );
	const [ prov, setProv ] = useState( {} );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		getSettings().then( ( r ) => {
			setS( r );
			setLoaded( r );
		} );
	}, [] );
	if ( ! s ) {
		return null;
	}
	const set = ( k ) => ( v ) => setS( { ...s, [ k ]: v } );

	const findZone = async () => {
		setZoneMsg( '' );
		try {
			const r = await findCloudflareZone( token );
			if ( r.zone ) {
				setS( { ...s, cloudflare_zone: r.zone } );
				setZoneMsg( __( 'Zone found. Press Save to keep it.', 'purgekit' ) );
			} else {
				setZoneMsg( r.message );
			}
		} catch ( e ) {
			setZoneMsg( e.message );
		}
	};

	const sendTest = async () => {
		setAlertMsg( '' );
		try {
			const r = await testAlerts();
			const parts = Object.entries( r.results );
			setAlertMsg( parts.length ? parts.map( ( [ ch, res ] ) => `${ ch }: ${ res }` ).join( ' · ' ) : __( 'No alert channel is set up yet. Save first.', 'purgekit' ) );
		} catch ( e ) {
			setAlertMsg( e.message );
		}
	};

	const resetOne = async ( key ) => {
		await saveSettings( { reset: [ key ] } );
		const r = await getSettings();
		setS( r );
		setLoaded( r );
	};

	/** Fixed in wp-config.php (PURGEKIT_CONFIG): shown, but not editable here. */
	const locked = ( key ) => ( s.locked || [] ).includes( key );

	/** Only on multisite, only for keys this site overrides. */
	const resetLink = ( key ) =>
		s.multisite && INHERITABLE.includes( key ) && ( s.overridden || [] ).includes( key ) ? (
			<p className="description">
				{ __( 'Overridden for this site.', 'purgekit' ) }{ ' ' }
				<Button variant="link" onClick={ () => resetOne( key ) } aria-label={ `${ __( 'Reset to network default', 'purgekit' ) }: ${ key }` }>
					{ __( 'Reset to network default', 'purgekit' ) }
				</Button>
			</p>
		) : null;

	const adoptHome = async () => {
		await saveSettings( { adopt_home: true } );
		const r = await getSettings();
		setS( r );
		setLoaded( r );
	};

	const removeSlack = async () => {
		await saveSettings( { clear_slack_webhook: true } );
		getSettings().then( ( r ) => {
			setS( r );
			setLoaded( r );
		} );
	};

	const save = async () => {
		// Only send what changed: on multisite, saving a key makes the site stop inheriting the network default.
		const patch = {};
		[ 'mode', 'schedule', 'skip_if_unchanged', 'warm_enabled', 'warm_sitemap_limit', 'verify_after_flush', 'cloudflare_zone', 'varnish_enabled', 'opcache_enabled', 'apcu_enabled', 'transients_enabled', 'builder_assets_enabled', 'nginx_cache_path', 'nginx_cache_key', 'nginx_cache_levels', 'alert_email', 'alert_on', 'allow_external', 'exclude_paths' ].forEach( ( k ) => {
			if ( s[ k ] !== loaded[ k ] && ! locked( k ) ) {
				patch[ k ] = s[ k ];
			}
		} );
		if ( token !== '' ) {
			patch.cloudflare_token = token;
		}
		// Typed provider values only; a blank secret keeps the stored one.
		const typed = {};
		( s.providers || [] ).forEach( ( p ) =>
			p.fields.forEach( ( f ) => {
				const v = prov[ f.key ];
				if ( v !== undefined && v !== ( f.kind === 'setting' ? f.value : '' ) ) {
					typed[ f.key ] = v;
				}
			} )
		);
		if ( Object.keys( typed ).length ) {
			patch.providers = typed;
		}
		if ( slack !== '' ) {
			patch.slack_webhook_url = slack;
		}
		await saveSettings( patch );
		setToken( '' );
		setSlack( '' );
		setProv( {} );
		setSaved( true );
		getSettings().then( ( r ) => {
			setS( r );
			setLoaded( r );
		} );
	};

	return (
		<div className="purgekit-form">
			{ ( s.locked || [] ).length > 0 && (
				<div className="notice notice-info inline">
					<p>
						{ __( 'Set in wp-config.php and not editable here:', 'purgekit' ) } <code>{ s.locked.join( ', ' ) }</code>
					</p>
				</div>
			) }
			<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'When something changes', 'purgekit' ) } value={ s.mode } options={ MODES } onChange={ set( 'mode' ) } disabled={ locked( 'mode' ) } />
			{ resetLink( 'mode' ) }
			{ s.mode === 'scheduled' && (
				<>
					<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Schedule', 'purgekit' ) } value={ s.schedule } options={ SCHEDULES } onChange={ set( 'schedule' ) } disabled={ locked( 'schedule' ) } />
					{ resetLink( 'schedule' ) }
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Skip if nothing changed', 'purgekit' ) } checked={ s.skip_if_unchanged } onChange={ set( 'skip_if_unchanged' ) } disabled={ locked( 'skip_if_unchanged' ) } />
					{ resetLink( 'skip_if_unchanged' ) }
				</>
			) }
			<ToggleControl __nextHasNoMarginBottom label={ __( 'Warm the cache after a purge', 'purgekit' ) } checked={ s.warm_enabled } onChange={ set( 'warm_enabled' ) } disabled={ locked( 'warm_enabled' ) } />
			{ resetLink( 'warm_enabled' ) }
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Warm this many sitemap pages after purging everything', 'purgekit' ) }
				type="number"
				min={ 0 }
				max={ 100 }
				value={ s.warm_sitemap_limit ?? 20 }
				onChange={ ( v ) => set( 'warm_sitemap_limit' )( v === '' ? 0 : parseInt( v, 10 ) || 0 ) }
				disabled={ locked( 'warm_sitemap_limit' ) }
				help={ __( 'Taken from wp-sitemap.xml, own pages only. 0 turns it off, 100 is the maximum.', 'purgekit' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Check the home page and purged pages after a flush', 'purgekit' ) }
				checked={ !! s.verify_after_flush }
				onChange={ set( 'verify_after_flush' ) } disabled={ locked( 'verify_after_flush' ) }
				help={ __( 'Fetches up to three own pages once the purge is done and records in History whether a cache layer still serves a stale copy.', 'purgekit' ) }
			/>
			<h3>{ __( 'Safety', 'purgekit' ) }</h3>
			{ s.guard?.paused && (
				<div className="notice notice-warning inline">
					<p>
						{ __( 'Purges to CDNs and firewalls are paused: ', 'purgekit' ) }{ s.guard.paused }.{ ' ' }
						{ s.guard.recorded_host && s.guard.recorded_host !== s.guard.host && (
							<Button variant="link" onClick={ adoptHome }>{ __( 'This is the real site now', 'purgekit' ) }</Button>
						) }
					</p>
				</div>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Allow CDN and firewall purges from this environment', 'purgekit' ) }
				checked={ !! s.allow_external }
				onChange={ set( 'allow_external' ) } disabled={ locked( 'allow_external' ) }
				help={ __( 'Normally purges to Cloudflare, Fastly and similar are paused on staging sites and on copies of the site with a new address, so a clone cannot purge the live CDN.', 'purgekit' ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Never purge these paths (one per line)', 'purgekit' ) }
				value={ s.exclude_paths || '' }
				onChange={ set( 'exclude_paths' ) } disabled={ locked( 'exclude_paths' ) }
				placeholder={ '/cart/*\n/checkout/*\n/my-account/*' }
				help={ __( 'Applies to automatic and rule-driven purges of single pages. Start with / or *, and use * as a wildcard. “Purge everything” and pages you purge by hand are not affected.', 'purgekit' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Reset PHP OPcache when everything is purged', 'purgekit' ) }
				checked={ !! s.opcache_enabled }
				onChange={ set( 'opcache_enabled' ) } disabled={ locked( 'opcache_enabled' ) }
				help={ __( 'For when a theme or plugin changed and PHP still runs the old code. It is never reset for a single page, and it only reaches the PHP pool that runs the purge. Use “Test connection” in the layer map to see its health.', 'purgekit' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Empty APCu when everything is purged', 'purgekit' ) }
				checked={ !! s.apcu_enabled }
				onChange={ set( 'apcu_enabled' ) } disabled={ locked( 'apcu_enabled' ) }
				help={ __( 'APCu is shared by everything on the same PHP pool, so this also drops other applications’ entries there. Only for a full purge.', 'purgekit' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Delete database transients when everything is purged', 'purgekit' ) }
				checked={ !! s.transients_enabled }
				onChange={ set( 'transients_enabled' ) } disabled={ locked( 'transients_enabled' ) }
				help={ __( 'Transients are temporary cached values that plugins rebuild on demand. With a persistent object cache they are not in the database and nothing is deleted.', 'purgekit' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Clear page-builder and minifier files when everything is purged', 'purgekit' ) }
				checked={ !! s.builder_assets_enabled }
				onChange={ set( 'builder_assets_enabled' ) } disabled={ locked( 'builder_assets_enabled' ) }
				help={ __( 'Elementor and Divi CSS, Beaver Builder assets and the Autoptimize cache, for the ones that are installed. They are regenerated on the next visit, which can briefly slow the first pages.', 'purgekit' ) }
			/>
			<ToggleControl __nextHasNoMarginBottom label={ __( 'Varnish', 'purgekit' ) } checked={ s.varnish_enabled } onChange={ set( 'varnish_enabled' ) } disabled={ locked( 'varnish_enabled' ) } />
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Nginx FastCGI cache directory', 'purgekit' ) }
				value={ s.nginx_cache_path || '' }
				onChange={ set( 'nginx_cache_path' ) } disabled={ locked( 'nginx_cache_path' ) }
				help={ __( 'Absolute path from fastcgi_cache_path, e.g. /var/cache/nginx. PHP needs write access. Leave empty if you do not use it.', 'purgekit' ) }
			/>
			{ s.nginx_cache_path && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Nginx cache key', 'purgekit' ) }
						value={ s.nginx_cache_key || '' }
						onChange={ set( 'nginx_cache_key' ) } disabled={ locked( 'nginx_cache_key' ) }
						placeholder="$scheme$request_method$host$request_uri"
						help={ __( 'Your fastcgi_cache_key. Leave empty for the default.', 'purgekit' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Nginx cache levels', 'purgekit' ) }
						value={ s.nginx_cache_levels || '' }
						onChange={ set( 'nginx_cache_levels' ) } disabled={ locked( 'nginx_cache_levels' ) }
						placeholder="1:2"
						help={ __( 'The levels= value from fastcgi_cache_path. Leave empty for 1:2.', 'purgekit' ) }
					/>
				</>
			) }
			<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Cloudflare zone ID', 'purgekit' ) } value={ s.cloudflare_zone } onChange={ set( 'cloudflare_zone' ) } disabled={ locked( 'cloudflare_zone' ) } />
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Cloudflare API token', 'purgekit' ) }
				type="password"
				autoComplete="new-password"
				value={ token }
				onChange={ setToken }
				help={ s.cloudflare_token_set ? __( 'Token set. Leave blank to keep it.', 'purgekit' ) : '' }
			/>
			<h3>{ __( 'Alerts', 'purgekit' ) }</h3>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Alert email', 'purgekit' ) }
				type="email"
				value={ s.alert_email || '' }
				onChange={ set( 'alert_email' ) } disabled={ locked( 'alert_email' ) }
				help={ __( 'Sent when a flush fails. At most one alert every 15 minutes.', 'purgekit' ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Slack incoming webhook URL', 'purgekit' ) }
				type="password"
				autoComplete="new-password"
				value={ slack }
				onChange={ setSlack }
				placeholder="https://hooks.slack.com/services/..."
				help={ s.slack_webhook_set ? __( 'Webhook set. Leave blank to keep it.', 'purgekit' ) : '' }
			/>
			{ s.slack_webhook_set && (
				<Button variant="link" isDestructive onClick={ removeSlack }>
					{ __( 'Remove Slack webhook', 'purgekit' ) }
				</Button>
			) }
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Alert on', 'purgekit' ) }
				value={ s.alert_on }
				options={ [
					{ value: 'problems', label: __( 'Failed and partial flushes', 'purgekit' ) },
					{ value: 'failed', label: __( 'Only when every layer failed', 'purgekit' ) },
				] }
				onChange={ set( 'alert_on' ) } disabled={ locked( 'alert_on' ) }
			/>
			<Button variant="secondary" onClick={ sendTest }>{ __( 'Send test alert', 'purgekit' ) }</Button>
			{ alertMsg && <span>{ ' ' + alertMsg }</span> }
			<h3>{ __( 'Other CDNs and firewalls', 'purgekit' ) }</h3>
			{ ( s.providers || [] ).map( ( p ) => (
				<details key={ p.id }>
					<summary>{ p.label + ( p.detected ? ' ✓' : '' ) }</summary>
					{ p.fields.map( ( f ) => (
						<TextControl
							key={ f.key }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ f.label }
							type={ f.kind === 'secret' ? 'password' : 'text' }
							autoComplete="new-password"
							value={ prov[ f.key ] ?? ( f.kind === 'setting' ? f.value : '' ) }
							onChange={ ( v ) => setProv( { ...prov, [ f.key ]: v } ) }
							help={ f.kind === 'secret' && f.set ? __( 'Saved. Leave blank to keep it.', 'purgekit' ) : '' }
						/>
					) ) }
				</details>
			) ) }
			<hr />
			<Button variant="secondary" onClick={ findZone } disabled={ ! token && ! s.cloudflare_token_set }>
				{ __( 'Find zone ID for this site', 'purgekit' ) }
			</Button>
			{ zoneMsg && <span>{ ' ' + zoneMsg }</span> }
			<br />
			<Button variant="primary" onClick={ save }>{ __( 'Save', 'purgekit' ) }</Button>
			{ saved && <span>{ ' ' + __( 'Saved.', 'purgekit' ) }</span> }
			{ s.cloudflare_token_set && s.cloudflare_zone && (
				<>
					<hr />
					<CloudflareTools />
				</>
			) }
			<hr />
			<Portability
				onImported={ () =>
					getSettings().then( ( r ) => {
						setS( r );
						setLoaded( r );
					} )
				}
			/>
			<hr />
			<Button variant="secondary" onClick={ () => rotateWebhookKey().then( ( r ) => {
				setKey( r.key );
				setHookUrl( r.url );
			} ) }>
				{ s.webhook_key_set ? __( 'Rotate deploy webhook key', 'purgekit' ) : __( 'Create deploy webhook key', 'purgekit' ) }
			</Button>
			{ s.webhook_url && (
				<p>
					{ __( 'Webhook URL:', 'purgekit' ) } <code>{ s.webhook_url }</code>
					<br />
					{ __( 'Send a POST with the key in the X-PurgeKit-Key header.', 'purgekit' ) }
				</p>
			) }
			{ key && (
				<p>
					{ __( 'Copy this key now, it is shown only once:', 'purgekit' ) } <code>{ key }</code>
					{ hookUrl && (
						<>
							<br />
							<code>{ `curl -X POST -H "X-PurgeKit-Key: ${ key }" ${ hookUrl }` }</code>
						</>
					) }
				</p>
			) }
		</div>
	);
}
