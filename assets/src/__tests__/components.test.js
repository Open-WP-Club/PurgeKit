import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';
import * as api from '../api';
import Status from '../components/Status';
import Layers from '../components/Layers';
import Settings from '../components/Settings';
import History from '../components/History';

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
