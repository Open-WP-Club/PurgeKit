import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';
import * as api from '../api';
import Status from '../components/Status';
import Layers from '../components/Layers';
import Settings from '../components/Settings';
import History from '../components/History';
import Inspector from '../components/Inspector';
import Rules from '../components/Rules';
import Scheduled from '../components/Scheduled';
import Portability from '../components/Portability';
import CachePanel from '../editor/CachePanel';
import CloudflareTools from '../components/CloudflareTools';

jest.mock( '../api' );

afterEach( () => jest.resetAllMocks() );

test( 'Status shows pending count and Flush now calls flush()', async () => {
	api.getStatus.mockResolvedValue( { pending: 3, mode: 'notify', layers_active: 2, last_run: null } );
	api.flush.mockResolvedValue( { status: 'ok', results: [] } );
	render( <Status /> );
	expect( await screen.findByText( /3 pending/i ) ).toBeInTheDocument();
	await userEvent.click( screen.getByRole( 'button', { name: /flush now/i } ) );
	expect( api.flush ).toHaveBeenCalledTimes( 1 );
	expect( ( await screen.findAllByText( /flush ok/i ) ).length ).toBeGreaterThan( 0 ); // Notice also speaks via an a11y live region.
} );

test( 'Status shows an error when the flush is locked', async () => {
	api.getStatus.mockResolvedValue( { pending: 0, mode: 'notify', layers_active: 1, last_run: null } );
	api.flush.mockRejectedValue( { message: 'Another flush is running' } );
	render( <Status /> );
	await userEvent.click( await screen.findByRole( 'button', { name: /flush now/i } ) );
	expect( ( await screen.findAllByText( /another flush is running/i ) ).length ).toBeGreaterThan( 0 );
} );

test( 'Layers: detected layers are listed, the others are folded away', async () => {
	api.getLayers.mockResolvedValue( [
		{ id: 'object-cache', label: 'Object cache', tier: 10, tier_label: 'Object cache', detected: true, order: 1 },
		{ id: 'cloudflare', label: 'Cloudflare', tier: 50, tier_label: 'CDN / edge', detected: false },
	] );
	render( <Layers /> );
	const list = ( await screen.findAllByRole( 'list' ) )[ 0 ]; // The first list holds the detected layers.
	expect( within( list ).getByText( 'Object cache' ) ).toBeInTheDocument();
	expect( screen.getByText( /not detected \(1\)/i ) ).toBeInTheDocument();
	expect( within( list ).queryByText( 'Cloudflare' ) ).not.toBeInTheDocument();
} );

test( 'Settings never renders a stored token and omits it when blank', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: 'z1', varnish_enabled: false, cloudflare_token_set: true, webhook_key_set: true,
	} );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	expect( await screen.findByText( /token set/i ) ).toBeInTheDocument();
	expect( screen.getByLabelText( /cloudflare api token/i ) ).toHaveValue( '' );
	await userEvent.click( screen.getByRole( 'button', { name: /save/i } ) );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalled() );
	expect( api.saveSettings.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty( 'cloudflare_token' );
} );

test( 'Settings sends a newly typed token', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: false, webhook_key_set: false,
	} );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	await userEvent.type( await screen.findByLabelText( /cloudflare api token/i ), 'abc' );
	await userEvent.click( screen.getByRole( 'button', { name: /save/i } ) );
	await waitFor( () => expect( api.saveSettings.mock.calls[ 0 ][ 0 ].cloudflare_token ).toBe( 'abc' ) );
} );

test( 'History renders statuses and layer results', async () => {
	api.getHistory.mockResolvedValue( {
		total: 2,
		items: [
			{ id: 2, created_at: '2026-11-24 10:00:00', source: 'webhook', status: 'partial', results: [ { layer: 'cloudflare', status: 'failed', message: 'bad zone' } ], urls: [], all: true, duration_ms: 12 },
			{ id: 1, created_at: '2026-11-24 09:00:00', source: 'manual', status: 'ok', results: [], urls: [], all: true, duration_ms: 3 },
		],
	} );
	render( <History /> );
	expect( await screen.findByText( 'partial' ) ).toBeInTheDocument();
	expect( screen.getByText( 'ok' ) ).toBeInTheDocument();
	expect( screen.getByText( /bad zone/ ) ).toBeInTheDocument();
} );

test( 'History shows an empty state', async () => {
	api.getHistory.mockResolvedValue( { total: 0, items: [] } );
	render( <History /> );
	expect( await screen.findByText( /no flushes yet/i ) ).toBeInTheDocument();
} );

test( 'Layers: Test connection only for detected layers and shows the result', async () => {
	api.getLayers.mockResolvedValue( [
		{ id: 'cloudflare', label: 'Cloudflare', tier: 50, tier_label: 'CDN / edge', detected: true, order: 1 },
		{ id: 'varnish', label: 'Varnish', tier: 40, tier_label: 'Reverse proxy', detected: false },
	] );
	api.testLayer.mockResolvedValue( { layer: 'cloudflare', status: 'failed', message: 'Invalid token', at: 1900000000 } );
	render( <Layers /> );
	const buttons = await screen.findAllByRole( 'button', { name: /test connection/i } );
	expect( buttons ).toHaveLength( 1 );
	await userEvent.click( buttons[ 0 ] );
	expect( api.testLayer ).toHaveBeenCalledWith( 'cloudflare' );
	expect( await screen.findByText( /invalid token/i ) ).toBeInTheDocument();
} );

