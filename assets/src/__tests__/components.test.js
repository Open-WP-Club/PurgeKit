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

test( 'Layers renders one row per layer with detected badge', async () => {
	api.getLayers.mockResolvedValue( [
		{ id: 'object-cache', label: 'Object cache', tier: 10, detected: true },
		{ id: 'cloudflare', label: 'Cloudflare', tier: 50, detected: false },
	] );
	render( <Layers /> );
	expect( await screen.findByText( 'Object cache' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Cloudflare' ) ).toBeInTheDocument();
	expect( screen.getAllByText( /^detected$/i ) ).toHaveLength( 1 );
	expect( screen.getAllByText( /not detected/i ) ).toHaveLength( 1 );
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
		{ id: 'cloudflare', label: 'Cloudflare', tier: 50, detected: true },
		{ id: 'varnish', label: 'Varnish', tier: 40, detected: false },
	] );
	api.testLayer.mockResolvedValue( { layer: 'cloudflare', status: 'failed', message: 'Invalid token' } );
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

test( 'Layer map: shows purge order from the visitor inwards, the last result, and purges one layer', async () => {
	api.getLayers.mockResolvedValue( [
		{ id: 'object-cache', label: 'Object cache', tier: 10, tier_label: 'Object cache', detected: true, order: 1, last: null },
		{ id: 'cloudflare', label: 'Cloudflare', tier: 50, tier_label: 'CDN / edge', detected: true, order: 2, last: { status: 'failed', message: 'Invalid token', ms: 5, at: '2026-11-24 10:00:00', source: 'cron' } },
	] );
	api.purgeLayer.mockResolvedValue( { status: 'ok', results: [ { layer: 'cloudflare', status: 'ok', message: '' } ] } );
	render( <Layers /> );
	expect( await screen.findByText( /invalid token/i ) ).toBeInTheDocument();
	expect( screen.getByText( /no purge recorded yet/i ) ).toBeInTheDocument();
	const rows = screen.getAllByRole( 'row' ).slice( 1 );
	expect( rows[ 0 ] ).toHaveTextContent( 'Cloudflare' ); // Closest to the visitor first.
	expect( rows[ 0 ] ).toHaveTextContent( '2' );
	expect( rows[ 1 ] ).toHaveTextContent( 'Object cache' );
	await userEvent.click( within( rows[ 0 ] ).getByRole( 'button', { name: /purge this layer/i } ) );
	expect( api.purgeLayer ).toHaveBeenCalledWith( 'cloudflare' );
	expect( await screen.findByText( /purge: ok/i ) ).toBeInTheDocument();
	expect( api.getLayers ).toHaveBeenCalledTimes( 2 ); // Reloaded to refresh the last result.
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
	expect( await screen.findByText( /switched off/i ) ).toBeInTheDocument();
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
