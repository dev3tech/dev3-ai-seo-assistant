import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, DiffView, ErrorNotice, Modal, Notice, PageHeader, ResponsiveTable, Select, TextField, diffWords, useToast } from '../core3/ui';
import { undoRow } from '../shared/HistoryList';
import { FIELD_LABELS, contextDiff, fieldLabel, isConflict, when } from '../shared/seo';
import { qs } from './util';

const PER_PAGE = 20;

/**
 * History: every applied change with undo (single row or a whole run).
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.params  Hash params (batch, post).
 * @param {Function} props.refresh Refresh status.
 * @return {Element} Screen.
 */
export default function HistoryTab( { params, refresh } ) {
	const toast = useToast();
	const [ filter, setFilter ] = useState( { search: '', field: '', batch: params.batch || '', post_id: params.post || '' } );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const [ busy, setBusy ] = useState( 0 );

	const load = () => {
		setError( null );
		request( `history?${ qs( { ...filter, page, per_page: PER_PAGE } ) }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ filter, page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const set = ( key ) => ( value ) => {
		setPage( 1 );
		setFilter( { ...filter, [ key ]: value } );
	};

	const undo = ( row, force = false ) => {
		setBusy( row.id );
		undoRow( row, force )
			.then( () => {
				setConfirm( null );
				toast.success( sprintf( /* translators: %s: field */ __( '%s restored.', 'dev3-ai-seo-assistant' ), fieldLabel( row.field ) ) );
				load();
				refresh();
			} )
			.catch( ( err ) => ( isConflict( err ) ? setConfirm( { row } ) : toast.error( err ) ) )
			.finally( () => setBusy( 0 ) );
	};

	const undoBatch = ( force = false ) => {
		setBusy( -1 );
		request( 'history/batch/undo', { method: 'POST', data: { batch: filter.batch, force } } )
			.then( ( res ) => {
				setConfirm( null );
				if ( res.skipped.length && ! force ) {
					setConfirm( { batch: true, skipped: res.skipped.length } );
				}
				toast.success( sprintf( /* translators: %d: changes */ _n( '%d change undone.', '%d changes undone.', res.undone, 'dev3-ai-seo-assistant' ), res.undone ) );
				load();
				refresh();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( 0 ) );
	};

	const columns = [
		{ key: 'post_title', label: __( 'Post', 'dev3-ai-seo-assistant' ), primary: true, render: ( r ) => <a href={ `#/post/${ r.post_id }` }>{ r.post_title || `#${ r.post_id }` }</a> },
		{ key: 'field', label: __( 'Field', 'dev3-ai-seo-assistant' ), render: ( r ) => fieldLabel( r.field ) },
		{
			key: 'change',
			label: __( 'Change', 'dev3-ai-seo-assistant' ),
			render: ( r ) => (
				<div className="dev3se-histdiff">
					<DiffView parts={ r.field === 'content' ? contextDiff( r.before, r.after, 40 ) : diffWords( r.before, r.after ) } />
				</div>
			),
		},
		{ key: 'user', label: __( 'By', 'dev3-ai-seo-assistant' ), hideOnMobile: true, render: ( r ) => r.user || '—' },
		{ key: 'created_at', label: __( 'When', 'dev3-ai-seo-assistant' ), render: ( r ) => when( r.created_at ) },
		{
			key: 'batch',
			label: __( 'Run', 'dev3-ai-seo-assistant' ),
			hideOnMobile: true,
			render: ( r ) =>
				r.batch ? (
					<Button size="sm" variant="link" onClick={ () => set( 'batch' )( r.batch ) }>
						{ r.batch }
					</Button>
				) : (
					'—'
				),
		},
	];

	return (
		<>
			<PageHeader title={ __( 'History', 'dev3-ai-seo-assistant' ) } description={ __( 'Every applied draft, with the value before and after. Undo any change, or a whole bulk run.', 'dev3-ai-seo-assistant' ) } />
			<Card flush>
				<div className="dev3se-filters">
					<TextField label={ __( 'Search posts', 'dev3-ai-seo-assistant' ) } value={ filter.search } onChange={ set( 'search' ) } />
					<Select label={ __( 'Field', 'dev3-ai-seo-assistant' ) } value={ filter.field } onChange={ set( 'field' ) } options={ [ { value: '', label: __( 'All fields', 'dev3-ai-seo-assistant' ) }, ...Object.entries( FIELD_LABELS ).map( ( [ value, label ] ) => ( { value, label } ) ) ] } />
				</div>
				{ filter.batch && (
					<div className="dev3se-pad">
						<Notice
							tone="info"
							title={ sprintf( /* translators: %s: batch */ __( 'Run %s', 'dev3-ai-seo-assistant' ), filter.batch ) }
							actions={
								<>
									<Button size="sm" variant="danger" icon="refresh" onClick={ () => undoBatch( false ) } loading={ busy === -1 }>
										{ __( 'Undo this run', 'dev3-ai-seo-assistant' ) }
									</Button>
									<Button size="sm" variant="ghost" onClick={ () => set( 'batch' )( '' ) }>
										{ __( 'Show all', 'dev3-ai-seo-assistant' ) }
									</Button>
								</>
							}
						>
							{ __( 'Undo restores every value of this run that nobody changed since.', 'dev3-ai-seo-assistant' ) }
						</Notice>
					</div>
				) }
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					rows={ data ? data.items : [] }
					loading={ ! data && ! error }
					columns={ columns }
					pagination={ { page, perPage: PER_PAGE, total: data ? data.total : 0, onPageChange: setPage } }
					rowActions={ ( r ) =>
						r.undone_at ? (
							<Badge>{ __( 'Undone', 'dev3-ai-seo-assistant' ) }</Badge>
						) : (
							<Button size="sm" icon="refresh" onClick={ () => undo( r ) } loading={ busy === r.id }>
								{ __( 'Undo', 'dev3-ai-seo-assistant' ) }
							</Button>
						)
					}
					empty={ { icon: 'clock', title: __( 'No changes yet', 'dev3-ai-seo-assistant' ), description: __( 'Applied drafts appear here with an undo button.', 'dev3-ai-seo-assistant' ) } }
				/>
			</Card>
			<Modal
				open={ !! confirm }
				onClose={ () => setConfirm( null ) }
				title={ __( 'Overwrite newer changes?', 'dev3-ai-seo-assistant' ) }
				size="sm"
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( null ) }>
							{ __( 'Cancel', 'dev3-ai-seo-assistant' ) }
						</Button>
						<Button variant="danger" onClick={ () => ( confirm && confirm.batch ? undoBatch( true ) : undo( confirm.row, true ) ) } loading={ !! busy }>
							{ __( 'Undo anyway', 'dev3-ai-seo-assistant' ) }
						</Button>
					</>
				}
			>
				<p>
					{ confirm && confirm.batch
						? sprintf( /* translators: %d: rows */ _n( '%d value changed after the run, so it was skipped. Undo it anyway?', '%d values changed after the run, so they were skipped. Undo them anyway?', confirm.skipped, 'dev3-ai-seo-assistant' ), confirm.skipped )
						: __( 'This value was edited after DEV3 applied it. Undo would replace the newer value with the one from before.', 'dev3-ai-seo-assistant' ) }
				</p>
			</Modal>
		</>
	);
}