test( 'Settings shows the webhook URL, and the key with a curl example after rotating', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: false,
		webhook_key_set: true, webhook_url: 'https://a.test/wp-json/purgekit/v1/webhook/flush',
	} );
	api.rotateWebhookKey.mockResolvedValue( { key: 'KEY123', url: 'https://a.test/wp-json/purgekit/v1/webhook/flush' } );
	render( <Settings /> );
	expect( await screen.findByText( 'https://a.test/wp-json/purgekit/v1/webhook/flush' ) ).toBeInTheDocument();
	expect( screen.queryByText( /KEY123/ ) ).not.toBeInTheDocument();
	await userEvent.click( screen.getByRole( 'button', { name: /rotate deploy webhook key/i } ) );
	expect( await screen.findByText( 'KEY123' ) ).toBeInTheDocument();
	expect( screen.getByText( /curl -X POST/ ) ).toBeInTheDocument();
} );

test( 'Settings can look up the Cloudflare zone and fills it in without saving', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: true, webhook_key_set: false,
	} );
	api.findCloudflareZone.mockResolvedValue( { zone: 'zone999', message: '' } );
	render( <Settings /> );
	await userEvent.click( await screen.findByRole( 'button', { name: /find zone id/i } ) );
	expect( await screen.findByDisplayValue( 'zone999' ) ).toBeInTheDocument();
	expect( api.saveSettings ).not.toHaveBeenCalled();
} );

test( 'Inspector reports the stale layer and offers a per-layer purge', async () => {
	api.inspectUrl.mockResolvedValue( {
		verdict: 'stale',
		url: 'https://a.test/x',
		message: 'Cloudflare is serving an out-of-date copy. Purge it.',
		layer: 'cloudflare',
		served_by: [ { layer: 'cloudflare', label: 'Cloudflare', evidence: 'Cloudflare edge cache (cf-cache-status: HIT)', tier: 50 } ],
		visitor: { status: 200, headers: { 'cf-cache-status': 'HIT' } },
		fresh: { status: 200, headers: {} },
	} );
	api.purgeLayerUrl.mockResolvedValue( { layer: 'cloudflare', status: 'ok', message: '' } );
	render( <Inspector /> );
	const inspect = screen.getByRole( 'button', { name: /^inspect$/i } );
	expect( inspect ).toBeDisabled();
	await userEvent.type( screen.getByLabelText( /page url/i ), 'https://a.test/x' );
	await userEvent.click( inspect );
	expect( ( await screen.findAllByText( /out-of-date copy/i ) ).length ).toBeGreaterThan( 0 );
	await userEvent.click( screen.getByRole( 'button', { name: /purge this layer/i } ) );
	expect( api.purgeLayerUrl ).toHaveBeenCalledWith( 'cloudflare', 'https://a.test/x' );
	expect( await screen.findByText( /purged/i ) ).toBeInTheDocument();
} );

test( 'Inspector shows an API error', async () => {
	api.inspectUrl.mockRejectedValue( new Error( 'Enter a URL on this site.' ) );
	render( <Inspector /> );
	await userEvent.type( screen.getByLabelText( /page url/i ), 'https://evil.test/' );
	await userEvent.click( screen.getByRole( 'button', { name: /^inspect$/i } ) );
	expect( ( await screen.findAllByText( /enter a url on this site/i ) ).length ).toBeGreaterThan( 0 );
} );

test( 'Settings only sends the keys that changed, so inherited network defaults stay inherited', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'auto', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: false, webhook_key_set: false,
	} );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	await userEvent.type( await screen.findByLabelText( /cloudflare zone id/i ), 'zone1' );
	await userEvent.click( screen.getByRole( 'button', { name: /^save$/i } ) );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalled() );
	expect( api.saveSettings.mock.calls[ 0 ][ 0 ] ).toEqual( { cloudflare_zone: 'zone1' } );
} );

test( 'Settings: Slack webhook is never rendered, only sent when typed, and the test button reports per channel', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: false, webhook_key_set: false,
		alert_email: '', alert_on: 'problems', slack_webhook_set: true,
	} );
	api.saveSettings.mockResolvedValue( {} );
	api.testAlerts.mockResolvedValue( { channels: [ 'slack' ], results: { slack: 'ok', email: 'The mail was not accepted for delivery.' } } );
	render( <Settings /> );
	expect( await screen.findByText( /webhook set/i ) ).toBeInTheDocument();
	expect( screen.getByLabelText( /slack incoming webhook url/i ) ).toHaveValue( '' );
	await userEvent.click( screen.getByRole( 'button', { name: /send test alert/i } ) );
	expect( await screen.findByText( /slack: ok/i ) ).toBeInTheDocument();
	await userEvent.click( screen.getByRole( 'button', { name: /^save$/i } ) );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalled() );
	expect( api.saveSettings.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty( 'slack_webhook_url' );
	await userEvent.type( screen.getByLabelText( /slack incoming webhook url/i ), 'https://hooks.slack.com/services/T/B/X' );
	await userEvent.click( screen.getByRole( 'button', { name: /^save$/i } ) );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalledTimes( 2 ) );
	expect( api.saveSettings.mock.calls[ 1 ][ 0 ].slack_webhook_url ).toBe( 'https://hooks.slack.com/services/T/B/X' );
} );

