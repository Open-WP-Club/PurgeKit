import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getSettingsExport, importSettings } from '../api';

/** Copy a setup between sites: a JSON file with the settings, the provider ids and the rules, never the secrets. */
export default function Portability( { onImported } ) {
	const [ message, setMessage ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const download = async () => {
		setMessage( null );
		try {
			const data = await getSettingsExport();
			const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = 'purgekit-settings.json';
			a.click();
			URL.revokeObjectURL( url );
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
	};

	const upload = async ( event ) => {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';
		if ( ! file ) {
			return;
		}
		setBusy( true );
		setMessage( null );
		try {
			const data = JSON.parse( await file.text() );
			const r = await importSettings( data );
			const parts = [ sprintf( /* translators: %d: number of settings */ __( '%d settings applied.', 'purgekit' ), r.applied.length ) ];
			if ( r.rules !== null ) {
				parts.push( sprintf( /* translators: %d: number of rules */ __( '%d rules imported.', 'purgekit' ), r.rules ) );
			}
			if ( r.ignored.length ) {
				parts.push( sprintf( /* translators: %s: list of setting names */ __( 'Not applied: %s.', 'purgekit' ), r.ignored.join( ', ' ) ) );
			}
			setMessage( { status: 'success', text: parts.join( ' ' ) } );
			onImported?.();
		} catch ( e ) {
			setMessage( { status: 'error', text: e instanceof SyntaxError ? __( 'That file is not valid JSON.', 'purgekit' ) : e.message } );
		}
		setBusy( false );
	};

	return (
		<div className="purgekit-portability">
			<h3>{ __( 'Export and import', 'purgekit' ) }</h3>
			<p>
				{ __( 'Copy these settings and the saved rules to another site. API tokens, webhook keys and the Slack address are never exported; set them on each site, or with constants in wp-config.php.', 'purgekit' ) }
			</p>
			<Button variant="secondary" onClick={ download }>{ __( 'Download settings', 'purgekit' ) }</Button>{ ' ' }
			<label className="components-button is-secondary" style={ { cursor: busy ? 'wait' : 'pointer' } }>
				{ __( 'Import settings…', 'purgekit' ) }
				<input type="file" accept="application/json,.json" onChange={ upload } disabled={ busy } hidden />
			</label>
			{ message && <Notice status={ message.status } isDismissible={ false }>{ message.text }</Notice> }
		</div>
	);
}
