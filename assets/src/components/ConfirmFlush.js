import { useEffect, useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { previewFlush } from '../api';
import { Badge } from '../ui';

/** Shows what "purge everything" would touch before anything is purged. */
export default function ConfirmFlush( { onConfirm, onCancel, busy } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		previewFlush( true ).then( setData ).catch( ( e ) => setError( e.message ) );
	}, [] );

	const layers = data?.jobs?.[ 0 ]?.layers || [];
	const active = layers.filter( ( l ) => ! l.skipped );

	return (
		<Modal title={ __( 'Purge everything?', 'purgekit' ) } onRequestClose={ onCancel } className="purgekit-modal">
			<p>{ __( 'Every page will be rebuilt on the next visit, so the site can feel slower for a moment. PurgeKit purges from the inside out and warms the cache afterwards.', 'purgekit' ) }</p>
			{ error && <p className="purgekit-note is-bad">{ error }</p> }
			{ ! data && ! error && <p className="purgekit-layer__when">{ __( 'Checking what would be purged…', 'purgekit' ) }</p> }
			{ data && (
				<ol className="purgekit-confirm__layers" aria-label={ __( 'Layers that will be purged, in order', 'purgekit' ) }>
					{ layers.map( ( l ) => (
						<li key={ l.id } className="purgekit-layer__line">
							<strong>{ l.label }</strong>
							{ l.skipped ? <Badge tone="muted">{ l.skipped }</Badge> : <Badge tone="ok">{ __( 'Will be purged', 'purgekit' ) }</Badge> }
						</li>
					) ) }
				</ol>
			) }
			{ data && active.length === 0 && <p className="purgekit-note is-bad">{ __( 'No layer is active, so nothing would be purged.', 'purgekit' ) }</p> }
			<div className="purgekit-modal__actions">
				<Button variant="tertiary" onClick={ onCancel }>{ __( 'Cancel', 'purgekit' ) }</Button>
				<Button variant="primary" isDestructive isBusy={ busy } disabled={ busy || ! data || active.length === 0 } onClick={ onConfirm }>
					{ __( 'Purge everything', 'purgekit' ) }
				</Button>
			</div>
		</Modal>
	);
}