test( 'History: status filter reloads and the CSV link carries the filter', async () => {
	window.purgekitData = { csvUrl: 'https://a.test/wp-admin/admin-post.php?action=purgekit_history_csv&_wpnonce=abc' };
	api.getHistory.mockResolvedValue( { total: 0, items: [] } );
	render( <History /> );
	const link = await screen.findByRole( 'link', { name: /export csv/i } );
	expect( link ).toHaveAttribute( 'href', expect.stringContaining( '_wpnonce=abc' ) );
	await userEvent.selectOptions( screen.getByLabelText( /show/i ), 'failed' );
	await waitFor( () => expect( api.getHistory ).toHaveBeenLastCalledWith( 1, 'failed' ) );
	expect( screen.getByRole( 'link', { name: /export csv/i } ) ).toHaveAttribute( 'href', expect.stringContaining( '&status=failed' ) );
	delete window.purgekitData;
} );

test( 'Settings: provider secrets are never shown, only changed fields are sent', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: false, webhook_key_set: false,
		alert_email: '', alert_on: 'problems',
		providers: [
			{ id: 'bunny', label: 'Bunny CDN', detected: false, fields: [
				{ key: 'bunny_pullzone_id', kind: 'setting', label: 'Bunny pull zone ID', value: '42' },
				{ key: 'BUNNY_ACCESS_KEY', kind: 'secret', label: 'Bunny account API key', set: true },
			] },
		],
	} );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	expect( await screen.findByLabelText( /bunny pull zone id/i ) ).toHaveValue( '42' );
	expect( screen.getByLabelText( /bunny account api key/i ) ).toHaveValue( '' );
	await userEvent.click( screen.getByRole( 'button', { name: /^save$/i } ) );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalled() );
	expect( api.saveSettings.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty( 'providers' );
	await userEvent.type( screen.getByLabelText( /bunny account api key/i ), 'K1' );
	await userEvent.click( screen.getByRole( 'button', { name: /^save$/i } ) );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalledTimes( 2 ) );
	expect( api.saveSettings.mock.calls[ 1 ][ 0 ].providers ).toEqual( { BUNNY_ACCESS_KEY: 'K1' } );
} );

test( 'Rules lists saved rules and saves an added one', async () => {
	api.getScheduled.mockResolvedValue( [] );
	api.getRules.mockResolvedValue( {
		rules: [ { id: 'abcd1234', name: 'Shop', enabled: true, on: [ 'stock' ], post_types: [], action: 'home', layers: [] } ],
		triggers: { stock: 'WooCommerce stock changes', post: 'A post changes' },
		actions: [ 'default', 'all', 'home', 'skip' ],
		max: 50,
	} );
	api.getLayers.mockResolvedValue( [ { id: 'cloudflare', label: 'Cloudflare', tier: 50, detected: true } ] );
	api.saveRules.mockImplementation( async ( rules ) => ( { rules, triggers: {}, actions: [], max: 50 } ) );
	render( <Rules /> );
	expect( await screen.findByText( 'Shop' ) ).toBeInTheDocument();
	await userEvent.click( screen.getByRole( 'button', { name: /add rule/i } ) );
	await userEvent.click( screen.getByRole( 'button', { name: /save rules/i } ) );
	await waitFor( () => expect( api.saveRules ).toHaveBeenCalledTimes( 1 ) );
	expect( api.saveRules.mock.calls[ 0 ][ 0 ] ).toHaveLength( 2 );
	expect( ( await screen.findAllByText( /rules saved/i ) ).length ).toBeGreaterThan( 0 );
} );

const PREVIEW = {
	pending: 3,
	empty: false,
	all: false,
	urls: [ 'https://a.test/x/' ],
	urls_total: 1,
	routed: true,
	jobs: [
		{ rules: [ 'Posts to the CDN' ], all: false, urls: [ 'https://a.test/x/' ], urls_total: 1, layers: [ { id: 'cloudflare', label: 'Cloudflare' } ] },
		{ rules: [], all: true, urls: [], urls_total: 0, layers: [ { id: 'object-cache', label: 'Object cache' } ] },
	],
	skipped: [ { type: 'comment', rule: 'Quiet comments', urls: [] } ],
};

