import { registerPlugin } from '@wordpress/plugins';
import { useSelect } from '@wordpress/data';
import * as editor from '@wordpress/editor';
import * as editPost from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import CachePanel from './editor/CachePanel';

// The panel moved from wp.editPost to wp.editor in WordPress 6.6; this works on 6.4 and later.
const DocumentPanel = editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;

function PurgeKitPanel() {
	const { url, published, viewable } = useSelect( ( select ) => {
		const current = select( 'core/editor' );
		const type = select( 'core' ).getPostType( current.getCurrentPostType() );
		return {
			url: current.getPermalink(),
			published: current.getCurrentPostAttribute( 'status' ) === 'publish',
			viewable: !! type?.viewable,
		};
	}, [] );

	// Only public content has a front-end page to inspect.
	if ( ! viewable || ! DocumentPanel ) {
		return null;
	}
	return (
		<DocumentPanel name="purgekit-cache" title={ __( 'PurgeKit cache', 'purgekit' ) } className="purgekit-editor-panel">
			<CachePanel url={ url } published={ published } />
		</DocumentPanel>
	);
}

registerPlugin( 'purgekit', { render: PurgeKitPanel } );
