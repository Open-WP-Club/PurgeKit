import { Button, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Group, Setting, ToggleSetting } from '../../ui';

export default function Protection( { form } ) {
	const { s, set, locked, act } = form;
	return (
		<>
			<Group title={ __( 'Staging and copies', 'purgekit' ) }>
				{ s.guard?.paused && (
					<div className="purgekit-banner is-warn">
						<div>
							<strong>{ __( 'Purges to CDNs and firewalls are paused: ', 'purgekit' ) }</strong>
							{ s.guard.paused }.
						</div>
						{ s.guard.recorded_host && s.guard.recorded_host !== s.guard.host && (
							<Button variant="secondary" onClick={ () => act( { adopt_home: true } ) }>{ __( 'This is the real site now', 'purgekit' ) }</Button>
						) }
					</div>
				) }
				<ToggleSetting id="pk-external" label={ __( 'Allow CDN and firewall purges from this environment', 'purgekit' ) } help={ __( 'Normally purges to Cloudflare, Fastly and similar are paused on staging sites and on copies of the site with a new address, so a clone cannot purge the live CDN.', 'purgekit' ) } checked={ !! s.allow_external } onChange={ set( 'allow_external' ) } locked={ locked( 'allow_external' ) } />
			</Group>
			<Group title={ __( 'Flush storms', 'purgekit' ) }>
				<ToggleSetting id="pk-storm" label={ __( 'Pause automatic flushing during a flush storm', 'purgekit' ) } help={ __( 'When five full flushes happen within ten minutes (a cron job or a plugin flushing in a loop), PurgeKit warns you either way. With this on it also stops flushing by itself and keeps the changes queued, until you resume it on the Dashboard.', 'purgekit' ) } checked={ !! s.storm_pause } onChange={ set( 'storm_pause' ) } locked={ locked( 'storm_pause' ) } />
			</Group>
			<Group title={ __( 'Pages that are never purged', 'purgekit' ) }>
				<Setting id="pk-exclude" stacked label={ __( 'Never purge these paths (one per line)', 'purgekit' ) } help={ __( 'Applies to automatic and rule-driven purges of single pages. Start with / or *, and use * as a wildcard. “Purge everything” and pages you purge by hand are not affected.', 'purgekit' ) } locked={ locked( 'exclude_paths' ) }>
					<TextareaControl id="pk-exclude" __nextHasNoMarginBottom  value={ s.exclude_paths || '' } onChange={ set( 'exclude_paths' ) } disabled={ locked( 'exclude_paths' ) } placeholder={ '/cart/*\n/checkout/*\n/my-account/*' } />
				</Setting>
			</Group>
		</>
	);
}