test( 'Status: Preview shows the plan, rule names, layers and skipped changes without flushing', async () => {
	api.getStatus.mockResolvedValue( { pending: 3, mode: 'notify', layers_active: 2, last_run: null } );
	api.previewFlush.mockResolvedValue( PREVIEW );
	render( <Status /> );
	await userEvent.click( await screen.findByRole( 'button', { name: /^preview$/i } ) );
	expect( await screen.findByText( /by rule: Posts to the CDN/ ) ).toBeInTheDocument();
	expect( screen.getByText( /Cloudflare/ ) ).toBeInTheDocument();
	expect( screen.getByText( 'https://a.test/x/' ) ).toBeInTheDocument();
	expect( screen.getByText( /by default \(no rule\)/ ) ).toBeInTheDocument();
	expect( screen.getByText( /comment \(rule: Quiet comments\)/ ) ).toBeInTheDocument();
	expect( api.flush ).not.toHaveBeenCalled();
} );

test( 'Preview says so when nothing would be purged, and shows errors', async () => {
	api.getStatus.mockResolvedValue( { pending: 0, mode: 'notify', layers_active: 1, last_run: null } );
	api.previewFlush.mockResolvedValueOnce( { ...PREVIEW, pending: 0, empty: true, jobs: [], skipped: [] } );
	render( <Status /> );
	await userEvent.click( await screen.findByRole( 'button', { name: /^preview$/i } ) );
	expect( await screen.findByText( /nothing would be purged/i ) ).toBeInTheDocument();
	api.previewFlush.mockRejectedValueOnce( new Error( 'Sorry, you are not allowed' ) );
	await userEvent.click( screen.getByRole( 'button', { name: /^preview$/i } ) );
	expect( ( await screen.findAllByText( /not allowed/i ) ).length ).toBeGreaterThan( 0 );
} );

test( 'Rules tab has a Preview button too', async () => {
	api.getScheduled.mockResolvedValue( [] );
	api.getRules.mockResolvedValue( { rules: [], triggers: {}, actions: [], max: 50 } );
	api.getLayers.mockResolvedValue( [] );
	api.previewFlush.mockResolvedValue( PREVIEW );
	render( <Rules /> );
	await userEvent.click( await screen.findByRole( 'button', { name: /^preview$/i } ) );
	expect( await screen.findByText( /by rule: Posts to the CDN/ ) ).toBeInTheDocument();
} );

test( 'History shows the verification result per flush', async () => {
	api.getHistory.mockResolvedValue( {
		total: 3,
		items: [
			{ id: 3, created_at: 'a', source: 'manual', status: 'ok', results: [], urls: [], all: true, duration_ms: 1, verification: { status: 'stale', message: 'Cloudflare still serves a stale copy of https://a.test/.' } },
			{ id: 2, created_at: 'b', source: 'manual', status: 'ok', results: [], urls: [], all: true, duration_ms: 1, verification: { status: 'verified', message: 'Verified 1 of 1 pages.' } },
			{ id: 1, created_at: 'c', source: 'manual', status: 'ok', results: [], urls: [], all: true, duration_ms: 1, verification: null },
		],
	} );
	render( <History /> );
	expect( await screen.findByText( /Stale copy: Cloudflare still serves a stale copy/ ) ).toBeInTheDocument();
	expect( screen.getByText( 'Verified' ) ).toBeInTheDocument();
} );

test( 'Settings: verification toggle and sitemap limit are sent when changed', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true, warm_sitemap_limit: 20, verify_after_flush: false,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: false, webhook_key_set: false,
	} );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	await userEvent.click( await screen.findByLabelText( /check the home page and purged pages/i ) );
	const limit = screen.getByLabelText( /warm this many sitemap pages/i );
	expect( limit ).toHaveValue( 20 );
	await userEvent.clear( limit );
	await userEvent.type( limit, '35' );
	await userEvent.click( screen.getByRole( 'button', { name: /^save$/i } ) );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalled() );
	expect( api.saveSettings.mock.calls[ 0 ][ 0 ] ).toEqual( { warm_sitemap_limit: 35, verify_after_flush: true } );
} );

test( 'Settings: reset buttons appear only on multisite for overridden keys and send the key', async () => {
	const base = {
		mode: 'scheduled', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: false, webhook_key_set: false,
	};
	api.getSettings.mockResolvedValueOnce( { ...base, multisite: true, overridden: [ 'mode', 'schedule' ] } );
	api.getSettings.mockResolvedValue( { ...base, multisite: true, overridden: [ 'schedule' ] } );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	const buttons = await screen.findAllByRole( 'button', { name: /reset to network default/i } );
	expect( buttons ).toHaveLength( 2 );
	await userEvent.click( buttons[ 0 ] );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalledWith( { reset: [ 'mode' ] } ) );
	await waitFor( () => expect( screen.getAllByRole( 'button', { name: /reset to network default/i } ) ).toHaveLength( 1 ) );
} );

test( 'Settings: no reset buttons on a single site', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'auto', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true,
		cloudflare_zone: '', varnish_enabled: false, cloudflare_token_set: false, webhook_key_set: false,
		multisite: false, overridden: [],
	} );
	render( <Settings /> );
	await screen.findByLabelText( /when something changes/i );
	expect( screen.queryByRole( 'button', { name: /reset to network default/i } ) ).not.toBeInTheDocument();
} );

