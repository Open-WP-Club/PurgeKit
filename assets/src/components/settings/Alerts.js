import { useState } from '@wordpress/element';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { testAlerts } from '../../api';
import { Group, Setting, ToggleSetting } from '../../ui';
import { CHANNELS } from './useSettingsForm';

const LABELS = {
	slack: { name: __( 'Slack incoming webhook URL', 'purgekit' ), placeholder: 'https://hooks.slack.com/services/...' },
	discord: { name: __( 'Discord webhook URL', 'purgekit' ), placeholder: 'https://discord.com/api/webhooks/...' },
	teams: { name: __( 'Microsoft Teams webhook URL', 'purgekit' ), placeholder: 'https://...logic.azure.com/workflows/...', help: __( 'A Teams “Workflows” webhook. The message is sent as an Adaptive Card.', 'purgekit' ) },
	webhook: { name: __( 'Webhook URL (JSON)', 'purgekit' ), placeholder: 'https://example.com/hooks/purgekit', help: __( 'Gets a JSON message with the event, the status, what started the flush and the layers that failed. Must be https and public.', 'purgekit' ) },
};

export default function Alerts( { form } ) {
	const { s, set, locked, hooks, setHooks, act } = form;
	const [ msg, setMsg ] = useState( '' );

	const sendTest = async () => {
		setMsg( '' );
		try {
			const r = await testAlerts();
			const parts = Object.entries( r.results );
			setMsg( parts.length ? parts.map( ( [ ch, res ] ) => `${ ch }: ${ res }` ).join( ' · ' ) : __( 'No alert channel is set up yet. Save first.', 'purgekit' ) );
		} catch ( e ) {
			setMsg( e.message );
		}
	};

	return (
		<>
			<Group title={ __( 'Email', 'purgekit' ) }>
				<Setting id="pk-email" label={ __( 'Alert email', 'purgekit' ) } help={ __( 'Sent when a flush fails, and when a flush storm starts. At most one alert every 15 minutes.', 'purgekit' ) } locked={ locked( 'alert_email' ) }>
					<TextControl id="pk-email" __nextHasNoMarginBottom __next40pxDefaultSize type="email" value={ s.alert_email || '' } onChange={ set( 'alert_email' ) } disabled={ locked( 'alert_email' ) } />
				</Setting>
			</Group>
			<Group title={ __( 'Chat and webhooks', 'purgekit' ) }>
				{ CHANNELS.map( ( c ) => (
					<div key={ c.id }>
						<Setting id={ `pk-${ c.id }` } label={ LABELS[ c.id ].name } help={ s[ c.set ] ? __( 'Webhook set. Leave blank to keep it.', 'purgekit' ) : LABELS[ c.id ].help }>
							<TextControl
								id={ `pk-${ c.id }` }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								type="password"
								autoComplete="new-password"
								value={ hooks[ c.id ] || '' }
								onChange={ ( v ) => setHooks( { ...hooks, [ c.id ]: v } ) }
								placeholder={ LABELS[ c.id ].placeholder }
							/>
						</Setting>
						{ s[ c.set ] && (
							<div className="purgekit-setting__actions">
								<Button variant="link" isDestructive onClick={ () => act( c.id === 'slack' ? { clear_slack_webhook: true } : { clear_alert_channel: c.id } ) }>
									{ `${ __( 'Remove', 'purgekit' ) } ${ LABELS[ c.id ].name.replace( / (incoming )?webhook URL.*/i, '' ) } ${ __( 'webhook', 'purgekit' ) }` }
								</Button>
							</div>
						) }
					</div>
				) ) }
			</Group>
			<Group title={ __( 'Monitoring', 'purgekit' ) }>
				<ToggleSetting id="pk-health" label={ __( 'Tell me when a cache has a problem', 'purgekit' ) } help={ __( 'Checked once an hour for the caches that can be read from here (Redis, Memcached, OPcache, APCu): not answering, memory nearly full, keys thrown out. Each problem is reported once, not every hour.', 'purgekit' ) } checked={ !! s.alert_cache_health } onChange={ set( 'alert_cache_health' ) } locked={ locked( 'alert_cache_health' ) } />
				<ToggleSetting id="pk-digest" label={ __( 'Send a weekly summary', 'purgekit' ) } help={ __( 'One message a week to the channels above with the flushes, failures and speed of each layer, and which pages no cache answered.', 'purgekit' ) } checked={ !! s.alert_digest } onChange={ set( 'alert_digest' ) } locked={ locked( 'alert_digest' ) } />
				<ToggleSetting id="pk-probe" label={ __( 'Check the site’s pages every hour', 'purgekit' ) } help={ __( 'Fetches the home page, the newest posts and pages and the shop page (two requests each) to see which ones a cache answers. Shown in the Compare tab. Only addresses on this site are requested.', 'purgekit' ) } checked={ !! s.site_probe } onChange={ set( 'site_probe' ) } locked={ locked( 'site_probe' ) } />
			</Group>
			<Group title={ __( 'What to alert on', 'purgekit' ) }>
				<Setting id="pk-alert-on" label={ __( 'Alert on', 'purgekit' ) } locked={ locked( 'alert_on' ) }>
					<SelectControl
						id="pk-alert-on"
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						value={ s.alert_on }
						options={ [
							{ value: 'problems', label: __( 'Failed and partial flushes', 'purgekit' ) },
							{ value: 'failed', label: __( 'Only when every layer failed', 'purgekit' ) },
						] }
						onChange={ set( 'alert_on' ) }
						disabled={ locked( 'alert_on' ) }
					/>
				</Setting>
				<div className="purgekit-setting__actions">
					<Button variant="secondary" onClick={ sendTest }>{ __( 'Send test alert', 'purgekit' ) }</Button>
					{ msg && <span className="purgekit-layer__when" role="status">{ msg }</span> }
				</div>
			</Group>
		</>
	);
}
