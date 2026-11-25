import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Choice, Group, Setting, ToggleSetting } from '../../ui';

export const MODES = [
	{ value: 'notify', label: __( 'Notify me', 'purgekit' ), description: __( 'Changes are collected and you flush by hand. The safest start.', 'purgekit' ) },
	{ value: 'auto', label: __( 'Flush automatically', 'purgekit' ), description: __( 'The affected pages are purged as soon as something changes.', 'purgekit' ) },
	{ value: 'scheduled', label: __( 'Scheduled flush', 'purgekit' ), description: __( 'Changes are collected and purged on a timetable, from hourly to weekly.', 'purgekit' ) },
];

export const SCHEDULES = [
	{ value: 'hourly', label: __( 'Every hour', 'purgekit' ) },
	{ value: '3h', label: __( 'Every 3 hours', 'purgekit' ) },
	{ value: '6h', label: __( 'Every 6 hours', 'purgekit' ) },
	{ value: '12h', label: __( 'Every 12 hours', 'purgekit' ) },
	{ value: 'daily', label: __( 'Every day', 'purgekit' ) },
	{ value: 'weekly', label: __( 'Every week', 'purgekit' ) },
];

export default function General( { form } ) {
	const { s, set, locked, resetLink } = form;
	return (
		<>
			<Group title={ __( 'When something changes', 'purgekit' ) }>
				<Choice legend={ __( 'When something changes', 'purgekit' ) } value={ s.mode } options={ MODES } onChange={ set( 'mode' ) } disabled={ locked( 'mode' ) } />
				{ resetLink( 'mode' ) }
				{ s.mode === 'scheduled' && (
					<>
						<Setting id="pk-schedule" label={ __( 'Schedule', 'purgekit' ) } locked={ locked( 'schedule' ) }>
							<SelectControl id="pk-schedule" __nextHasNoMarginBottom __next40pxDefaultSize  value={ s.schedule } options={ SCHEDULES } onChange={ set( 'schedule' ) } disabled={ locked( 'schedule' ) } />
						</Setting>
						{ resetLink( 'schedule' ) }
						<ToggleSetting id="pk-skip" label={ __( 'Skip if nothing changed', 'purgekit' ) } help={ __( 'A scheduled run without queued changes does nothing.', 'purgekit' ) } checked={ s.skip_if_unchanged } onChange={ set( 'skip_if_unchanged' ) } locked={ locked( 'skip_if_unchanged' ) } />
						{ resetLink( 'skip_if_unchanged' ) }
					</>
				) }
			</Group>
			<Group title={ __( 'After a purge', 'purgekit' ) }>
				<ToggleSetting id="pk-warm" label={ __( 'Warm the cache after a purge', 'purgekit' ) } help={ __( 'Visits the purged pages in the background so the first real visitor gets a cached page.', 'purgekit' ) } checked={ s.warm_enabled } onChange={ set( 'warm_enabled' ) } locked={ locked( 'warm_enabled' ) } />
				{ resetLink( 'warm_enabled' ) }
				<Setting id="pk-warm-limit" label={ __( 'Warm this many sitemap pages after purging everything', 'purgekit' ) } help={ __( 'Taken from wp-sitemap.xml, own pages only. 0 turns it off, 100 is the maximum.', 'purgekit' ) } locked={ locked( 'warm_sitemap_limit' ) }>
					<TextControl
						id="pk-warm-limit"
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						max={ 100 }
						value={ s.warm_sitemap_limit ?? 20 }
						onChange={ ( v ) => set( 'warm_sitemap_limit' )( v === '' ? 0 : parseInt( v, 10 ) || 0 ) }
						disabled={ locked( 'warm_sitemap_limit' ) }
					/>
				</Setting>
				<ToggleSetting id="pk-verify" label={ __( 'Check the home page and purged pages after a flush', 'purgekit' ) } help={ __( 'Fetches up to three own pages once the purge is done and says in the result and in History whether a cache layer still serves a stale copy.', 'purgekit' ) } checked={ !! s.verify_after_flush } onChange={ set( 'verify_after_flush' ) } locked={ locked( 'verify_after_flush' ) } />
			</Group>
		</>
	);
}