test( 'Layer map: groups run from the visitor inwards, show the last purge and check, and purge one layer', async () => {
	api.getLayers.mockResolvedValue( [
		{ id: 'object-cache', label: 'Object cache', tier: 10, tier_label: 'Object cache', detected: true, order: 1, last: null, checked: null },
		{
			id: 'cloudflare', label: 'Cloudflare', tier: 50, tier_label: 'CDN / edge', detected: true, order: 2,
			last: { status: 'failed', message: 'Invalid token', ms: 5, at: '2026-11-24 10:00:00', source: 'cron' },
			checked: { status: 'ok', message: 'Zone reachable', ms: 5, at: 1900000000 },
		},
	] );
	api.purgeLayer.mockResolvedValue( { status: 'ok', results: [ { layer: 'cloudflare', status: 'ok', message: '' } ] } );
	render( <Layers /> );
	expect( await screen.findByText( /invalid token/i ) ).toBeInTheDocument();
	expect( screen.getByText( /zone reachable/i ) ).toBeInTheDocument();
	expect( screen.getByText( /no purge recorded yet/i ) ).toBeInTheDocument();
	expect( screen.getByText( /not checked yet/i ) ).toBeInTheDocument();
	const headings = screen.getAllByRole( 'heading', { level: 3 } ).map( ( h ) => h.textContent );
	expect( headings ).toEqual( [ 'CDN / edge', 'Object cache' ] ); // Closest to the visitor first.
	const cf = screen.getByText( 'Cloudflare' ).closest( 'li' );
	expect( cf ).toHaveTextContent( '2' );
	await userEvent.click( within( cf ).getByRole( 'button', { name: /purge this layer/i } ) );
	expect( api.purgeLayer ).toHaveBeenCalledWith( 'cloudflare' );
	await waitFor( () => expect( api.getLayers ).toHaveBeenCalledTimes( 2 ) ); // Reloaded so the last-purge line is fresh.
} );

test( 'Layer map: Check all tests every detected layer, Refresh reloads the detection', async () => {
	api.getLayers.mockResolvedValue( [
		{ id: 'cloudflare', label: 'Cloudflare', tier: 50, tier_label: 'CDN / edge', detected: true, order: 1 },
		{ id: 'fastly', label: 'Fastly', tier: 50, tier_label: 'CDN / edge', detected: true, order: 2 },
		{ id: 'varnish', label: 'Varnish', tier: 40, tier_label: 'Reverse proxy', detected: false },
	] );
	api.testLayer.mockImplementation( async ( id ) => ( { layer: id, status: id === 'fastly' ? 'failed' : 'ok', message: id === 'fastly' ? 'Bad key' : 'Reachable', at: 1900000000 } ) );
	render( <Layers /> );
	await userEvent.click( await screen.findByRole( 'button', { name: /check all connections/i } ) );
	await waitFor( () => expect( api.testLayer ).toHaveBeenCalledTimes( 2 ) );
	expect( api.testLayer ).not.toHaveBeenCalledWith( 'varnish' );
	expect( await screen.findByText( /bad key/i ) ).toBeInTheDocument();
	expect( screen.getByText( /reachable/i ) ).toBeInTheDocument();

	await userEvent.click( screen.getByRole( 'button', { name: /^refresh$/i } ) );
	await waitFor( () => expect( api.getLayers ).toHaveBeenCalledTimes( 2 ) );
} );

test( 'Layer map: a layer can be switched off and shows as paused when the environment guard applies', async () => {
	api.getLayers.mockResolvedValue( [
		{ id: 'cloudflare', label: 'Cloudflare', tier: 50, tier_label: 'CDN / edge', detected: true, enabled: true, paused: 'Paused: staging', order: 1, last: null },
	] );
	api.setLayerEnabled.mockResolvedValue( [
		{ id: 'cloudflare', label: 'Cloudflare', tier: 50, tier_label: 'CDN / edge', detected: true, enabled: false, paused: null, order: 1, last: null },
	] );
	render( <Layers /> );
	expect( await screen.findByText( /paused \(see settings\)/i ) ).toBeInTheDocument();
	await userEvent.click( screen.getByRole( 'button', { name: /switch off/i } ) );
	expect( api.setLayerEnabled ).toHaveBeenCalledWith( 'cloudflare', false );
	expect( await screen.findByText( /^switched off$/i ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: /purge this layer/i } ) ).toBeDisabled();
	expect( screen.getByRole( 'button', { name: /switch on/i } ) ).toBeInTheDocument();
} );

