import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';
import * as api from '../api';
import VariantInspector from '../components/VariantInspector';
import Reasons from '../components/Reasons';
import Preview from '../components/Preview';
import History from '../components/History';
import Dashboard from '../components/Dashboard';
import Settings from '../components/Settings';

jest.mock( '../api' );
afterEach( () => jest.resetAllMocks() );

const report = { verdict: 'cached', findings: [], evidence: [ 'Age: 60' ], cookies: [], first: { headers: {} }, second: { headers: {} }, message: 'Cached' };

test( 'Variant comparison uses the page URL by default and accepts language URLs and queries', async () => {
	api.inspectVariants.mockResolvedValue( { variants: [ { url: 'https://a.test/bg/?currency=BGN', device: 'mobile', language: 'bg', inspection: { message: 'Mobile copy differs' }, cacheability: report } ] } );
	render( <VariantInspector url="https://a.test/" /> );
	await userEvent.click( screen.getByText( 'Compare cache variants' ) );
	await userEvent.type( screen.getAllByLabelText( /language code/i )[ 0 ], 'bg' );
	await userEvent.type( screen.getAllByLabelText( /variant url/i )[ 0 ], 'https://a.test/bg/?currency=BGN' );
	await userEvent.click( screen.getByRole( 'button', { name: 'Compare variants' } ) );
	expect( api.inspectVariants ).toHaveBeenCalledWith( [ { url: 'https://a.test/bg/?currency=BGN', device: 'desktop', language: 'bg' }, { url: 'https://a.test/', device: 'mobile', language: '' } ] );
	expect( await screen.findByText( 'Mobile copy differs' ) ).toBeInTheDocument();
	expect( screen.getAllByText( 'Cached' ).length ).toBeGreaterThan( 0 );
} );

test( 'Variant comparison limits four rows, supports removal and reports errors', async () => {
	api.inspectVariants.mockRejectedValue( { message: 'Choose a valid language code' } );
	render( <VariantInspector url="https://a.test/" /> );
	await userEvent.click( screen.getByText( 'Compare cache variants' ) );
	await userEvent.click( screen.getByRole( 'button', { name: 'Add variant' } ) );
	await userEvent.click( screen.getByRole( 'button', { name: 'Add variant' } ) );
	expect( screen.getByRole( 'button', { name: 'Add variant' } ) ).toBeDisabled();
	await userEvent.click( screen.getAllByRole( 'button', { name: 'Remove variant' } )[ 0 ] );
	expect( screen.getAllByLabelText( 'Device' ) ).toHaveLength( 3 );
	await userEvent.click( screen.getByRole( 'button', { name: 'Compare variants' } ) );
	expect( ( await screen.findAllByText( 'Choose a valid language code' ) ).length ).toBeGreaterThan( 0 );
	expect( screen.getByRole( 'button', { name: 'Compare variants' } ) ).toBeEnabled();
} );

test( 'Variant comparison prevents empty requests and overlapping submissions', async () => {
	let finish;
	api.inspectVariants.mockImplementation( () => new Promise( ( resolve ) => { finish = resolve; } ) );
	const { rerender } = render( <VariantInspector url="" /> );
	await userEvent.click( screen.getByText( 'Compare cache variants' ) );
	expect( screen.getByRole( 'button', { name: 'Compare variants' } ) ).toBeDisabled();
	rerender( <VariantInspector url="https://a.test/" /> );
	await userEvent.click( screen.getByRole( 'button', { name: 'Compare variants' } ) );
	expect( screen.getByRole( 'button', { name: 'Comparing…' } ) ).toBeDisabled();
	finish( { variants: [] } );
	await waitFor( () => expect( screen.getByRole( 'button', { name: 'Compare variants' } ) ).toBeEnabled() );
} );

test( 'Reasons render targets and causes, including the entire site, and tolerate old history', () => {
	const { rerender, container } = render( <Reasons /> );
	expect( container ).toBeEmptyDOMElement();
	rerender( <Reasons reasons={ { '*': [ 'switch_theme' ], 'https://a.test/': [ 'post #12 → rule: CDN' ] } } /> );
	fireEvent.click( screen.getByText( 'Why were these pages selected?' ) );
	expect( screen.getByText( 'Entire site' ) ).toBeInTheDocument();
	expect( screen.getByText( 'post #12 → rule: CDN' ) ).toBeInTheDocument();
} );

