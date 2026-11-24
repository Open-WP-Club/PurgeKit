import apiFetch from '@wordpress/api-fetch';

const NS = '/purgekit/v1';

export const getStatus = () => apiFetch( { path: `${ NS }/status` } );
export const flush = ( urls ) =>
	apiFetch( { path: `${ NS }/flush`, method: 'POST', data: urls ? { urls } : {} } );
export const getLayers = () => apiFetch( { path: `${ NS }/layers` } );
export const getHistory = ( page = 1 ) =>
	apiFetch( { path: `${ NS }/history?per_page=20&page=${ page }` } );
export const getSettings = () => apiFetch( { path: `${ NS }/settings` } );
export const saveSettings = ( patch ) =>
	apiFetch( { path: `${ NS }/settings`, method: 'POST', data: patch } );
export const rotateWebhookKey = () =>
	apiFetch( { path: `${ NS }/settings/webhook-key/rotate`, method: 'POST' } );
