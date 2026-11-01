import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Checkbox, ErrorNotice, FormGrid, Inline, JobProgress, Notice, PageHeader, ResponsiveTable, Select, Stack, TextField, Toggle, formatNumber, useToast, VisuallyHidden } from '../core3/ui';
import { aiReady } from '../shared/panels';
import { fieldLabel, scoreTone } from '../shared/seo';
import { STATUS_OPTIONS, issueOptions, qs, typeOptions } from './util';

const RUN_FIELDS = [ 'seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description', 'schema' ];
const PER_PAGE = 20;

/**
 * Bulk optimize: filter posts, select, run a background draft job.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.status  Status.
 * @param {Object}   props.params  Hash params (ids, issue).
 * @param {Function} props.onUsage After AI use.
 * @param {Function} props.refresh Refresh status.
 * @return {Element} Screen.
 */
export default function BulkTab( { status, params, onUsage, refresh } ) {
	const toast = useToast();
	const [ filter, setFilter ] = useState( { search: '', type: '', status: '', issue: params.issue || '', ids: params.ids || '' } );
	const [ page, setPage ] = useState( 1 );
	const [ sort, setSort ] = useState( { key: 'modified', dir: 'desc' } );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( () => ( params.ids ? params.ids.split( ',' ).map( Number ).filter( Boolean ) : [] ) );
	const [ scope, setScope ] = useState( params.ids ? 'selected' : 'filter' );
	const adapterFields = status ? status.adapter.fields : RUN_FIELDS;
	const [ fields, setFields ] = useState( [ 'seo_title', 'meta_description', 'focus_keyword' ] );
	const [ mode, setMode ] = useState( 'ai' );
	const [ onlyEmpty, setOnlyEmpty ] = useState( true );
	const [ job, setJob ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const ready = aiReady( status );

	useEffect( () => {
		if ( status && ! ready ) {
			setMode( 'basic' );
		}
	}, [ ready, status ] );

	const load = () => {
		setError( null );
		setData( ( d ) => ( d ? { ...d, loading: true } : d ) );
		request( `posts?${ qs( { ...filter, page, per_page: PER_PAGE, orderby: sort.key, order: sort.dir } ) }` )
			.then( ( res ) => setData( res ) )
			.catch( setError );
	};
	useEffect( load, [ filter, page, sort ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		request( 'runs' )
			.then( ( r ) => r.active && setJob( r.active ) )
			.catch( () => {} );
	}, [] );

	const set = ( key ) => ( value ) => {
		setPage( 1 );
		setFilter( { ...filter, [ key ]: value } );
	};
	const rows = data ? data.items : [];
	const allOnPage = rows.length > 0 && rows.every( ( r ) => selected.includes( r.id ) );
	const toggleRow = ( id, on ) => setSelected( on ? [ ...selected, id ] : selected.filter( ( x ) => x !== id ) );
	const togglePage = ( on ) => setSelected( on ? Array.from( new Set( [ ...selected, ...rows.map( ( r ) => r.id ) ] ) ) : selected.filter( ( id ) => ! rows.some( ( r ) => r.id === id ) ) );
	const available = RUN_FIELDS.filter( ( f ) => f === 'schema' || adapterFields.includes( f ) );
	const target = scope === 'selected' ? selected.length : data ? data.total : 0;

	const start = () => {
		setStarting( true );
		setError( null );
		const body = { fields, mode, only_empty: onlyEmpty };
		if ( scope === 'selected' ) {
			body.ids = selected;
		} else {
			body.filter = { search: filter.search, type: filter.type, status: filter.status, issue: filter.issue };
		}
		request( 'bulk', { method: 'POST', data: body } )
			.then( ( j ) => {
				setJob( j );
				toast.success( sprintf( /* translators: %d: posts */ _n( 'Run started for %d post.', 'Run started for %d posts.', j.total, 'dev3-ai-seo-assistant' ), j.total ) );
			} )
			.catch( setError )
			.finally( () => setStarting( false ) );
	};

	const columns = [
		{
			key: 'select',
			label: __( 'Select', 'dev3-ai-seo-assistant' ),
			width: '56px',
			render: ( r ) => (
				<span onClick={ ( e ) => e.stopPropagation() } role="presentation">
					<Checkbox label={ <VisuallyHidden>{ sprintf( /* translators: %s: post title */ __( 'Select “%s”', 'dev3-ai-seo-assistant' ), r.title ) }</VisuallyHidden> } checked={ selected.includes( r.id ) } onChange={ ( on ) => toggleRow( r.id, on ) } />
				</span>
			),
		},
		{
			key: 'title',
			label: __( 'Post', 'dev3-ai-seo-assistant' ),
			primary: true,
			sortable: true,
			render: ( r ) => (
				<span className="dev3se-cell-title">
					<a href={ `#/post/${ r.id }` }>{ r.title || __( '(no title)', 'dev3-ai-seo-assistant' ) }</a>
					<span className="dev3se-help">{ `${ r.type } · ${ r.status }` }</span>
				</span>
			),
		},
		{ key: 'score', label: __( 'Score', 'dev3-ai-seo-assistant' ), sortable: true, align: 'end', render: ( r ) => <Badge tone={ scoreTone( r.score ) }>{ r.score }</Badge> },
		{ key: 'keyword', label: __( 'Focus keyword', 'dev3-ai-seo-assistant' ), hideOnMobile: false, render: ( r ) => r.keyword || <span className="dev3se-muted">—</span> },
		{ key: 'title_len', label: __( 'Title', 'dev3-ai-seo-assistant' ), align: 'end', render: ( r ) => <span className={ r.issues.includes( 'long_title' ) ? 'dev3se-bad' : '' }>{ r.seo_title ? r.title_len : `${ r.title_len }*` }</span> },
		{ key: 'desc_len', label: __( 'Description', 'dev3-ai-seo-assistant' ), align: 'end', render: ( r ) => ( r.desc_len ? <span className={ r.issues.some( ( i ) => i.endsWith( '_description' ) ) ? 'dev3se-warn' : '' }>{ r.desc_len }</span> : <Badge tone="danger">{ __( 'none', 'dev3-ai-seo-assistant' ) }</Badge> ) },
		{ key: 'words', label: __( 'Words', 'dev3-ai-seo-assistant' ), sortable: true, align: 'end', hideOnMobile: true, render: ( r ) => formatNumber( r.words ) },
		{ key: 'inbound', label: __( 'Links in/out', 'dev3-ai-seo-assistant' ), sortable: true, align: 'end', hideOnMobile: true, render: ( r ) => `${ r.inbound } / ${ r.outbound }` },
	];

	return (
		<>
			<PageHeader title={ __( 'Bulk optimize', 'dev3-ai-seo-assistant' ) } description={ __( 'Pick posts, choose fields and let DEV3 AI draft them in the background. Nothing changes until you apply the drafts in the Review queue.', 'dev3-ai-seo-assistant' ) } />
			{ error && <ErrorNotice error={ error } onDismiss={ () => setError( null ) } settingsHref="#/api" /> }
			<Card tone="ai" title={ __( 'Run settings', 'dev3-ai-seo-assistant' ) }>
				{ job ? (
					<Stack gap={ 12 }>
						<JobProgress
							jobId={ job.id }
							job={ job }
							title={ __( 'Drafting SEO fields', 'dev3-ai-seo-assistant' ) }
							onDone={ ( j ) => {
								setJob( { ...j, finished: true } );
								onUsage();
								refresh();
								load();
							} }
						/>
						{ job.finished && (
							<Inline>
								<Button variant="primary" icon="diff" href={ `#/review/job=${ job.id }` }>
									{ __( 'Review the drafts', 'dev3-ai-seo-assistant' ) }
								</Button>
								<Button variant="ghost" onClick={ () => setJob( null ) }>
									{ __( 'Start another run', 'dev3-ai-seo-assistant' ) }
								</Button>
							</Inline>
						) }
					</Stack>
				) : (
					<Stack gap={ 16 }>
						<fieldset className="dev3se-fieldset">
							<legend>{ __( 'Fields', 'dev3-ai-seo-assistant' ) }</legend>
							<div className="dev3se-checkgrid">
								{ available.map( ( f ) => (
									<Checkbox key={ f } label={ f === 'schema' ? __( 'Schema', 'dev3-ai-seo-assistant' ) : fieldLabel( f ) } checked={ fields.includes( f ) } onChange={ ( on ) => setFields( on ? [ ...fields, f ] : fields.filter( ( x ) => x !== f ) ) } />
								) ) }
							</div>
						</fieldset>
						<FormGrid>
							<Select
								label={ __( 'Writer', 'dev3-ai-seo-assistant' ) }
								value={ mode }
								onChange={ setMode }
								options={ [
									{ value: 'ai', label: __( 'DEV3 AI (Gemini)', 'dev3-ai-seo-assistant' ) },
									{ value: 'basic', label: __( 'Basic rules (no AI)', 'dev3-ai-seo-assistant' ) },
								] }
								help={ ! ready ? __( 'DEV3 AI needs an API key (API Config).', 'dev3-ai-seo-assistant' ) : __( 'About 1 request per post.', 'dev3-ai-seo-assistant' ) }
							/>
							<Select
								label={ __( 'Posts', 'dev3-ai-seo-assistant' ) }
								value={ scope }
								onChange={ setScope }
								options={ [
									{ value: 'selected', label: sprintf( /* translators: %d: count */ __( 'Selected posts (%d)', 'dev3-ai-seo-assistant' ), selected.length ) },
									{ value: 'filter', label: sprintf( /* translators: %d: count */ __( 'All matching the filter (%d)', 'dev3-ai-seo-assistant' ), data ? data.total : 0 ) },
								] }
							/>
						</FormGrid>
						<Toggle label={ __( 'Only fill empty fields', 'dev3-ai-seo-assistant' ) } checked={ onlyEmpty } onChange={ setOnlyEmpty } help={ __( 'Leave values someone already wrote untouched.', 'dev3-ai-seo-assistant' ) } />
						{ mode === 'ai' && ! ready && <Notice tone="warning">{ __( 'DEV3 AI is not available. Switch to Basic rules or add an API key.', 'dev3-ai-seo-assistant' ) }</Notice> }
						<Inline>
							<AiButton onClick={ start } loading={ starting } disabled={ ! fields.length || ! target || ( mode === 'ai' && ! ready ) }>
								{ sprintf( /* translators: %d: posts */ _n( 'Draft %d post', 'Draft %d posts', target, 'dev3-ai-seo-assistant' ), target ) }
							</AiButton>
							{ selected.length > 0 && (
								<Button variant="ghost" onClick={ () => setSelected( [] ) }>
									{ __( 'Clear selection', 'dev3-ai-seo-assistant' ) }
								</Button>
							) }
						</Inline>
					</Stack>
				) }
			</Card>
			<Card
				title={ __( 'Posts', 'dev3-ai-seo-assistant' ) }
				flush
				actions={
					<Checkbox label={ __( 'Select page', 'dev3-ai-seo-assistant' ) } checked={ allOnPage } onChange={ togglePage } />
				}
			>
				<div className="dev3se-filters">
					<TextField label={ __( 'Search', 'dev3-ai-seo-assistant' ) } value={ filter.search } onChange={ set( 'search' ) } placeholder={ __( 'Title or keyword', 'dev3-ai-seo-assistant' ) } />
					<Select label={ __( 'Type', 'dev3-ai-seo-assistant' ) } value={ filter.type } onChange={ set( 'type' ) } options={ typeOptions( status ) } />
					<Select label={ __( 'Status', 'dev3-ai-seo-assistant' ) } value={ filter.status } onChange={ set( 'status' ) } options={ STATUS_OPTIONS } />
					<Select label={ __( 'Issue', 'dev3-ai-seo-assistant' ) } value={ filter.issue } onChange={ set( 'issue' ) } options={ issueOptions( status ) } />
				</div>
				{ filter.ids && (
					<div className="dev3se-pad">
						<Notice tone="info" actions={ <Button size="sm" variant="link" onClick={ set( 'ids' ).bind( null, '' ) }>{ __( 'Show all posts', 'dev3-ai-seo-assistant' ) }</Button> }>
							{ __( 'Showing the posts you picked in the Posts list.', 'dev3-ai-seo-assistant' ) }
						</Notice>
					</div>
				) }
				<ResponsiveTable
					columns={ columns }
					rows={ rows }
					loading={ ! data || data.loading }
					sort={ sort }
					onSortChange={ ( s ) => {
						setPage( 1 );
						setSort( s );
					} }
					pagination={ { page, perPage: PER_PAGE, total: data ? data.total : 0, onPageChange: setPage } }
					rowActions={ ( r ) => (
						<Button size="sm" href={ `#/post/${ r.id }` }>
							{ __( 'Open', 'dev3-ai-seo-assistant' ) }
						</Button>
					) }
					empty={ { icon: 'search', title: __( 'No posts match', 'dev3-ai-seo-assistant' ), description: __( 'Change the filters, or enable more post types in Settings.', 'dev3-ai-seo-assistant' ) } }
					caption={ __( 'Posts and their SEO health', 'dev3-ai-seo-assistant' ) }
				/>
			</Card>
		</>
	);
}
