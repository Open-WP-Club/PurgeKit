import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { findCloudflareZone, rotateWebhookKey } from '../../api';
import { Badge, Group, Setting } from '../../ui';
import CloudflareTools from '../CloudflareTools';
import Portability from '../Portability';

export default function Connections( { form } ) {
	const { s, setS, set, locked, token, setToken, prov, setProv, reload } = form;
	const [ zoneMsg, setZoneMsg ] = useState( '' );
	const [ key, setKey ] = useState( null );
	const [ hookUrl, setHookUrl ] = useState( '' );

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

	return (
		<>
			<Group title={ __( 'Cloudflare', 'purgekit' ) }>
				<Setting id="pk-cf-zone" label={ __( 'Cloudflare zone ID', 'purgekit' ) } locked={ locked( 'cloudflare_zone' ) }>
					<TextControl id="pk-cf-zone" __nextHasNoMarginBottom __next40pxDefaultSize  value={ s.cloudflare_zone } onChange={ set( 'cloudflare_zone' ) } disabled={ locked( 'cloudflare_zone' ) } />
				</Setting>
				<Setting id="pk-cf-token" label={ __( 'Cloudflare API token', 'purgekit' ) } help={ s.cloudflare_token_set ? __( 'Token set. Leave blank to keep it.', 'purgekit' ) : __( 'Needs Zone · Cache Purge · Purge.', 'purgekit' ) }>
					<TextControl id="pk-cf-token" __nextHasNoMarginBottom __next40pxDefaultSize  type="password" autoComplete="new-password" value={ token } onChange={ setToken } />
				</Setting>
				<div className="purgekit-setting__actions">
					<Button variant="secondary" onClick={ findZone } disabled={ ! token && ! s.cloudflare_token_set }>
						{ __( 'Find zone ID for this site', 'purgekit' ) }
					</Button>
					{ zoneMsg && <span className="purgekit-layer__when" role="status">{ zoneMsg }</span> }
				</div>
				{ s.cloudflare_token_set && s.cloudflare_zone && <CloudflareTools /> }
			</Group>

			<Group title={ __( 'Other CDNs and firewalls', 'purgekit' ) }>
				{ ( s.providers || [] ).map( ( p ) => (
					<details key={ p.id } className="purgekit-provider">
						<summary>
							<strong>{ p.label }</strong> { p.detected && <Badge tone="ok">{ __( 'Connected', 'purgekit' ) }</Badge> }
						</summary>
						<div className="purgekit-provider__fields">
							{ p.fields.map( ( f ) => (
								<Setting key={ f.key } id={ `pk-${ f.key }` } label={ f.label } help={ f.kind === 'secret' && f.set ? __( 'Saved. Leave blank to keep it.', 'purgekit' ) : '' }>
									<TextControl
										id={ `pk-${ f.key }` }
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										type={ f.kind === 'secret' ? 'password' : 'text' }
										autoComplete="new-password"
										value={ prov[ f.key ] ?? ( f.kind === 'setting' ? f.value : '' ) }
										onChange={ ( v ) => setProv( { ...prov, [ f.key ]: v } ) }
									/>
								</Setting>
							) ) }
						</div>
					</details>
				) ) }
			</Group>

			<Group title={ __( 'Deploy webhook', 'purgekit' ) }>
				<p className="purgekit-setting__help">
					{ __( 'Lets a deploy script purge the site with one request.', 'purgekit' ) }
				</p>
				<div className="purgekit-setting__actions">
					<Button
						variant="secondary"
						onClick={ () =>
							rotateWebhookKey().then( ( r ) => {
								setKey( r.key );
								setHookUrl( r.url );
								reload();
							} )
						}
					>
						{ s.webhook_key_set ? __( 'Rotate deploy webhook key', 'purgekit' ) : __( 'Create deploy webhook key', 'purgekit' ) }
					</Button>
				</div>
				{ s.webhook_url && (
					<p className="purgekit-setting__help">
						{ __( 'Webhook URL:', 'purgekit' ) } <code>{ s.webhook_url }</code>
						<br />
						{ __( 'Send a POST with the key in the X-PurgeKit-Key header.', 'purgekit' ) }
					</p>
				) }
				{ key && (
					<div className="purgekit-note is-ok" role="status">
						{ __( 'Copy this key now, it is shown only once:', 'purgekit' ) } <code>{ key }</code>
						{ hookUrl && (
							<>
								<br />
								<code>{ `curl -X POST -H "X-PurgeKit-Key: ${ key }" ${ hookUrl }` }</code>
							</>
						) }
					</div>
				) }
			</Group>

			<Group title={ __( 'Backup', 'purgekit' ) }>
				<Portability onImported={ reload } />
			</Group>
		</>
	);
}