test( 'Settings: safety section shows the pause, adopts the new address, and sends only the changed exclusions', async () => {
	const base = {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true, cloudflare_zone: '', varnish_enabled: false,
		webhook_key_set: true, webhook_url: 'https://a.test/hook', allow_external: false, exclude_paths: '', providers: [],
		guard: { paused: "this site runs in the 'staging' environment", environment: 'staging', recorded_host: 'a.test', host: 'b.test' },
	};
	api.getSettings.mockResolvedValue( base );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	expect( await screen.findByText( /purges to cdns and firewalls are paused/i ) ).toBeInTheDocument();
	await userEvent.click( screen.getByRole( 'button', { name: /this is the real site now/i } ) );
	expect( api.saveSettings ).toHaveBeenCalledWith( { adopt_home: true } );

	await userEvent.type( screen.getByLabelText( /never purge these paths/i ), '/cart/*' );
	await userEvent.click( screen.getByRole( 'button', { name: /^save/i } ) );
	const patch = api.saveSettings.mock.calls.at( -1 )[ 0 ];
	expect( patch ).toEqual( { exclude_paths: '/cart/*' } );
} );

test( 'Scheduled purges: lists, adds with a unix time, and cancels', async () => {
	api.getScheduled.mockResolvedValue( [ { id: 'abcd1234', at: 1900000000, urls: [], note: 'Launch' } ] );
	api.addScheduled.mockResolvedValue( [] );
	api.cancelScheduled.mockResolvedValue( [] );
	render( <Scheduled /> );
	expect( await screen.findByText( /launch/i ) ).toBeInTheDocument();
	await userEvent.click( screen.getByRole( 'button', { name: /cancel/i } ) );
	expect( api.cancelScheduled ).toHaveBeenCalledWith( 'abcd1234' );

	await userEvent.type( screen.getByLabelText( /^when$/i ), '2030-01-02T09:00' );
	await userEvent.type( screen.getByLabelText( /addresses on this site/i ), 'https://a.test/sale/' );
	await userEvent.click( screen.getByRole( 'button', { name: /schedule purge/i } ) );
	await waitFor( () => expect( api.addScheduled ).toHaveBeenCalledTimes( 1 ) );
	const [ at, urls ] = api.addScheduled.mock.calls[ 0 ];
	expect( Number.isInteger( at ) && at > 1800000000 ).toBe( true );
	expect( urls ).toEqual( [ 'https://a.test/sale/' ] );
} );

test( 'Settings: the OPcache switch is sent only when changed', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true, cloudflare_zone: '', varnish_enabled: false,
		opcache_enabled: false, webhook_key_set: true, webhook_url: 'https://a.test/hook', providers: [], guard: { paused: null },
	} );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	await userEvent.click( await screen.findByLabelText( /reset php opcache when everything is purged/i ) );
	await userEvent.click( screen.getByRole( 'button', { name: /^save/i } ) );
	expect( api.saveSettings.mock.calls.at( -1 )[ 0 ] ).toEqual( { opcache_enabled: true } );
} );

test( 'Settings: the APCu, transients and builder-file switches are sent only when changed', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'notify', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true, cloudflare_zone: '', varnish_enabled: false,
		apcu_enabled: false, transients_enabled: false, builder_assets_enabled: false,
		webhook_key_set: true, webhook_url: 'https://a.test/hook', providers: [], guard: { paused: null },
	} );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	await userEvent.click( await screen.findByLabelText( /empty apcu when everything is purged/i ) );
	await userEvent.click( screen.getByLabelText( /delete database transients when everything is purged/i ) );
	await userEvent.click( screen.getByLabelText( /clear page-builder and minifier files/i ) );
	await userEvent.click( screen.getByRole( 'button', { name: /^save/i } ) );
	expect( api.saveSettings.mock.calls.at( -1 )[ 0 ] ).toEqual( { apcu_enabled: true, transients_enabled: true, builder_assets_enabled: true } );
} );

test( 'Settings: settings fixed in wp-config.php are listed and cannot be edited', async () => {
	api.getSettings.mockResolvedValue( {
		mode: 'auto', schedule: 'daily', skip_if_unchanged: true, warm_enabled: true, cloudflare_zone: '', varnish_enabled: false,
		webhook_key_set: true, webhook_url: 'https://a.test/hook', providers: [], guard: { paused: null }, locked: [ 'mode', 'warm_enabled' ],
	} );
	render( <Settings /> );
	expect( await screen.findByText( /set in wp-config\.php and not editable here/i ) ).toBeInTheDocument();
	expect( screen.getByLabelText( /when something changes/i ) ).toBeDisabled();
	expect( screen.getByLabelText( /warm the cache after a purge/i ) ).toBeDisabled();
	expect( screen.getByLabelText( /check the home page and purged pages/i ) ).not.toBeDisabled();
} );