test( 'Preview shows each URL cause and the reason a layer is skipped without flushing', async () => {
	api.previewFlush.mockResolvedValue( { pending: 1, empty: false, jobs: [ { all: false, urls: [ 'https://a.test/' ], urls_total: 1, rules: [ 'CDN' ], layers: [ { id: 'cdn', label: 'CDN', skipped: 'Staging protection' } ], reasons: { 'https://a.test/': [ 'post #3 → rule: CDN' ] } } ], skipped: [] } );
	render( <Preview /> );
	await userEvent.click( screen.getByRole( 'button', { name: 'Preview' } ) );
	expect( await screen.findByText( 'CDN: Staging protection' ) ).toBeInTheDocument();
	expect( screen.getByText( 'post #3 → rule: CDN' ) ).toBeInTheDocument();
	expect( api.flush ).not.toHaveBeenCalled();
} );

test( 'History displays stored causes and skipped layer messages', async () => {
	api.getLayers.mockResolvedValue( [] );
	api.getHistory.mockResolvedValue( { total: 1, items: [ { id: 1, created_at: '2026-11-25 10:00:00', status: 'skipped', source: 'auto', all: false, urls: [], results: [ { layer: 'cdn', status: 'skipped', message: 'Disabled by owner' } ], trigger: { reasons: { 'https://a.test/': [ 'previous category archive' ] } } } ] } );
	render( <History /> );
	expect( await screen.findByText( 'cdn: Disabled by owner' ) ).toBeInTheDocument();
	expect( screen.getByText( 'previous category archive' ) ).toBeInTheDocument();
} );

test( 'Dashboard shows warming counts and its next batch', async () => {
	api.getLayers.mockResolvedValue( [] );
	api.getStatus.mockResolvedValue( { pending: 1, mode: 'auto', recent: [], warming: { pending: 4, successful: 8, failed: 1, retries: 3, next_at: 1800000000 }, auto_due: 1800000000 } );
	render( <Dashboard /> );
	expect( await screen.findByText( '4 waiting · 8 successful · 1 failed · 3 retries' ) ).toBeInTheDocument();
	expect( screen.getByText( /Next batch/ ) ).toBeInTheDocument();
	expect( screen.getByText( /Automatic purge scheduled for/ ) ).toBeInTheDocument();
} );

test( 'Settings save quiet time, deadline and warming priority URLs', async () => {
	api.getSettings.mockResolvedValue( { mode: 'auto', batch_delay: 20, batch_maximum: 300, warm_priority_urls: [], providers: [], guard: { paused: null } } );
	api.saveSettings.mockResolvedValue( {} );
	render( <Settings /> );
	const quiet = await screen.findByLabelText( /quiet seconds/i );
	fireEvent.change( quiet, { target: { value: '30' } } );
	fireEvent.change( screen.getByLabelText( /maximum collection time/i ), { target: { value: '600' } } );
	fireEvent.change( screen.getByLabelText( /priority warming urls/i ), { target: { value: 'https://a.test/shop/\nhttps://a.test/important/' } } );
	await userEvent.click( screen.getByRole( 'button', { name: /^save/i } ) );
	await waitFor( () => expect( api.saveSettings ).toHaveBeenCalledWith( { batch_delay: 30, batch_maximum: 600, warm_priority_urls: [ 'https://a.test/shop/', 'https://a.test/important/' ] } ) );
} );

test( 'History execution plan links rules, layers and delayed URL causes', async () => {
	api.getLayers.mockResolvedValue( [] );
	api.getHistory.mockResolvedValue( { total: 1, items: [ { id: 1, created_at: '2026-11-25 10:00:00', status: 'skipped', source: 'auto', all: false, urls: [], results: [], trigger: { jobs: [ { rules: [ 'CDN later' ], layers: [ { id: 'cdn', label: 'Cloudflare' } ], delay: 30, when: 'on_failure', reasons: { 'https://a.test/': [ 'post #12' ] } } ] } } ] } );
	render( <History /> );
	expect( await screen.findByText( 'Execution plan' ) ).toBeInTheDocument();
	expect( screen.getByText( 'CDN later → Cloudflare' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Delayed by 30 seconds' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Only if an earlier step failed' ) ).toBeInTheDocument();
	expect( screen.getByText( 'post #12' ) ).toBeInTheDocument();
} );
