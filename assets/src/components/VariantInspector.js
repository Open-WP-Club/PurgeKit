import { useState } from '@wordpress/element';
import { Button, Notice, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { inspectVariants } from '../api';
import CacheReport from './CacheReport';

export default function VariantInspector( { url, disabled } ) {
	const [ variants, setVariants ] = useState( [ { device: 'desktop', language: '', url: '' }, { device: 'mobile', language: '', url: '' } ] );
	const [ results, setResults ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const set = ( i, key, value ) => setVariants( ( rows ) => rows.map( ( row, n ) => n === i ? { ...row, [ key ]: value } : row ) );
	const run = async () => {
		setBusy( true );
		setError( '' );
		setResults( null );
		try {
			const data = await inspectVariants( variants.map( ( v ) => ( { ...v, url: v.url || url } ) ) );
			setResults( data.variants );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setBusy( false );
		}
	};
	return (
		<details>
			<summary>{ __( 'Compare cache variants', 'purgekit' ) }</summary>
			<p>{ __( 'Compare anonymous desktop/mobile visits, language headers and URLs with different query parameters. For sites that use language paths, enter each translated URL. A cache-busting request may still be cached; differences can also come from dynamic content.', 'purgekit' ) }</p>
			{ variants.map( ( v, i ) => (
				<fieldset key={ i } disabled={ busy }>
					<legend>{ __( 'Variant', 'purgekit' ) } { i + 1 }</legend>
					<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Device', 'purgekit' ) } value={ v.device } options={ [ { value: 'desktop', label: __( 'Desktop', 'purgekit' ) }, { value: 'mobile', label: __( 'Mobile', 'purgekit' ) } ] } onChange={ ( value ) => set( i, 'device', value ) } />
					<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Language code (optional)', 'purgekit' ) } placeholder="bg-BG" value={ v.language } onChange={ ( value ) => set( i, 'language', value ) } />
					<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Variant URL (optional)', 'purgekit' ) } help={ __( 'Leave empty to use the page URL above. Include any query parameters here.', 'purgekit' ) } value={ v.url } onChange={ ( value ) => set( i, 'url', value ) } />
					{ variants.length > 1 && <Button variant="tertiary" onClick={ () => setVariants( variants.filter( ( _, n ) => n !== i ) ) }>{ __( 'Remove variant', 'purgekit' ) }</Button> }
				</fieldset>
			) ) }
			<Button variant="secondary" disabled={ busy || variants.length >= 4 } onClick={ () => setVariants( [ ...variants, { device: 'desktop', language: '', url: '' } ] ) }>{ __( 'Add variant', 'purgekit' ) }</Button>{ ' ' }
			<Button variant="primary" disabled={ disabled || busy || variants.some( ( v ) => ! ( v.url || url ) ) } isBusy={ busy } onClick={ run }>{ busy ? __( 'Comparing…', 'purgekit' ) : __( 'Compare variants', 'purgekit' ) }</Button>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ results?.map( ( v, i ) => <section key={ i }><h3>{ v.device } · { v.language || __( 'Default language', 'purgekit' ) }</h3><code>{ v.url }</code><p>{ v.inspection.message }</p><CacheReport report={ v.cacheability } /></section> ) }
		</details>
	);
}