test( 'Export and import: imports a file, reports what was applied and refuses invalid JSON', async () => {
	const onImported = jest.fn();
	api.importSettings.mockResolvedValue( { applied: [ 'mode', 'schedule' ], ignored: [ 'allow_external' ], rules: 3 } );
	const { container } = render( <Portability onImported={ onImported } /> );
	const input = container.querySelector( 'input[type="file"]' );

	const good = new File( [ JSON.stringify( { purgekit: 1, settings: { mode: 'auto' } } ) ], 'purgekit-settings.json', { type: 'application/json' } );
	good.text = () => Promise.resolve( JSON.stringify( { purgekit: 1, settings: { mode: 'auto' } } ) );
	await userEvent.upload( input, good );
	expect( ( await screen.findAllByText( /2 settings applied\. 3 rules imported\. Not applied: allow_external\./i ) ).length ).toBeGreaterThan( 0 );
	expect( api.importSettings ).toHaveBeenCalledWith( { purgekit: 1, settings: { mode: 'auto' } } );
	expect( onImported ).toHaveBeenCalled();

	const bad = new File( [ 'not json' ], 'x.json', { type: 'application/json' } );
	bad.text = () => Promise.resolve( 'not json' );
	await userEvent.upload( input, bad );
	expect( ( await screen.findAllByText( /not valid json/i ) ).length ).toBeGreaterThan( 0 );
	expect( api.importSettings ).toHaveBeenCalledTimes( 1 );
} );

test( 'Export and import: the download asks the server for the export', async () => {
	api.getSettingsExport.mockResolvedValue( { purgekit: 1, settings: {}, providers: {}, rules: [] } );
	global.URL.createObjectURL = jest.fn( () => 'blob:x' );
	global.URL.revokeObjectURL = jest.fn();
	const click = jest.spyOn( HTMLAnchorElement.prototype, 'click' ).mockImplementation( () => {} );
	render( <Portability /> );
	await userEvent.click( screen.getByRole( 'button', { name: /download settings/i } ) );
	await waitFor( () => expect( api.getSettingsExport ).toHaveBeenCalledTimes( 1 ) );
	expect( global.URL.createObjectURL ).toHaveBeenCalled();
	expect( click ).toHaveBeenCalled();
	click.mockRestore();
} );

test( 'Editor panel: asks nothing until the button is pressed, then shows the cache layers with a purge button each', async () => {
	api.inspectUrl.mockResolvedValue( {
		verdict: 'stale', url: 'https://a.test/hello/', message: 'Cloudflare is serving an out-of-date copy. Purge it.', layer: 'cloudflare',
		served_by: [ { layer: 'cloudflare', label: 'Cloudflare', evidence: 'Cloudflare (cf-cache-status: HIT)', tier: 50 } ],
		visitor: { status: 200, headers: {} }, fresh: { status: 200, headers: {} },
	} );
	api.purgeLayerUrl.mockResolvedValue( { layer: 'cloudflare', status: 'ok', message: '' } );
	render( <CachePanel url="https://a.test/hello/" published /> );
	expect( api.inspectUrl ).not.toHaveBeenCalled();

	await userEvent.click( screen.getByRole( 'button', { name: /check caches/i } ) );
	expect( api.inspectUrl ).toHaveBeenCalledWith( 'https://a.test/hello/' );
	expect( ( await screen.findAllByText( /out-of-date copy/i ) ).length ).toBeGreaterThan( 0 );
	expect( screen.getByText( /cf-cache-status: HIT/ ) ).toBeInTheDocument();

	await userEvent.click( screen.getByRole( 'button', { name: /^purge$/i } ) );
	expect( api.purgeLayerUrl ).toHaveBeenCalledWith( 'cloudflare', 'https://a.test/hello/' );
	expect( ( await screen.findAllByText( /purged\. check again/i ) ).length ).toBeGreaterThan( 0 );
	expect( screen.getByRole( 'button', { name: /check again/i } ) ).toBeInTheDocument();
} );

test( 'Editor panel: purges the page on every layer, and an unpublished page is not checked', async () => {
	api.flush.mockResolvedValue( { status: 'ok', results: [] } );
	const { unmount } = render( <CachePanel url="https://a.test/hello/" published /> );
	await userEvent.click( screen.getByRole( 'button', { name: /purge this page/i } ) );
	expect( api.flush ).toHaveBeenCalledWith( [ 'https://a.test/hello/' ] );
	unmount();

	render( <CachePanel url="https://a.test/?p=1" published={ false } /> );
	expect( screen.getByText( /publish this page to check/i ) ).toBeInTheDocument();
	expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
} );

test( 'Editor panel: shows an API error', async () => {
	api.inspectUrl.mockRejectedValue( { message: 'Only pages on this site can be checked.' } );
	render( <CachePanel url="https://a.test/hello/" published /> );
	await userEvent.click( screen.getByRole( 'button', { name: /check caches/i } ) );
	expect( ( await screen.findAllByText( /only pages on this site/i ) ).length ).toBeGreaterThan( 0 );
} );

test( 'Cloudflare tools: nothing is requested until a button is pressed; shows development mode and APO', async () => {
	api.getCloudflareStatus.mockResolvedValue( {
		development_mode: { ok: true, on: true, seconds_left: 5400, message: '' },
		apo: { ok: true, enabled: true, wp_plugin: false, hostnames: [ 'a.test' ], message: '' },
	} );
	api.setCloudflareDevMode.mockResolvedValue( { ok: true, on: false, seconds_left: 0, message: '' } );
	render( <CloudflareTools /> );
	expect( api.getCloudflareStatus ).not.toHaveBeenCalled();

	await userEvent.click( screen.getByRole( 'button', { name: /check cloudflare status/i } ) );
	expect( await screen.findByText( /on, 1h 30m left/i ) ).toBeInTheDocument();
	expect( screen.getByText( /plugin for wordpress is not installed/i ) ).toBeInTheDocument();

	await userEvent.click( screen.getByRole( 'button', { name: /turn off/i } ) );
	expect( api.setCloudflareDevMode ).toHaveBeenCalledWith( false );
	expect( await screen.findByRole( 'button', { name: /turn on for 3 hours/i } ) ).toBeInTheDocument();
} );

