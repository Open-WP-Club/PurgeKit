import apiFetch from '@wordpress/api-fetch';

const NS = '/purgekit/v1';

export const getStatus = () => apiFetch( { path: `${ NS }/status` } );
export const flush = ( urls ) =>
	apiFetch( { path: `${ NS }/flush`, method: 'POST', data: urls ? { urls } : {} } );
export const previewFlush = ( force = false ) =>
	apiFetch( { path: `${ NS }/flush/preview`, method: 'POST', data: force ? { force: true } : {} } );
export const getLayers = () => apiFetch( { path: `${ NS }/layers` } );
export const testLayer = ( id ) =>
	apiFetch( { path: `${ NS }/layers/${ encodeURIComponent( id ) }/test`, method: 'POST' } );
export const getHistory = ( page = 1, status = '' ) =>
	apiFetch( { path: `${ NS }/history?per_page=20&page=${ page }${ status ? `&status=${ status }` : '' }` } );
export const testAlerts = () => apiFetch( { path: `${ NS }/alerts/test`, method: 'POST' } );
export const getSettings = () => apiFetch( { path: `${ NS }/settings` } );
export const saveSettings = ( patch ) =>
	apiFetch( { path: `${ NS }/settings`, method: 'POST', data: patch } );
export const findCloudflareZone = ( token ) =>
	apiFetch( { path: `${ NS }/settings/cloudflare/find-zone`, method: 'POST', data: token ? { token } : {} } );
export const rotateWebhookKey = () =>
	apiFetch( { path: `${ NS }/settings/webhook-key/rotate`, method: 'POST' } );
export const inspectUrl = ( url ) =>
	apiFetch( { path: `${ NS }/inspect`, method: 'POST', data: { url } } );
export const purgeLayerUrl = ( id, url ) =>
	apiFetch( { path: `${ NS }/layers/${ encodeURIComponent( id ) }/purge`, method: 'POST', data: { url } } );
export const getRules = () => apiFetch( { path: `${ NS }/rules` } );
export const saveRules = ( rules ) =>
	apiFetch( { path: `${ NS }/rules`, method: 'PUT', data: { rules } } );
export const purgeLayer = ( id ) =>
	apiFetch( { path: `${ NS }/layers/${ encodeURIComponent( id ) }/purge-all`, method: 'POST' } );
export const setLayerEnabled = ( id, enabled ) =>
	apiFetch( { path: `${ NS }/layers/${ encodeURIComponent( id ) }/enabled`, method: 'POST', data: { enabled } } );
export const getScheduled = () => apiFetch( { path: `${ NS }/scheduled` } );
export const addScheduled = ( at, urls, note ) =>
	apiFetch( { path: `${ NS }/scheduled`, method: 'POST', data: { at, urls, note } } );
export const cancelScheduled = ( id ) =>
	apiFetch( { path: `${ NS }/scheduled/${ id }`, method: 'DELETE' } );
