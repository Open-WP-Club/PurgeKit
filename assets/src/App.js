import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Status from './components/Status';
import Layers from './components/Layers';
import History from './components/History';
import Settings from './components/Settings';
import Inspector from './components/Inspector';
import Rules from './components/Rules';

const TABS = [
	{
		name: 'dashboard',
		title: __( 'Dashboard', 'purgekit' ),
		Component: () => (
			<>
				<section className="purgekit-card">
					<h2>{ __( 'Cache status', 'purgekit' ) }</h2>
					<Status />
				</section>
				<section className="purgekit-card">
					<h2>{ __( 'Cache layers', 'purgekit' ) }</h2>
					<Layers />
				</section>
			</>
		),
	},
	{ name: 'inspector', title: __( 'Inspector', 'purgekit' ), Component: Inspector },
	{ name: 'rules', title: __( 'Rules', 'purgekit' ), Component: Rules },
	{ name: 'history', title: __( 'History', 'purgekit' ), Component: History },
	{ name: 'settings', title: __( 'Settings', 'purgekit' ), Component: Settings },
];

export default function App() {
	return (
		<div className="wrap purgekit">
			<h1 className="purgekit__title">
				PurgeKit <small>{ __( 'Cache purging across every layer', 'purgekit' ) }</small>
			</h1>
			<TabPanel tabs={ TABS }>
				{ ( tab ) => {
					const { Component } = TABS.find( ( t ) => t.name === tab.name );
					// The dashboard builds its own cards; every other tab is one card.
					return tab.name === 'dashboard' ? (
						<Component />
					) : (
						<section className="purgekit-card">
							<Component />
						</section>
					);
				} }
			</TabPanel>
		</div>
	);
}
