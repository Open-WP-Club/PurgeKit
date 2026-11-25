import { useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { bumpAssets } from '../../api';
import { ago, Group, Live, Setting, ToggleSetting } from '../../ui';

export default function Caches( { form } ) {
	const { s, set, locked, reload } = form;
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState( null );

	const bump = async () => {
		setBusy( true );
		setMessage( null );
		try {
			const r = await bumpAssets();
			setMessage( { status: r.status === 'failed' ? 'error' : 'success', text: r.status === 'failed' ? __( 'The version was changed, but purging the caches failed. Check History.', 'purgekit' ) : __( 'Done. Browsers fetch the CSS and JavaScript files again on the next visit.', 'purgekit' ) } );
			await reload();
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
		setConfirming( false );
		setBusy( false );
	};
	const text = ( id, key, label, help, placeholder ) => (
		<Setting id={ id } label={ label } help={ help } locked={ locked( key ) }>
			<TextControl id={ id } __nextHasNoMarginBottom __next40pxDefaultSize  value={ s[ key ] || '' } onChange={ set( key ) } disabled={ locked( key ) } placeholder={ placeholder } />
		</Setting>
	);
	return (
		<>
			<Group title={ __( 'Cleared when everything is purged', 'purgekit' ) }>
				<ToggleSetting id="pk-opcache" label={ __( 'Reset PHP OPcache', 'purgekit' ) } help={ __( 'For when a theme or plugin changed and PHP still runs the old code. It is never reset for a single page, and it only reaches the PHP pool that runs the purge. Use “Test connection” in the layer map to see its health.', 'purgekit' ) } checked={ !! s.opcache_enabled } onChange={ set( 'opcache_enabled' ) } locked={ locked( 'opcache_enabled' ) } />
				<ToggleSetting id="pk-apcu" label={ __( 'Empty APCu', 'purgekit' ) } help={ __( 'APCu is shared by everything on the same PHP pool, so this also drops other applications’ entries there. Only for a full purge.', 'purgekit' ) } checked={ !! s.apcu_enabled } onChange={ set( 'apcu_enabled' ) } locked={ locked( 'apcu_enabled' ) } />
				<ToggleSetting id="pk-transients" label={ __( 'Delete database transients', 'purgekit' ) } help={ __( 'Transients are temporary cached values that plugins rebuild on demand. With a persistent object cache they are not in the database and nothing is deleted.', 'purgekit' ) } checked={ !! s.transients_enabled } onChange={ set( 'transients_enabled' ) } locked={ locked( 'transients_enabled' ) } />
				<ToggleSetting id="pk-builders" label={ __( 'Clear page-builder and minifier files', 'purgekit' ) } help={ __( 'Elementor and Divi CSS, Beaver Builder assets and the Autoptimize cache, for the ones that are installed. They are regenerated on the next visit, which can briefly slow the first pages.', 'purgekit' ) } checked={ !! s.builder_assets_enabled } onChange={ set( 'builder_assets_enabled' ) } locked={ locked( 'builder_assets_enabled' ) } />
			</Group>
			<Group title={ __( 'Server caches', 'purgekit' ) }>
				<ToggleSetting id="pk-varnish" label={ __( 'Varnish', 'purgekit' ) } help={ __( 'Send PURGE requests to Varnish on this server.', 'purgekit' ) } checked={ !! s.varnish_enabled } onChange={ set( 'varnish_enabled' ) } locked={ locked( 'varnish_enabled' ) } />
				{ text( 'pk-nginx-path', 'nginx_cache_path', __( 'Nginx FastCGI cache directory', 'purgekit' ), __( 'Absolute path from fastcgi_cache_path, e.g. /var/cache/nginx. PHP needs write access. Leave empty if you do not use it.', 'purgekit' ) ) }
				{ s.nginx_cache_path && (
					<>
						{ text( 'pk-nginx-key', 'nginx_cache_key', __( 'Nginx cache key', 'purgekit' ), __( 'Your fastcgi_cache_key. Leave empty for the default.', 'purgekit' ), '$scheme$request_method$host$request_uri' ) }
						{ text( 'pk-nginx-levels', 'nginx_cache_levels', __( 'Nginx cache levels', 'purgekit' ), __( 'The levels= value from fastcgi_cache_path. Leave empty for 1:2.', 'purgekit' ), '1:2' ) }
					</>
				) }
			</Group>
			<Group title={ __( 'CDN behaviour', 'purgekit' ) }>
				<ToggleSetting id="pk-tags" label={ __( 'Cache tags', 'purgekit' ) } help={ __( 'Adds Surrogate-Key and Cache-Tag headers to your pages, and purges a changed post by tag on Fastly and Cloudflare. One tag then reaches the post and every archive, feed and list that shows it. URLs are still purged too, and CDNs without tag support only get the URLs. Turn it on, let the CDN cache a few pages, then change a post.', 'purgekit' ) } checked={ !! s.cache_tags } onChange={ set( 'cache_tags' ) } locked={ locked( 'cache_tags' ) } />
				<ToggleSetting id="pk-soft" label={ __( 'Soft purge', 'purgekit' ) } help={ __( 'Marks content as stale instead of deleting it, so the CDN can serve the old copy while it fetches a new one and a purge does not send a burst of requests to your server. Fastly only; purging everything is always a hard purge.', 'purgekit' ) } checked={ !! s.soft_purge } onChange={ set( 'soft_purge' ) } locked={ locked( 'soft_purge' ) } />
			</Group>
			<Group title={ __( 'Visitors’ browsers', 'purgekit' ) }>
				<Setting
					label={ __( 'Reload CSS and JavaScript in browsers', 'purgekit' ) }
					help={ __( 'A browser’s own cache cannot be purged from the server, but a new address for the file makes it fetch the file again. This adds a new version to the addresses of this site’s CSS and JS files and then purges every layer, because cached pages still point at the old addresses.', 'purgekit' ) }
				>
					{ confirming ? (
						<span className="purgekit-setting__buttons">
							<Button variant="primary" isDestructive isBusy={ busy } disabled={ busy } onClick={ bump }>{ __( 'Reload and purge everything', 'purgekit' ) }</Button>
							<Button variant="tertiary" disabled={ busy } onClick={ () => setConfirming( false ) }>{ __( 'Cancel', 'purgekit' ) }</Button>
						</span>
					) : (
						<Button variant="secondary" onClick={ () => setConfirming( true ) }>{ __( 'Reload assets…', 'purgekit' ) }</Button>
					) }
				</Setting>
				{ s.asset_version > 0 && <p className="purgekit-setting__help">{ `${ __( 'Last reload:', 'purgekit' ) } ${ ago( s.asset_version ) }` }</p> }
				<Live message={ message } onClear={ () => setMessage( null ) } />
			</Group>
		</>
	);
}
