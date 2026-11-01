import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, EmptyState, ErrorNotice, Inline, JobProgress, Notice, PageHeader, Pagination, ResponsiveTable, Select, SkeletonCard, Stack, Tabs, TextField, useToast, VisuallyHidden } from '../core3/ui';
import DraftItem from '../shared/DraftItem';
import { FIELD_LABELS, fieldLabel, statusLabel, statusTone, when } from '../shared/seo';
import { qs } from './util';

const PER_PAGE = 10;

/**
 * Review queue: field-level drafts with diffs; apply/reject one, selected or a whole run.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.params  Hash params (job).
 * @param {Function} props.refresh Refresh status (nav badge).
 * @return {Element} Screen.
 */
export default function ReviewTab( { params, refresh } ) {
	const toast = useToast();
	const [ filter, setFilter ] = useState( { status: 'pending', field: '', job_id: params.job || '', search: '' } );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ runs, setRuns ] = useState( [] );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ busy, setBusy ] = useState( '' );
	const [ applyJob, setApplyJob ] = useState( null );
	const [ result, setResult ] = useState( null );

	const load = () => {
		setError( null );
		request( `drafts?${ qs( { ...filter, page, per_page: PER_PAGE } ) }` )
			.then( ( res ) => {
				setData( res );
				setSelected( [] );
			} )
			.catch( setError );
	};
	useEffect( load, [ filter, page ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		request( 'runs' )
			.then( ( r ) => setRuns( r.runs ) )
			.catch( () => {} );
	}, [] );

	const set = ( key ) => ( value ) => {
		setPage( 1 );
		setFilter( { ...filter, [ key ]: value } );
	};
	const changed = () => {
		load();
		refresh();
	};

	const bulk = ( action ) => {
		setBusy( action );
		setResult( null );
		request( 'drafts/bulk', { method: 'POST', data: { ids: selected, action } } )
			.then( ( res ) => {
				setResult( res );
				toast.success( sprintf( /* translators: %d: drafts */ _n( '%d draft done.', '%d drafts done.', res.done, 'dev3-ai-seo-assistant' ), res.done ) );
				changed();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};

	const applyRun = () => {
		setBusy( 'run' );
		request( 'drafts/apply-run', { method: 'POST', data: { job_id: Number( filter.job_id ) } } )
			.then( setApplyJob )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};

	const items = data ? data.items : [];
	const counts = data ? data.counts : {};
	const pending = filter.status === 'pending';
	const tabs = [ 'pending', 'applied', 'rejected', 'superseded' ].map( ( id ) => ( { id, label: statusLabel( id ), badge: counts[ id ] ? String( counts[ id ] ) : undefined } ) );
	const runOptions = [ { value: '', label: __( 'All runs and editor drafts', 'dev3-ai-seo-assistant' ) }, ...runs.map( ( r ) => ( { value: String( r.job_id ), label: sprintf( /* translators: 1: run id, 2: pending */ __( 'Run #%1$d (%2$d pending)', 'dev3-ai-seo-assistant' ), r.job_id, r.pending ) } ) ) ];

	return (
		<>
			<PageHeader title={ __( 'Review queue', 'dev3-ai-seo-assistant' ) } description={ __( 'Every DEV3 AI suggestion waits here as a draft. Compare it with the live value, edit it, then apply or reject it field by field.', 'dev3-ai-seo-assistant' ) } />
			<div className="dev3se-tabscroll">
				<Tabs tabs={ tabs } value={ filter.status } onChange={ set( 'status' ) } label={ __( 'Draft status', 'dev3-ai-seo-assistant' ) } />
			</div>
			<Card flush>
				<div className="dev3se-filters">
					<TextField label={ __( 'Search posts', 'dev3-ai-seo-assistant' ) } value={ filter.search } onChange={ set( 'search' ) } />
					<Select label={ __( 'Field', 'dev3-ai-seo-assistant' ) } value={ filter.field } onChange={ set( 'field' ) } options={ [ { value: '', label: __( 'All fields', 'dev3-ai-seo-assistant' ) }, ...Object.entries( FIELD_LABELS ).map( ( [ value, label ] ) => ( { value, label } ) ) ] } />
					<Select label={ __( 'Run', 'dev3-ai-seo-assistant' ) } value={ String( filter.job_id ) } onChange={ set( 'job_id' ) } options={ runOptions } />
				</div>
			</Card>
			{ applyJob && (
				<Card tone="ai">
					<JobProgress
						jobId={ applyJob.id }
						job={ applyJob }
						title={ __( 'Applying drafts', 'dev3-ai-seo-assistant' ) }
						onDone={ () => {
							toast.success( __( 'Run applied. Undo it from History if needed.', 'dev3-ai-seo-assistant' ) );
							changed();
						} }
					/>
					<Inline>
						<Button size="sm" href={ `#/history/batch=run-${ filter.job_id }` }>
							{ __( 'Open in History', 'dev3-ai-seo-assistant' ) }
						</Button>
						<Button size="sm" variant="ghost" onClick={ () => setApplyJob( null ) }>
							{ __( 'Dismiss', 'dev3-ai-seo-assistant' ) }
						</Button>
					</Inline>
				</Card>
			) }
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ result && result.failed.length > 0 && (
				<Notice tone="warning" title={ sprintf( /* translators: %d: drafts */ _n( '%d draft was skipped', '%d drafts were skipped', result.failed.length, 'dev3-ai-seo-assistant' ), result.failed.length ) } onDismiss={ () => setResult( null ) }>
					{ result.failed.slice( 0, 5 ).map( ( f ) => `#${ f.id }: ${ f.message }` ).join( ' ' ) }
				</Notice>
			) }
			{ ! data && ! error && <SkeletonCard lines={ 6 } /> }
			{ data && pending && items.length > 0 && (
				<div className="dev3se-bulkbar" role="toolbar" aria-label={ __( 'Bulk actions', 'dev3-ai-seo-assistant' ) }>
					<Checkbox label={ sprintf( /* translators: %d: selected */ __( 'Select all on this page (%d selected)', 'dev3-ai-seo-assistant' ), selected.length ) } checked={ selected.length === items.length } onChange={ ( on ) => setSelected( on ? items.map( ( d ) => d.id ) : [] ) } />
					<Inline>
						<Button size="sm" variant="primary" icon="check" disabled={ ! selected.length } loading={ busy === 'apply' } onClick={ () => bulk( 'apply' ) }>
							{ __( 'Apply selected', 'dev3-ai-seo-assistant' ) }
						</Button>
						<Button size="sm" icon="x" disabled={ ! selected.length } loading={ busy === 'reject' } onClick={ () => bulk( 'reject' ) }>
							{ __( 'Reject selected', 'dev3-ai-seo-assistant' ) }
						</Button>
						{ filter.job_id && (
							<Button size="sm" icon="bolt" loading={ busy === 'run' } onClick={ applyRun }>
								{ __( 'Apply all pending of this run', 'dev3-ai-seo-assistant' ) }
							</Button>
						) }
					</Inline>
				</div>
			) }
			{ data && pending && (
				<Stack gap={ 16 }>
					{ items.length === 0 && <EmptyState icon="inbox" title={ __( 'Nothing to review', 'dev3-ai-seo-assistant' ) } description={ __( 'Start a bulk run, or generate drafts from the DEV3 AI SEO sidebar in the editor.', 'dev3-ai-seo-assistant' ) } action={ <Button variant="primary" href="#/bulk">{ __( 'Bulk optimize', 'dev3-ai-seo-assistant' ) }</Button> } /> }
					{ items.map( ( d ) => (
						<div key={ d.id } className="dev3se-queueitem">
							<div className="dev3se-queueitem__bar">
								<Checkbox label={ <VisuallyHidden>{ sprintf( /* translators: 1: field, 2: post */ __( 'Select %1$s of “%2$s”', 'dev3-ai-seo-assistant' ), fieldLabel( d.field ), d.post_title ) }</VisuallyHidden> } checked={ selected.includes( d.id ) } onChange={ ( on ) => setSelected( on ? [ ...selected, d.id ] : selected.filter( ( x ) => x !== d.id ) ) } />
								<a href={ `#/post/${ d.post_id }` } className="dev3se-queueitem__post">
									{ d.post_title || `#${ d.post_id }` }
								</a>
								<span className="dev3se-help">{ [ d.source === 'bulk' ? sprintf( /* translators: %d: run */ __( 'Run #%d', 'dev3-ai-seo-assistant' ), d.job_id ) : d.source, when( d.created_at ) ].filter( Boolean ).join( ' · ' ) }</span>
							</div>
							<DraftItem draft={ d } onDone={ changed } />
						</div>
					) ) }
					{ data.total > PER_PAGE && <Pagination page={ page } perPage={ PER_PAGE } total={ data.total } onPageChange={ setPage } /> }
				</Stack>
			) }
			{ data && ! pending && (
				<Card flush>
					<ResponsiveTable
						rows={ items }
						columns={ [
							{ key: 'post_title', label: __( 'Post', 'dev3-ai-seo-assistant' ), primary: true, render: ( d ) => <a href={ `#/post/${ d.post_id }` }>{ d.post_title || `#${ d.post_id }` }</a> },
							{ key: 'field', label: __( 'Field', 'dev3-ai-seo-assistant' ), render: ( d ) => fieldLabel( d.field ) },
							{ key: 'draft', label: __( 'Value', 'dev3-ai-seo-assistant' ), render: ( d ) => <span className="dev3se-clip">{ d.field === 'content' ? d.note : d.draft }</span> },
							{ key: 'status', label: __( 'Status', 'dev3-ai-seo-assistant' ), render: ( d ) => <Badge tone={ statusTone( d.status ) }>{ statusLabel( d.status ) }</Badge> },
							{ key: 'updated_at', label: __( 'Updated', 'dev3-ai-seo-assistant' ), render: ( d ) => when( d.updated_at ) },
						] }
						pagination={ { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } }
						empty={ { icon: 'inbox', title: __( 'No drafts here', 'dev3-ai-seo-assistant' ) } }
					/>
				</Card>
			) }
		</>
	);
}
