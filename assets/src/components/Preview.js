import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { previewFlush } from '../api';

/** Dry run: shows what the next queued flush would do. Nothing is purged and the queue is not touched. */
export default function Preview() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const run = async () => {
		setBusy( true );
		setError( null );
		try {
			setData( await previewFlush() );
		} catch ( e ) {
			setData( null );
			setError( e.message );
		}
		setBusy( false );
	};

	return (
		<div className="purgekit-preview">
			<Button variant="secondary" onClick={ run } isBusy={ busy } disabled={ busy }>
				{ __( 'Preview', 'purgekit' ) }
			</Button>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ data && <PreviewResult data={ data } /> }
		</div>
	);
}

function PreviewResult( { data } ) {
	return (
		<div>
			<p>
				{ sprintf(
					/* translators: %d: number of queued changes */
					__( 'Preview of the queued flush (%d queued changes). Nothing was purged.', 'purgekit' ),
					data.pending
				) }
			</p>
			{ data.empty ? (
				<p>{ __( 'Nothing would be purged.', 'purgekit' ) }</p>
			) : (
				<ul>
					{ data.jobs.map( ( job, i ) => (
						<li key={ i }>
							<strong>
								{ job.all
									? __( 'Everything', 'purgekit' )
									: sprintf(
											/* translators: %d: number of URLs */
											__( '%d URLs', 'purgekit' ),
											job.urls_total
									  ) }
							</strong>
							{ ' ' }
							{ job.rules.length > 0
								? sprintf( /* translators: %s: rule names */ __( 'by rule: %s', 'purgekit' ), job.rules.join( ', ' ) )
								: __( 'by default (no rule)', 'purgekit' ) }
							{ ' — ' }
							{ job.layers.length > 0 ? job.layers.map( ( l ) => l.label ).join( ', ' ) : __( 'no active layer', 'purgekit' ) }
							{ ! job.all && job.urls.length > 0 && (
								<ul>
									{ job.urls.map( ( u ) => (
										<li key={ u }>
											<code>{ u }</code>
										</li>
									) ) }
									{ job.urls_total > job.urls.length && (
										<li>
											{ sprintf(
												/* translators: %d: number of URLs not listed */
												__( 'and %d more', 'purgekit' ),
												job.urls_total - job.urls.length
											) }
										</li>
									) }
								</ul>
							) }
						</li>
					) ) }
				</ul>
			) }
			{ data.skipped.length > 0 && (
				<div>
					<p>{ __( 'Skipped by rules:', 'purgekit' ) }</p>
					<ul>
						{ data.skipped.map( ( c, i ) => (
							<li key={ i }>
								{ sprintf(
									/* translators: 1: change type, 2: rule name */
									__( '%1$s (rule: %2$s)', 'purgekit' ),
									c.type,
									c.rule
								) }
							</li>
						) ) }
					</ul>
				</div>
			) }
		</div>
	);
}
