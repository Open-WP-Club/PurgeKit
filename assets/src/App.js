import { useEffect, useState } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getStatus } from './api';
import Dashboard from './components/Dashboard';
import History from './components/History';
import Compare from './components/Compare';
import Impact from './components/Impact';
import Health from './components/Health';
import Inspector from './components/Inspector';
import Rules from './components/Rules';
import Settings from './components/Settings';
import Wizard from './components/Wizard';

const hashTarget = () => {
	const [ tab, section ] = window.location.hash.replace( '#', '' ).split( ':' );
	return { tab: tab || 'dashboard', section: section || '' };
};

export default function App() {
	const [ target, setTarget ] = useState( hashTarget );
	const [ wizard, setWizard ] = useState( false );
	const [ epoch, setEpoch ] = useState( 0 );

	useEffect( () => {
		const onHash = () => setTarget( hashTarget() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	// First run: show the short setup until it was finished or skipped.
	useEffect( () => {
		getStatus().then( ( s ) => setWizard( s && s.onboarded === false ) ).catch( () => {} );
	}, [] );

	/** "settings:alerts" opens the Settings tab on its Alerts section. */
	const goTo = ( to ) => {
		window.location.hash = to;
		window.scrollTo?.( 0, 0 );
	};

	const TABS = [
		{ name: 'dashboard', title: __( 'Dashboard', 'purgekit' ) },
		{ name: 'inspector', title: __( 'Inspector', 'purgekit' ) },
		{ name: 'rules', title: __( 'Rules', 'purgekit' ) },
		{ name: 'history', title: __( 'History', 'purgekit' ) },
		{ name: 'impact', title: __( 'Impact', 'purgekit' ) },
		{ name: 'compare', title: __( 'Compare', 'purgekit' ) },
		{ name: 'health', title: __( 'Health', 'purgekit' ) },
		{ name: 'settings', title: __( 'Settings', 'purgekit' ) },
	];
	const known = TABS.some( ( t ) => t.name === target.tab ) ? target.tab : 'dashboard';

	return (
		<div className="wrap purgekit">
			<h1 className="purgekit__title">
				PurgeKit <small>{ __( 'Cache purging across every layer', 'purgekit' ) }</small>
			</h1>
			{ wizard && <Wizard
					onDone={ () => {
						setWizard( false );
						setEpoch( ( e ) => e + 1 );
					} }
				/> }
			{ ! wizard && (
			<TabPanel
				key={ `${ known }-${ epoch }` }
				tabs={ TABS }
				initialTabName={ known }
				onSelect={ ( name ) => {
					if ( name !== known ) {
						window.history.replaceState( null, '', `#${ name }` );
						setTarget( { tab: name, section: '' } );
					}
				} }
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'dashboard':
							return <Dashboard goTo={ goTo } />;
						case 'settings':
							return (
								<section className="purgekit-card">
									<Settings section={ target.section } onSection={ ( s ) => window.history.replaceState( null, '', `#settings:${ s }` ) } />
								</section>
							);
						case 'compare':
							return (
								<section className="purgekit-card">
									<Compare goTo={ goTo } />
								</section>
							);
						case 'health':
							return (
								<section className="purgekit-card">
									<Health goTo={ goTo } />
								</section>
							);
						default: {
							const Component = { inspector: Inspector, rules: Rules, history: History, impact: Impact }[ tab.name ];
							return (
								<section className="purgekit-card">
									<Component />
								</section>
							);
						}
					}
				} }
			</TabPanel>
			) }
		</div>
	);
}
