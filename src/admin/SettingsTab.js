import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, Card, Checkbox, FormGrid, NumberField, PageHeader, Select, Stack, TextField, Textarea, Toggle, useToast } from '../core3/ui';

const KEYS = [ 'seo_adapter', 'title_max', 'description_min', 'description_max', 'title_separator', 'brand_suffix', 'brand_name', 'content_language', 'tone', 'instructions', 'post_types', 'link_suggestions', 'link_min_score', 'auto_index', 'faq_count', 'schema_output', 'native_output', 'posts_list_column' ];

/**
 * Plugin settings (keys registered in includes/Settings.php).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @param {Object}   props.status   Status (adapters, post types).
 * @return {Element} Settings.
 */
export default function SettingsTab( { settings, save, status } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( settings[ k ] ) );
	const adapters = status ? status.adapters : [];
	const detected = status ? adapters.find( ( a ) => a.id === status.detected ) : null;

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const patch = {};
		KEYS.forEach( ( k ) => {
			patch[ k ] = form[ k ] === '' && typeof settings[ k ] === 'number' ? settings[ k ] : form[ k ];
		} );
		save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-seo-assistant' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const types = ( status && status.postTypes ) || [];
	const toggleType = ( type, on ) => set( 'post_types' )( on ? [ ...( form.post_types || [] ), type ] : ( form.post_types || [] ).filter( ( t ) => t !== type ) );

	return (
		<form onSubmit={ submit }>
			<PageHeader title={ __( 'Settings', 'dev3-ai-seo-assistant' ) } description={ __( 'Where DEV3 writes, how titles and descriptions are shaped, and which content it works on.', 'dev3-ai-seo-assistant' ) } />
			<Stack gap={ 16 }>
				<Card title={ __( 'SEO plugin', 'dev3-ai-seo-assistant' ) } description={ __( 'DEV3 writes drafts you apply into this plugin’s fields.', 'dev3-ai-seo-assistant' ) }>
					<FormGrid>
						<Select
							label={ __( 'Write into', 'dev3-ai-seo-assistant' ) }
							value={ form.seo_adapter }
							onChange={ set( 'seo_adapter' ) }
							options={ [
								{ value: 'auto', label: detected ? sprintf( /* translators: %s: plugin */ __( 'Auto-detect (now: %s)', 'dev3-ai-seo-assistant' ), detected.label ) : __( 'Auto-detect', 'dev3-ai-seo-assistant' ) },
								...adapters.map( ( a ) => ( { value: a.id, label: a.active || a.id === 'native' ? a.label : sprintf( /* translators: %s: plugin */ __( '%s (not active)', 'dev3-ai-seo-assistant' ), a.label ) } ) ),
							] }
							help={ __( 'Yoast SEO and Rank Math are detected automatically. SEOPress and All in One SEO are supported too.', 'dev3-ai-seo-assistant' ) }
						/>
					</FormGrid>
					<div className="dev3se-adapters">
						{ adapters.map( ( a ) => (
							<Badge key={ a.id } tone={ a.active && a.id !== 'native' ? 'success' : 'neutral' } dot>
								{ a.label }
								{ a.active && a.id !== 'native' ? ` · ${ __( 'active', 'dev3-ai-seo-assistant' ) }` : '' }
							</Badge>
						) ) }
					</div>
					<Toggle label={ __( 'Print meta tags when no SEO plugin is active', 'dev3-ai-seo-assistant' ) } checked={ !! form.native_output } onChange={ set( 'native_output' ) } help={ __( 'Title, meta description and Open Graph tags from DEV3’s own fields.', 'dev3-ai-seo-assistant' ) } />
					<Toggle label={ __( 'Output DEV3 schema (JSON-LD)', 'dev3-ai-seo-assistant' ) } checked={ !! form.schema_output } onChange={ set( 'schema_output' ) } help={ __( 'Adds applied schema pieces and FAQ schema to the SEO plugin’s graph, or prints them on their own.', 'dev3-ai-seo-assistant' ) } />
				</Card>
				<Card title={ __( 'Titles & descriptions', 'dev3-ai-seo-assistant' ) }>
					<FormGrid>
						<NumberField label={ __( 'SEO title limit (characters)', 'dev3-ai-seo-assistant' ) } value={ form.title_max } onChange={ set( 'title_max' ) } min={ 30 } max={ 80 } />
						<NumberField label={ __( 'Description minimum', 'dev3-ai-seo-assistant' ) } value={ form.description_min } onChange={ set( 'description_min' ) } min={ 50 } max={ 160 } />
						<NumberField label={ __( 'Description maximum', 'dev3-ai-seo-assistant' ) } value={ form.description_max } onChange={ set( 'description_max' ) } min={ 80 } max={ 320 } />
						<TextField label={ __( 'Title separator', 'dev3-ai-seo-assistant' ) } value={ form.title_separator } onChange={ set( 'title_separator' ) } maxLength={ 3 } />
						<TextField label={ __( 'Brand name', 'dev3-ai-seo-assistant' ) } value={ form.brand_name } onChange={ set( 'brand_name' ) } placeholder={ __( 'Site title', 'dev3-ai-seo-assistant' ) } />
					</FormGrid>
					<Toggle label={ __( 'Add the brand to SEO titles when it fits', 'dev3-ai-seo-assistant' ) } checked={ !! form.brand_suffix } onChange={ set( 'brand_suffix' ) } />
				</Card>
				<Card title={ __( 'DEV3 AI writing', 'dev3-ai-seo-assistant' ) } tone="ai">
					<FormGrid>
						<Select
							label={ __( 'Tone', 'dev3-ai-seo-assistant' ) }
							value={ form.tone }
							onChange={ set( 'tone' ) }
							options={ [
								{ value: 'clear', label: __( 'Clear and helpful', 'dev3-ai-seo-assistant' ) },
								{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-seo-assistant' ) },
								{ value: 'professional', label: __( 'Professional', 'dev3-ai-seo-assistant' ) },
								{ value: 'bold', label: __( 'Bold and punchy', 'dev3-ai-seo-assistant' ) },
								{ value: 'technical', label: __( 'Technical', 'dev3-ai-seo-assistant' ) },
							] }
						/>
						<TextField label={ __( 'Content language', 'dev3-ai-seo-assistant' ) } value={ form.content_language } onChange={ set( 'content_language' ) } placeholder={ __( 'Site language', 'dev3-ai-seo-assistant' ) } help={ __( 'e.g. German, Spanish (Mexico). Empty = site language.', 'dev3-ai-seo-assistant' ) } />
						<NumberField label={ __( 'FAQ questions', 'dev3-ai-seo-assistant' ) } value={ form.faq_count } onChange={ set( 'faq_count' ) } min={ 2 } max={ 10 } />
					</FormGrid>
					<Textarea label={ __( 'House style (extra instructions)', 'dev3-ai-seo-assistant' ) } value={ form.instructions } onChange={ set( 'instructions' ) } rows={ 3 } maxLength={ 1000 } help={ __( 'e.g. “Use British spelling. Never promise free shipping.”', 'dev3-ai-seo-assistant' ) } />
				</Card>
				<Card title={ __( 'Internal links', 'dev3-ai-seo-assistant' ) }>
					<FormGrid>
						<NumberField label={ __( 'Suggestions per post', 'dev3-ai-seo-assistant' ) } value={ form.link_suggestions } onChange={ set( 'link_suggestions' ) } min={ 1 } max={ 15 } />
						<NumberField label={ __( 'Minimum similarity (0.1-0.95)', 'dev3-ai-seo-assistant' ) } value={ form.link_min_score } onChange={ set( 'link_min_score' ) } min={ 0.1 } max={ 0.95 } step={ 0.05 } />
					</FormGrid>
					<Toggle label={ __( 'Keep the link index fresh when posts are published', 'dev3-ai-seo-assistant' ) } checked={ !! form.auto_index } onChange={ set( 'auto_index' ) } help={ __( 'Re-embeds a published post in the background when it changes (uses a small amount of AI).', 'dev3-ai-seo-assistant' ) } />
				</Card>
				<Card title={ __( 'Content types', 'dev3-ai-seo-assistant' ) }>
					<div className="dev3se-checkgrid">
						{ types.map( ( t ) => (
							<Checkbox key={ t.value } label={ t.label } checked={ ( form.post_types || [] ).includes( t.value ) } onChange={ ( on ) => toggleType( t.value, on ) } />
						) ) }
					</div>
					<Toggle label={ __( 'Show the “SEO (DEV3)” score column in post lists', 'dev3-ai-seo-assistant' ) } checked={ !! form.posts_list_column } onChange={ set( 'posts_list_column' ) } />
				</Card>
			</Stack>
			<div className="dev3se-savebar">
				<Button type="submit" variant="primary" icon="check" loading={ busy } disabled={ ! dirty }>
					{ __( 'Save settings', 'dev3-ai-seo-assistant' ) }
				</Button>
			</div>
		</form>
	);
}
