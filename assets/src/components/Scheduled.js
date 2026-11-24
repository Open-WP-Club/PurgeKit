import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, TextareaControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addScheduled, cancelScheduled, getScheduled } from '../api';

export default function Scheduled() {
	const [ items, setItems ] = useState( [] );
	const [ when, setWhen ] = useState( '' );
	const [ urls, setUrls ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		getScheduled().then( setItems ).catch( ( e ) => setError( e.message ) );
	}, [] );

	const add = async () => {
		setError( '' );
		const at = Math.floor( new Date( when ).getTime() / 1000 );
		try {
			setItems( await addScheduled( at, urls.split( /\s+/ ).filter( Boolean ), note ) );
			setWhen( '' );
			setUrls( '' );
			setNote( '' );
		} catch ( e ) {
			setError( e.message );
		}
	};

	const cancel = async ( id ) => {
		try {
			setItems( await cancelScheduled( id ) );
		} catch ( e ) {
			setError( e.message );
		}
	};

	return (
		<div>
			<h3>{ __( 'Scheduled purges', 'purgekit' ) }</h3>
			<p>{ __( 'Purge at a set time, for example when a campaign goes live. Leave the addresses empty to purge everything.', 'purgekit' ) }</p>
			{ items.length === 0 && <p>{ __( 'Nothing scheduled.', 'purgekit' ) }</p> }
			{ items.length > 0 && (
				<ul>
					{ items.map( ( i ) => (
						<li key={ i.id }>
							{ new Date( i.at * 1000 ).toLocaleString() } —{ ' ' }
							{ i.urls.length ? `${ i.urls.length } ${ __( 'page(s)', 'purgekit' ) }` : __( 'everything', 'purgekit' ) }
							{ i.note ? ` — ${ i.note }` : '' }{ ' ' }
							<Button variant="link" isDestructive onClick={ () => cancel( i.id ) }>{ __( 'Cancel', 'purgekit' ) }</Button>
						</li>
					) ) }
				</ul>
			) }
			<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'When', 'purgekit' ) } type="datetime-local" value={ when } onChange={ setWhen } />
			<TextareaControl __nextHasNoMarginBottom label={ __( 'Addresses on this site (optional, one per line)', 'purgekit' ) } value={ urls } onChange={ setUrls } />
			<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Note (optional)', 'purgekit' ) } value={ note } onChange={ setNote } />
			<Button variant="secondary" disabled={ when === '' } onClick={ add }>{ __( 'Schedule purge', 'purgekit' ) }</Button>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
		</div>
	);
}
