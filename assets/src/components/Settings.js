import { useEffect, useState } from '@wordpress/element';
import { Button, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSettings, saveSettings, rotateWebhookKey } from '../api';

const MODES = [
	{ value: 'notify', label: __( 'Notify me', 'purgekit' ) },
	{ value: 'auto', label: __( 'Flush automatically', 'purgekit' ) },
	{ value: 'scheduled', label: __( 'Scheduled flush', 'purgekit' ) },
];
const SCHEDULES = [ 'hourly', '3h', '6h', '12h', 'daily', 'weekly' ].map( ( v ) => ( { value: v, label: v } ) );

export default function Settings() {
	const [ s, setS ] = useState( null );
	const [ token, setToken ] = useState( '' );
	const [ key, setKey ] = useState( null );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		getSettings().then( setS );
	}, [] );
	if ( ! s ) {
		return null;
	}
	const set = ( k ) => ( v ) => setS( { ...s, [ k ]: v } );

	const save = async () => {
		const patch = {
			mode: s.mode,
			schedule: s.schedule,
			skip_if_unchanged: s.skip_if_unchanged,
			warm_enabled: s.warm_enabled,
			cloudflare_zone: s.cloudflare_zone,
			varnish_enabled: s.varnish_enabled,
		};
		if ( token !== '' ) {
			patch.cloudflare_token = token;
		}
		await saveSettings( patch );
		setToken( '' );
		setSaved( true );
		getSettings().then( setS );
	};

	return (
		<div>
			<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'When something changes', 'purgekit' ) } value={ s.mode } options={ MODES } onChange={ set( 'mode' ) } />
			{ s.mode === 'scheduled' && (
				<>
					<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Schedule', 'purgekit' ) } value={ s.schedule } options={ SCHEDULES } onChange={ set( 'schedule' ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Skip if nothing changed', 'purgekit' ) } checked={ s.skip_if_unchanged } onChange={ set( 'skip_if_unchanged' ) } />
				</>
			) }
			<ToggleControl __nextHasNoMarginBottom label={ __( 'Warm the cache after a purge', 'purgekit' ) } checked={ s.warm_enabled } onChange={ set( 'warm_enabled' ) } />
			<ToggleControl __nextHasNoMarginBottom label={ __( 'Varnish', 'purgekit' ) } checked={ s.varnish_enabled } onChange={ set( 'varnish_enabled' ) } />
			<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Cloudflare zone ID', 'purgekit' ) } value={ s.cloudflare_zone } onChange={ set( 'cloudflare_zone' ) } />
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
			<Button variant="primary" onClick={ save }>{ __( 'Save', 'purgekit' ) }</Button>
			{ saved && <span>{ ' ' + __( 'Saved.', 'purgekit' ) }</span> }
			<hr />
			<Button variant="secondary" onClick={ () => rotateWebhookKey().then( ( r ) => setKey( r.key ) ) }>
				{ s.webhook_key_set ? __( 'Rotate deploy webhook key', 'purgekit' ) : __( 'Create deploy webhook key', 'purgekit' ) }
			</Button>
			{ key && <p><code>{ key }</code></p> }
		</div>
	);
}
