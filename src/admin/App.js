import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import BulkTab from './BulkTab';
import Dashboard from './Dashboard';
import GapsTab from './GapsTab';
import HistoryTab from './HistoryTab';
import LinksTab from './LinksTab';
import PostScreen from './PostScreen';
import ReviewTab from './ReviewTab';
import SettingsTab from './SettingsTab';
import { parseParams } from './util';

const ROUTES = [ 'dashboard', 'bulk', 'review', 'links', 'gaps', 'history', 'post', 'settings', 'api', 'usage' ];

export default function App() {
	const [ route ] = useHashRoute( ROUTES, 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );

	const loadStatus = () => request( 'status' ).then( setStatus ).catch( () => {} );
	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
		loadStatus();
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			loadStatus();
			return next;
		} );

	const onUsage = () => {
		setMeter( ( n ) => n + 1 );
		loadStatus();
	};
	const pending = status && status.counts ? status.counts.pending : 0;
	const nav = [
		{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-seo-assistant' ), icon: 'dashboard' },
		{ id: 'bulk', label: __( 'Bulk optimize', 'dev3-ai-seo-assistant' ), icon: 'bolt' },
		{ id: 'review', label: __( 'Review queue', 'dev3-ai-seo-assistant' ), icon: 'diff', badge: pending ? String( pending ) : undefined },
		{ id: 'links', label: __( 'Internal links', 'dev3-ai-seo-assistant' ), icon: 'external' },
		{ id: 'gaps', label: __( 'Content gaps', 'dev3-ai-seo-assistant' ), icon: 'search' },
		{ id: 'history', label: __( 'History', 'dev3-ai-seo-assistant' ), icon: 'clock' },
		{ id: 'settings', label: __( 'Settings', 'dev3-ai-seo-assistant' ), icon: 'settings' },
		{ id: 'api', label: __( 'API Config', 'dev3-ai-seo-assistant' ), icon: 'key' },
		{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-seo-assistant' ), icon: 'chart' },
	];
	const params = parseParams( route.params );
	const common = { settings, status, onUsage, params, refresh: loadStatus };

	const screens = {
		dashboard: () => <Dashboard { ...common } />,
		bulk: () => <BulkTab { ...common } />,
		review: () => <ReviewTab { ...common } />,
		links: () => <LinksTab { ...common } />,
		gaps: () => <GapsTab { ...common } />,
		history: () => <HistoryTab { ...common } />,
		post: () => <PostScreen { ...common } postId={ Number( route.params[ 0 ] ) } section={ route.params[ 1 ] } />,
		settings: () => <SettingsTab settings={ settings } save={ save } status={ status } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-seo-assistant' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-seo-assistant' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-seo-assistant' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-seo-assistant' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI SEO Assistant for Yoast & Rank Math' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ nav }
			active={ route.id === 'post' ? 'bulk' : route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-seo-assistant' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
