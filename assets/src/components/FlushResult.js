import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, StatusBadge } from '../ui';

/** What a flush did, layer by layer, with a way to try the failed layers again. */
export default function FlushResult( { result, labels = {}, retrying, onRetry, onDismiss } ) {
	const failed = ( result.results || [] ).filter( ( r ) => r.status === 'failed' );
	const v = result.verification;
	return (
		<div className="purgekit-result" role="status" aria-live="polite">
			<div className="purgekit-result__head">
				<strong>{ __( 'Last flush', 'purgekit' ) }</strong>
				<StatusBadge status={ result.status } />
				<span className="purgekit-layer__when">
					{ result.all
						? __( 'Everything', 'purgekit' )
						: sprintf( /* translators: %d: number of URLs */ __( '%d URLs', 'purgekit' ), ( result.urls || [] ).length ) }
				</span>
				<Button variant="tertiary" size="small" onClick={ onDismiss } className="purgekit-result__close">
					{ __( 'Dismiss', 'purgekit' ) }
				</Button>
			</div>
			{ result.status === 'skipped' && result.deferred === 0 && (
				<p className="purgekit-layer__when">{ __( 'Nothing was purged: no layer was active, or every layer is switched off or paused.', 'purgekit' ) }</p>
			) }
			{ ( result.results || [] ).length > 0 && (
				<ul className="purgekit-result__layers">
					{ result.results.map( ( r ) => (
						<li key={ r.layer } className="purgekit-layer__line">
							<span className="purgekit-layer__label">{ labels[ r.layer ] || r.layer }</span>
							<StatusBadge status={ r.status } />
							{ r.ms > 0 && <span className="purgekit-layer__when">{ `${ r.ms } ms` }</span> }
							{ r.message && <span className="purgekit-layer__msg">{ r.message }</span> }
						</li>
					) ) }
				</ul>
			) }
			{ result.deferred > 0 && (
				<p className="purgekit-layer__when">
					{ sprintf( /* translators: %d: number of jobs */ __( '%d more step(s) were postponed by a rule and run later. They appear in History when done.', 'purgekit' ), result.deferred ) }
				</p>
			) }
			{ v && (
				<div className="purgekit-layer__line">
					<span className="purgekit-layer__label">{ __( 'Check', 'purgekit' ) }</span>
					<Badge tone={ v.status === 'verified' ? 'ok' : v.status === 'stale' ? 'bad' : 'warn' }>
						{ v.status === 'verified' ? __( 'Fresh', 'purgekit' ) : v.status === 'stale' ? __( 'Stale copy', 'purgekit' ) : __( 'Not checked', 'purgekit' ) }
					</Badge>
					<span className="purgekit-layer__msg">{ v.message }</span>
				</div>
			) }
			{ failed.length > 0 && (
				<div className="purgekit-result__actions">
					<Button variant="secondary" isBusy={ retrying } disabled={ retrying } onClick={ () => onRetry( failed.map( ( r ) => r.layer ) ) }>
						{ sprintf( /* translators: %d: number of layers */ __( 'Retry %d failed layer(s)', 'purgekit' ), failed.length ) }
					</Button>
				</div>
			) }
		</div>
	);
}
