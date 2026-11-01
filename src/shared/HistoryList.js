import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, DiffView, EmptyState, ErrorNotice, Inline, Notice, Skeleton, diffWords, useToast } from '../core3/ui';
import { contextDiff, fieldLabel, isConflict, when } from './seo';

/**
 * Undo one history row (asks before forcing over newer edits).
 *
 * @param {Object}   row      Row.
 * @param {boolean}  force    Force.
 * @return {Promise} Request.
 */
export const undoRow = ( row, force = false ) => request( `history/${ row.id }/undo`, { method: 'POST', data: { force } } );

/**
 * Compact history of one post with undo (editor sidebar and post screen).
 *
 * @param {Object}   props            Props.
 * @param {number}   props.postId     Post id.
 * @param {number}   props.refreshKey Changes reload the list.
 * @param {Function} props.onUndone   ( row ) => void.
 * @return {Element} List.
 */
export default function HistoryList( { postId, refreshKey = 0, onUndone } ) {
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ confirm, setConfirm ] = useState( 0 );
	const [ busy, setBusy ] = useState( 0 );

	const load = () => {
		setError( null );
		request( `history?post_id=${ postId }&per_page=20` )
			.then( ( res ) => setRows( res.items ) )
			.catch( setError );
	};
	useEffect( load, [ postId, refreshKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const undo = ( row, force = false ) => {
		setBusy( row.id );
		undoRow( row, force )
			.then( ( next ) => {
				setConfirm( 0 );
				toast.success( sprintf( /* translators: %s: field */ __( '%s restored.', 'dev3-ai-seo-assistant' ), fieldLabel( row.field ) ) );
				setRows( ( list ) => list.map( ( r ) => ( r.id === row.id ? next : r ) ) );
				if ( onUndone ) {
					onUndone( next );
				}
			} )
			.catch( ( err ) => ( isConflict( err ) ? setConfirm( row.id ) : toast.error( err ) ) )
			.finally( () => setBusy( 0 ) );
	};

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! rows ) {
		return <Skeleton lines={ 3 } />;
	}
	if ( ! rows.length ) {
		return <EmptyState compact icon="clock" title={ __( 'No changes yet', 'dev3-ai-seo-assistant' ) } description={ __( 'Every draft you apply is listed here with an undo button.', 'dev3-ai-seo-assistant' ) } />;
	}
	return (
		<ul className="dev3se-history">
			{ rows.map( ( row ) => (
				<li key={ row.id } className={ row.undone_at ? 'is-undone' : '' }>
					<div className="dev3se-history__head">
						<strong>{ fieldLabel( row.field ) }</strong>
						{ row.undone_at ? <Badge>{ __( 'Undone', 'dev3-ai-seo-assistant' ) }</Badge> : null }
						<span className="dev3se-help">{ [ row.user, when( row.created_at ) ].filter( Boolean ).join( ' · ' ) }</span>
					</div>
					<DiffView parts={ row.field === 'content' ? contextDiff( row.before, row.after, 50 ) : diffWords( row.before, row.after ) } block={ row.field === 'content' || row.field === 'schema_jsonld' } />
					{ confirm === row.id && (
						<Notice
							tone="warning"
							actions={
								<Inline>
									<Button size="sm" variant="danger" onClick={ () => undo( row, true ) } loading={ busy === row.id }>
										{ __( 'Undo anyway', 'dev3-ai-seo-assistant' ) }
									</Button>
									<Button size="sm" variant="ghost" onClick={ () => setConfirm( 0 ) }>
										{ __( 'Cancel', 'dev3-ai-seo-assistant' ) }
									</Button>
								</Inline>
							}
						>
							{ __( 'The value changed after this edit. Undo would overwrite the newer value.', 'dev3-ai-seo-assistant' ) }
						</Notice>
					) }
					{ ! row.undone_at && confirm !== row.id && (
						<Button size="sm" icon="refresh" onClick={ () => undo( row ) } loading={ busy === row.id }>
							{ __( 'Undo', 'dev3-ai-seo-assistant' ) }
						</Button>
					) }
				</li>
			) ) }
		</ul>
	);
}