test( 'Cloudflare tools: purges by prefix and tag, one entry per line, and shows a failure', async () => {
	api.cloudflarePurge.mockResolvedValueOnce( { status: 'ok', results: [ { layer: 'cloudflare', status: 'ok', message: 'Purged 2 prefixes.' } ] } );
	api.cloudflarePurge.mockResolvedValueOnce( { status: 'failed', results: [ { layer: 'cloudflare', status: 'failed', message: 'Rate limited' } ] } );
	render( <CloudflareTools /> );
	const button = screen.getByRole( 'button', { name: /^purge$/i } );
	expect( button ).toBeDisabled();

	await userEvent.type( screen.getByLabelText( /url prefixes/i ), 'example.com/blog/{enter}example.com/shop/' );
	await userEvent.type( screen.getByLabelText( /cache tags/i ), 'post-1' );
	await userEvent.click( button );
	expect( api.cloudflarePurge ).toHaveBeenCalledWith( [ 'example.com/blog/', 'example.com/shop/' ], [ 'post-1' ] );
	expect( ( await screen.findAllByText( /^purged\.$/i ) ).length ).toBeGreaterThan( 0 );

	await userEvent.click( screen.getByRole( 'button', { name: /^purge$/i } ) );
	expect( ( await screen.findAllByText( /rate limited/i ) ).length ).toBeGreaterThan( 0 );
} );

test( 'Cloudflare tools: an unavailable setting shows the reason', async () => {
	api.getCloudflareStatus.mockResolvedValue( {
		development_mode: { ok: false, on: false, seconds_left: 0, message: 'Authentication error (needs Zone Settings)' },
		apo: { ok: false, enabled: false, wp_plugin: false, hostnames: [], message: 'Authentication error (needs Zone Settings)' },
	} );
	render( <CloudflareTools /> );
	await userEvent.click( screen.getByRole( 'button', { name: /check cloudflare status/i } ) );
	expect( ( await screen.findAllByText( /needs zone settings/i ) ).length ).toBe( 2 );
	expect( screen.queryByRole( 'button', { name: /turn on for 3 hours/i } ) ).not.toBeInTheDocument();
} );

test( 'Inspector: "Why isn\u2019t it cached?" lists the blockers first, then the rest, and never needs the other button', async () => {
	api.checkCacheability.mockResolvedValue( {
		verdict: 'blocked', url: 'https://a.test/', message: 'No cache is likely to store this page. See the reasons below.',
		findings: [
			{ severity: 'info', code: 'cache-control', message: 'There is no Cache-Control header.' },
			{ severity: 'blocker', code: 'cookie', message: 'Cookie PHPSESSID: a PHP session was started.' },
			{ severity: 'warning', code: 'vary', message: 'Vary: Cookie makes a separate copy.' },
		],
		evidence: [], cookies: [ 'PHPSESSID' ],
		first: { status: 200, headers: { vary: 'Cookie' } }, second: { status: 200, headers: { vary: 'Cookie' } },
	} );
	render( <Inspector /> );
	const button = screen.getByRole( 'button', { name: /why isn.t it cached/i } );
	expect( button ).toBeDisabled();
	await userEvent.type( screen.getByLabelText( /page url/i ), 'https://a.test/' );
	await userEvent.click( button );
	expect( api.checkCacheability ).toHaveBeenCalledWith( 'https://a.test/' );
	expect( api.inspectUrl ).not.toHaveBeenCalled();

	expect( await screen.findByText( /php session was started/i ) ).toBeInTheDocument();
	const items = screen.getAllByRole( 'listitem' ).map( ( li ) => li.textContent );
	expect( items[ 0 ] ).toMatch( /stops caching.*php session/i );
	expect( items[ 1 ] ).toMatch( /may hurt caching.*vary: cookie/i );
	expect( items[ 2 ] ).toMatch( /note.*no cache-control/i );
	expect( screen.getByText( 'First visit', { exact: false } ) ).toBeInTheDocument();
} );

test( 'Inspector: the cacheability check shows an API error', async () => {
	api.checkCacheability.mockRejectedValue( { message: 'Enter a URL on this site.' } );
	render( <Inspector /> );
	await userEvent.type( screen.getByLabelText( /page url/i ), 'https://elsewhere.test/' );
	await userEvent.click( screen.getByRole( 'button', { name: /why isn.t it cached/i } ) );
	expect( ( await screen.findAllByText( /enter a url on this site/i ) ).length ).toBeGreaterThan( 0 );
} );
