import { useEffect, useState } from '@wordpress/element';
import { FormToggle } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/* Shared building blocks. Everything here follows the look of the layer map: rows separated by a thin line,
 * small status badges, muted secondary text and the actions on the right. */

export const STATUS_LABEL = {
	ok: __( 'OK', 'purgekit' ),
	failed: __( 'Failed', 'purgekit' ),
	partial: __( 'Partial', 'purgekit' ),
	skipped: __( 'Skipped', 'purgekit' ),
	locked: __( 'Busy', 'purgekit' ),
};
export const TONE = { ok: 'ok', failed: 'bad', partial: 'warn', skipped: 'muted', locked: 'warn' };

export const SOURCE_LABEL = {
	auto: __( 'Automatic flush', 'purgekit' ),
	schedule: __( 'Scheduled flush', 'purgekit' ),
	manual: __( 'Manual flush', 'purgekit' ),
	webhook: __( 'Deploy webhook', 'purgekit' ),
	cli: __( 'WP-CLI', 'purgekit' ),
	admin: __( 'Admin bar', 'purgekit' ),
	'admin-layer': __( 'Single layer', 'purgekit' ),
	'scheduled-purge': __( 'Scheduled purge', 'purgekit' ),
	delayed: __( 'Delayed rule job', 'purgekit' ),
	retry: __( 'Retry', 'purgekit' ),
	replay: __( 'Run again', 'purgekit' ),
	'asset-bump': __( 'Asset reload', 'purgekit' ),
};
export const sourceLabel = ( source ) => SOURCE_LABEL[ source ] || source;

/** History stores a GMT string, connection checks a unix time. */
export const toDate = ( at ) => ( typeof at === 'number' ? new Date( at * 1000 ) : new Date( String( at ).replace( ' ', 'T' ) + 'Z' ) );

export const ago = ( at ) => {
	const d = toDate( at );
	if ( Number.isNaN( d.getTime() ) ) {
		return '';
	}
	const seconds = Math.round( ( d.getTime() - Date.now() ) / 1000 );
	const rtf = new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } );
	for ( const [ unit, size ] of [ [ 'day', 86400 ], [ 'hour', 3600 ], [ 'minute', 60 ] ] ) {
		if ( Math.abs( seconds ) >= size ) {
			return rtf.format( Math.round( seconds / size ), unit );
		}
	}
	return rtf.format( 0, 'second' );
};

/** Who or what started a history row: "by admin · via WP-CLI · rule: Plugin first". */
export const triggerParts = ( row ) => {
	const t = row.trigger || {};
	const parts = [];
	if ( t.user?.name ) {
		parts.push( sprintf( /* translators: %s: user name */ __( 'by %s', 'purgekit' ), t.user.name ) );
	}
	if ( t.rules?.length ) {
		parts.push( sprintf( /* translators: %s: rule names */ __( 'rule: %s', 'purgekit' ), t.rules.join( ', ' ) ) );
	}
	if ( t.changes?.length ) {
		parts.push( sprintf( /* translators: %s: kinds of change, such as post or term */ __( 'after: %s', 'purgekit' ), t.changes.join( ', ' ) ) );
	}
	return parts;
};

export function Badge( { tone, children } ) {
	return <span className={ `purgekit-badge is-${ tone }` }>{ children }</span>;
}

export const StatusBadge = ( { status } ) => <Badge tone={ TONE[ status ] || 'muted' }>{ STATUS_LABEL[ status ] || status }</Badge>;

/** A small heading above a group of rows, like the tier names in the layer map. */
export function Group( { title, children, className = '' } ) {
	return (
		<section className={ `purgekit-group ${ className }` }>
			{ title && <h3 className="purgekit-group__title">{ title }</h3> }
			{ children }
		</section>
	);
}

/** One row of a form: the name and explanation on the left, the control on the right. */
export function Setting( { id, label, help, locked, stacked, children } ) {
	return (
		<div className={ `purgekit-setting${ stacked ? ' is-stacked' : '' }` }>
			<div className="purgekit-setting__text">
				<div className="purgekit-setting__name">
					<label htmlFor={ id }>{ label }</label>
					{ locked && <Badge tone="muted">{ __( 'Set in wp-config.php', 'purgekit' ) }</Badge> }
				</div>
				{ help && <p className="purgekit-setting__help">{ help }</p> }
			</div>
			<div className="purgekit-setting__control">{ children }</div>
		</div>
	);
}

/** An on/off row. */
export function ToggleSetting( { id, label, help, checked, onChange, locked } ) {
	return (
		<Setting id={ id } label={ label } help={ help } locked={ locked }>
			<FormToggle id={ id } checked={ !! checked } disabled={ locked } onChange={ ( e ) => onChange( e.target.checked ) } />
		</Setting>
	);
}

/** Radio cards, used for the mode in Settings and in the first-run setup. */
export function Choice( { legend, value, options, onChange, disabled } ) {
	return (
		<fieldset className="purgekit-choices">
			<legend>{ legend }</legend>
			{ options.map( ( o ) => (
				<label key={ o.value } className={ `purgekit-choice${ value === o.value ? ' is-selected' : '' }` }>
					<input type="radio" name={ legend } value={ o.value } checked={ value === o.value } disabled={ disabled } onChange={ () => onChange( o.value ) } />
					<span className="purgekit-choice__body">
						<strong>
							{ o.label } { o.badge && <Badge tone="ok">{ o.badge }</Badge> }
						</strong>
						<span className="purgekit-choice__text">{ o.description }</span>
					</span>
				</label>
			) ) }
		</fieldset>
	);
}

/**
 * A message that screen readers announce (role="status"). Success messages go away by themselves;
 * errors stay until the next action.
 */
export function Live( { message, onClear, ms = 7000 } ) {
	useEffect( () => {
		if ( ! message || message.status === 'error' || ! onClear ) {
			return undefined;
		}
		const t = setTimeout( onClear, ms );
		return () => clearTimeout( t );
	}, [ message ] );
	return (
		<div className="purgekit-live" role={ message?.status === 'error' ? 'alert' : 'status' } aria-live="polite">
			{ message && <div className={ `purgekit-note is-${ message.status === 'error' ? 'bad' : 'ok' }` }>{ message.text }</div> }
		</div>
	);
}

/** Small hook for "do this, show the outcome". */
export function useMessage() {
	const [ message, setMessage ] = useState( null );
	return [ message, setMessage, () => setMessage( null ) ];
}
