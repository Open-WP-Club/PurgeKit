import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Status from './components/Status';
import Layers from './components/Layers';
import History from './components/History';
import Settings from './components/Settings';

const TABS = [
	{ name: 'dashboard', title: __( 'Dashboard', 'purgekit' ), Component: () => <><Status /><Layers /></> },
	{ name: 'history', title: __( 'History', 'purgekit' ), Component: History },
	{ name: 'settings', title: __( 'Settings', 'purgekit' ), Component: Settings },
];

export default function App() {
	return (
		<div className="wrap">
			<h1>PurgeKit</h1>
			<TabPanel tabs={ TABS }>
				{ ( tab ) => {
					const { Component } = TABS.find( ( t ) => t.name === tab.name );
					return <Component />;
				} }
			</TabPanel>
		</div>
	);
}
