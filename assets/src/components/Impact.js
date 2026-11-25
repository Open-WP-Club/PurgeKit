import { useEffect, useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getImpact } from '../api';
import { Group, sourceLabel } from '../ui';

const RANGES = [
	{ value: '7', label: __( 'Last 7 days', 'purgekit' ) },
	{ value: '30', label: __( 'Last 30 days', 'purgekit' ) },
	{ value: '90', label: __( 'Last 90 days', 'purgekit' ) },
];

function Stat( { title, value, hint } ) {
	return (
		<div className="purgekit-tile">
			<span className="purgekit-tile__title">{ title }</span>
			<div className="purgekit-tile__value">
				<strong className="purgekit-tile__number">{ value }</strong>
			</div>
			{ hint && <span className="purgekit-tile__hint">{ hint }</span> }
		</div>
	);
}

/** Flushes per day: the part that only touched some pages, the part that purged everything, and the days with problems. */
function Chart( { days } ) {
	const max = Math.max( 1, ...days.map( ( d ) => d.full + d.targeted ) );
	return (
		<div className="purgekit-impact__chart" role="img" aria-label={ __( 'Flushes per day. The numbers are in the table below.', 'purgekit' ) }>
			{ days.map( ( d ) => (
				<span key={ d.date } className={ `purgekit-impact__day${ d.problems > 0 ? ' has-problems' : '' }` } title={ `${ d.date }: ${ d.targeted } + ${ d.full }` }>
					<span className="purgekit-impact__full" style={ { height: `${ ( d.full / max ) * 100 }%` } } />
					<span className="purgekit-impact__targeted" style={ { height: `${ ( d.targeted / max ) * 100 }%` } } />
				</span>
			) ) }
		</div>
	);
}

function Bars( { rows, label } ) {
	const max = Math.max( 1, ...rows.map( ( r ) => r[ 1 ] ) );
	return (
		<ul className="purgekit-impact__list">
			{ rows.map( ( [ key, n ] ) => (
				<li key={ key }>
					<span className="purgekit-impact__name">{ label( key ) }</span>
					<span className="purgekit-impact__bar" style={ { width: `${ ( n / max ) * 100 }%` } } />
					<span className="purgekit-layer__when">{ n }</span>
				</li>
			) ) }
		</ul>
	);
}

export default function Impact() {
	const [ days, setDays ] = useState( '30' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		setError( '' );
		getImpact( Number( days ) ).then( setData ).catch( ( e ) => setError( e.message ) );
	}, [ days ] );

	if ( error ) {
		return <p className="purgekit-note is-bad" role="alert">{ error }</p>;
	}
	if ( ! data ) {
		return <p className="purgekit-layer__when" role="status">{ __( 'Loading…', 'purgekit' ) }</p>;
	}
	if ( data.flushes === 0 ) {
		return <p>{ __( 'Nothing was purged in this time. Edit a post and the numbers start to fill in.', 'purgekit' ) }</p>;
	}

	return (
		<div>
			<div className="purgekit-layers__bar">
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Period', 'purgekit' ) } value={ days } options={ RANGES } onChange={ setDays } />
			</div>
			<div className="purgekit-tiles">
				<Stat title={ __( 'Flushes', 'purgekit' ) } value={ data.flushes } hint={ sprintf( /* translators: %d: milliseconds */ __( '%d ms on average', 'purgekit' ), data.avg_ms ) } />
				<Stat
					title={ __( 'Targeted', 'purgekit' ) }
					value={ `${ data.targeted_share }%` }
					hint={ sprintf( /* translators: 1: targeted flushes, 2: full flushes */ __( '%1$d of them purged only some pages, %2$d purged everything', 'purgekit' ), data.targeted, data.full ) }
				/>
				<Stat title={ __( 'Pages purged', 'purgekit' ) } value={ data.urls } hint={ __( 'In targeted flushes. Everything else stayed cached.', 'purgekit' ) } />
				<Stat title={ __( 'With problems', 'purgekit' ) } value={ data.problems } hint={ sprintf( /* translators: %d: number of layer purges */ __( '%d layer purges worked', 'purgekit' ), data.layer_purges ) } />
			</div>

			<Group title={ __( 'Flushes per day', 'purgekit' ) }>
				<Chart days={ data.per_day } />
				<p className="purgekit-setting__help">
					<span className="purgekit-impact__key is-targeted" /> { __( 'targeted', 'purgekit' ) } <span className="purgekit-impact__key is-full" /> { __( 'everything', 'purgekit' ) } <span className="purgekit-impact__key is-problem" /> { __( 'a day with problems', 'purgekit' ) }
				</p>
				<details>
					<summary>{ __( 'Show the numbers', 'purgekit' ) }</summary>
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Day', 'purgekit' ) }</th>
								<th>{ __( 'Targeted', 'purgekit' ) }</th>
								<th>{ __( 'Everything', 'purgekit' ) }</th>
								<th>{ __( 'Problems', 'purgekit' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ data.per_day.map( ( d ) => (
								<tr key={ d.date }>
									<td>{ d.date }</td>
									<td>{ d.targeted }</td>
									<td>{ d.full }</td>
									<td>{ d.problems }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</details>
			</Group>

			<Group title={ __( 'What started the flushes', 'purgekit' ) }>
				<Bars rows={ Object.entries( data.by_source ) } label={ sourceLabel } />
			</Group>
			{ Object.keys( data.by_change ).length > 0 && (
				<Group title={ __( 'What changed on the site', 'purgekit' ) }>
					<Bars rows={ Object.entries( data.by_change ) } label={ ( k ) => k } />
				</Group>
			) }
		</div>
	);
}
