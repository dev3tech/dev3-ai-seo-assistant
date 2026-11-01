import * as editorPkg from '@wordpress/editor';
import * as editPost from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import Sidebar from '../editor/Sidebar';
import { SeoMark } from '../editor/icon';
import '../core3/admin-kit.css';
import '../shared/seo.css';
import '../editor/editor.css';

// WP 6.6+ exports the sidebar slots from @wordpress/editor; older versions from @wordpress/edit-post.
const PluginSidebar = editorPkg.PluginSidebar || editPost.PluginSidebar;
const PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem || editPost.PluginSidebarMoreMenuItem;

registerPlugin( 'dev3-ai-seo-assistant', {
	icon: <SeoMark />,
	render: () => (
		<>
			<PluginSidebarMoreMenuItem target="dev3se-sidebar" icon={ <SeoMark /> }>
				{ __( 'DEV3 AI SEO', 'dev3-ai-seo-assistant' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name="dev3se-sidebar" title={ __( 'DEV3 AI SEO', 'dev3-ai-seo-assistant' ) } icon={ <SeoMark /> }>
				<Sidebar />
			</PluginSidebar>
		</>
	),
} );
