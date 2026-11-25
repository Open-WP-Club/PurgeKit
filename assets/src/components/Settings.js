import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Live } from '../ui';
import useSettingsForm from './settings/useSettingsForm';
import General from './settings/General';
import Protection from './settings/Protection';
import Caches from './settings/Caches';
import Connections from './settings/Connections';
import Alerts from './settings/Alerts';

export const SECTIONS = [
	{ name: 'general', title: __( 'General', 'purgekit' ), hint: __( 'When and how to purge', 'purgekit' ), Component: General },
	{ name: 'connections', title: __( 'Connections', 'purgekit' ), hint: __( 'Cloudflare, CDNs, webhook', 'purgekit' ), Component: Connections },
	{ name: 'caches', title: __( 'Caches', 'purgekit' ), hint: __( 'Server caches and cache tags', 'purgekit' ), Component: Caches },
	{ name: 'protection', title: __( 'Protection', 'purgekit' ), hint: __( 'Staging, storms, excluded pages', 'purgekit' ), Component: Protection },
	{ name: 'alerts', title: __( 'Alerts', 'purgekit' ), hint: __( 'Email and Slack', 'purgekit' ), Component: Alerts },
];

export default function Settings( { section: wanted, onSection } ) {
	const form = useSettingsForm();
	const [ local, setLocal ] = useState( 'general' );
	const section = wanted && SECTIONS.some( ( x ) => x.name === wanted ) ? wanted : local;
	useEffect( () => {
		if ( wanted && SECTIONS.some( ( x ) => x.name === wanted ) ) {
			setLocal( wanted );
		}
	}, [ wanted ] );

	if ( ! form.ready ) {
		return form.message ? <p className="purgekit-note is-bad" role="alert">{ form.message.text }</p> : <p className="purgekit-layer__when" role="status">{ __( 'Loading…', 'purgekit' ) }</p>;
	}
	const { s, dirty, saving, save, discard, message, setMessage } = form;
	const Current = SECTIONS.find( ( x ) => x.name === section ).Component;
	const pick = ( name ) => {
		setLocal( name );
		onSection?.( name );
	};

	return (
		<div className="purgekit-settings">
			<nav className="purgekit-subnav" aria-label={ __( 'Settings sections', 'purgekit' ) }>
				{ SECTIONS.map( ( x ) => (
					<button key={ x.name } type="button" className={ `purgekit-subnav__item${ x.name === section ? ' is-active' : '' }` } aria-current={ x.name === section ? 'page' : undefined } onClick={ () => pick( x.name ) }>
						<strong>{ x.title }</strong>
						<span>{ x.hint }</span>
					</button>
				) ) }
			</nav>
			<div className="purgekit-settings__body">
				{ ( s.locked || [] ).length > 0 && (
					<div className="purgekit-banner">
						<div>
							{ __( 'Set in wp-config.php and not editable here:', 'purgekit' ) } <code>{ s.locked.join( ', ' ) }</code>
						</div>
					</div>
				) }
				<Current form={ form } />
				<div className={ `purgekit-savebar${ dirty ? ' is-dirty' : '' }` }>
					<span className="purgekit-savebar__state">{ dirty ? __( 'You have unsaved changes.', 'purgekit' ) : __( 'All changes are saved.', 'purgekit' ) }</span>
					<Live message={ message } onClear={ () => setMessage( null ) } />
					<Button variant="tertiary" onClick={ discard } disabled={ ! dirty || saving }>{ __( 'Discard', 'purgekit' ) }</Button>
					<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>{ __( 'Save', 'purgekit' ) }</Button>
				</div>
			</div>
		</div>
	);
}
