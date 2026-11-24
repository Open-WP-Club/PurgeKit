import { useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getLayers, getRules, saveRules } from '../api';
import Preview from './Preview';

const ACTION_LABELS = {
	default: __( 'Purge the affected pages', 'purgekit' ),
	all: __( 'Purge everything', 'purgekit' ),
	home: __( 'Purge only the home page', 'purgekit' ),
	skip: __( 'Do nothing', 'purgekit' ),
};

const blank = () => ( {
	id: '',
	name: __( 'New rule', 'purgekit' ),
	enabled: true,
	on: [],
	post_types: [],
	action: 'default',
	layers: [],
} );

const toggle = ( list, value, on ) => ( on ? [ ...list, value ] : list.filter( ( v ) => v !== value ) );

export default function Rules() {
	const [ rules, setRules ] = useState( null );
	const [ meta, setMeta ] = useState( { triggers: {}, actions: [], max: 50 } );
	const [ layers, setLayers ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState( null );

	useEffect( () => {
		Promise.all( [ getRules(), getLayers() ] )
			.then( ( [ r, l ] ) => {
				setRules( r.rules );
				setMeta( r );
				setLayers( l );
			} )
			.catch( ( e ) => setMessage( { status: 'error', text: e.message } ) );
	}, [] );

	if ( rules === null ) {
		return message ? <Notice status="error" isDismissible={ false }>{ message.text }</Notice> : <p>{ __( 'Loading…', 'purgekit' ) }</p>;
	}

	const change = ( i, patch ) => setRules( rules.map( ( r, n ) => ( n === i ? { ...r, ...patch } : r ) ) );
	const move = ( i, by ) => {
		const next = [ ...rules ];
		[ next[ i ], next[ i + by ] ] = [ next[ i + by ], next[ i ] ];
		setRules( next );
	};

	const save = async () => {
		setBusy( true );
		setMessage( null );
		try {
			const r = await saveRules( rules );
			setRules( r.rules );
			setMessage( { status: 'success', text: __( 'Rules saved.', 'purgekit' ) } );
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
		setBusy( false );
	};

	return (
		<div>
			<p>
				{ __( 'Rules decide what happens when something changes. The first enabled rule that matches a change is used; changes no rule matches are purged the normal way, on every layer.', 'purgekit' ) }
			</p>
			{ rules.length === 0 && <p>{ __( 'No rules yet.', 'purgekit' ) }</p> }
			{ rules.map( ( rule, i ) => (
				<details key={ rule.id || i } open={ ! rule.id } className="purgekit-rule" style={ { marginBottom: 12 } }>
					<summary>
						<strong>{ rule.name }</strong>
						{ ! rule.enabled && ` (${ __( 'off', 'purgekit' ) })` } — { ACTION_LABELS[ rule.action ] }
					</summary>
					<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Name', 'purgekit' ) } value={ rule.name } onChange={ ( name ) => change( i, { name } ) } />
					<CheckboxControl __nextHasNoMarginBottom label={ __( 'Enabled', 'purgekit' ) } checked={ rule.enabled } onChange={ ( enabled ) => change( i, { enabled } ) } />
					<fieldset>
						<legend>{ __( 'When (none ticked means any change)', 'purgekit' ) }</legend>
						{ Object.entries( meta.triggers ).map( ( [ key, label ] ) => (
							<CheckboxControl key={ key } __nextHasNoMarginBottom label={ label } checked={ rule.on.includes( key ) } onChange={ ( on ) => change( i, { on: toggle( rule.on, key, on ) } ) } />
						) ) }
					</fieldset>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Only for these post types (comma separated, empty means all)', 'purgekit' ) }
						value={ rule.post_types.join( ', ' ) }
						onChange={ ( v ) => change( i, { post_types: v.split( ',' ).map( ( t ) => t.trim() ).filter( Boolean ) } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Then', 'purgekit' ) }
						value={ rule.action }
						options={ meta.actions.map( ( a ) => ( { value: a, label: ACTION_LABELS[ a ] || a } ) ) }
						onChange={ ( action ) => change( i, { action } ) }
					/>
					{ rule.action !== 'skip' && (
						<fieldset>
							<legend>{ __( 'On these layers (none ticked means every active layer)', 'purgekit' ) }</legend>
							{ layers.map( ( l ) => (
								<CheckboxControl key={ l.id } __nextHasNoMarginBottom label={ l.label } checked={ rule.layers.includes( l.id ) } onChange={ ( on ) => change( i, { layers: toggle( rule.layers, l.id, on ) } ) } />
							) ) }
						</fieldset>
					) }
					<Button variant="tertiary" disabled={ i === 0 } onClick={ () => move( i, -1 ) }>{ __( 'Move up', 'purgekit' ) }</Button>
					<Button variant="tertiary" disabled={ i === rules.length - 1 } onClick={ () => move( i, 1 ) }>{ __( 'Move down', 'purgekit' ) }</Button>
					<Button variant="tertiary" isDestructive onClick={ () => setRules( rules.filter( ( _, n ) => n !== i ) ) }>{ __( 'Delete', 'purgekit' ) }</Button>
				</details>
			) ) }
			<Button variant="secondary" disabled={ rules.length >= meta.max } onClick={ () => setRules( [ ...rules, blank() ] ) }>
				{ __( 'Add rule', 'purgekit' ) }
			</Button>{ ' ' }
			<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ save }>{ __( 'Save rules', 'purgekit' ) }</Button>
			{ message && <Notice status={ message.status } isDismissible={ false }>{ message.text }</Notice> }
			<hr />
			<p>{ __( 'Save your rules first, then preview what the current queue would do with them.', 'purgekit' ) }</p>
			<Preview />
		</div>
	);
}
