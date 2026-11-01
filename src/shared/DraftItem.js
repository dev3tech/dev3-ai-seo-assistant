import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiDraftReview, Badge, Button, DiffView, ErrorNotice, Icon, Inline, Notice, cx, useToast } from '../core3/ui';
import { LengthMeter } from './components';
import { contextDiff, fieldLabel, isConflict } from './seo';

/**
 * One field-level draft: diff against the live value, alternatives, edit, apply / reject, conflict check.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.draft    Draft row.
 * @param {Function} props.onDone   ( action, result ) => void after apply/reject.
 * @param {Function} props.onSynced Optional ( field, value ) => void after apply (editor store sync).
 * @param {boolean}  props.showPost Show the post title (queue view).
 * @return {Element} Draft.
 */
export default function DraftItem( { draft, onDone, onSynced, showPost = false } ) {
	const toast = useToast();
	const [ value, setValue ] = useState( draft.draft );
	const [ busy, setBusy ] = useState( '' );
	const [ conflict, setConflict ] = useState( null );
	const [ error, setError ] = useState( null );
	const isJson = draft.field === 'schema_jsonld';
	const isContent = draft.field === 'content';
	const alternatives = [ draft.draft, ...( draft.alternatives || [] ) ].filter( ( v, i, a ) => typeof v === 'string' && v && a.indexOf( v ) === i );

	const apply = ( edited, force = false ) => {
		setBusy( 'apply' );
		setError( null );
		const data = { force };
		if ( edited !== undefined && edited !== null ) {
			data.value = typeof edited === 'string' ? edited : JSON.stringify( edited, null, 2 );
		}
		return request( `drafts/${ draft.id }/apply`, { method: 'POST', data } )
			.then( ( res ) => {
				setConflict( null );
				toast.success( sprintf( /* translators: %s: field label */ __( '%s applied.', 'dev3-ai-seo-assistant' ), fieldLabel( draft.field ) ) );
				if ( onSynced ) {
					onSynced( draft.field, res.value );
				}
				onDone( 'applied', res );
			} )
			.catch( ( err ) => {
				if ( isConflict( err ) ) {
					setConflict( { value: data.value, current: '' } );
					if ( ! isContent ) {
						// The error envelope carries no data: read the live value from the snapshot.
						request( `posts/${ draft.post_id }` )
							.then( ( snap ) => setConflict( { value: data.value, current: draft.field === 'schema_jsonld' ? snap.schema : ( snap.values || {} )[ draft.field ] || '' } ) )
							.catch( () => {} );
					}
				} else {
					setError( err );
				}
			} )
			.finally( () => setBusy( '' ) );
	};

	const reject = () => {
		setBusy( 'reject' );
		request( `drafts/${ draft.id }/reject`, { method: 'POST' } )
			.then( ( res ) => {
				toast.info( __( 'Draft rejected.', 'dev3-ai-seo-assistant' ) );
				onDone( 'rejected', res );
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const title = showPost ? `${ fieldLabel( draft.field ) } · ${ draft.post_title || `#${ draft.post_id }` }` : fieldLabel( draft.field );

	return (
		<div className={ cx( 'dev3se-draft', isContent && 'is-content' ) } data-field={ draft.field }>
			{ conflict && (
				<Notice
					tone="warning"
					title={ __( 'This field changed since the draft was made', 'dev3-ai-seo-assistant' ) }
					actions={
						<Inline>
							<Button size="sm" variant="primary" onClick={ () => apply( conflict.value, true ) } loading={ busy === 'apply' }>
								{ __( 'Apply anyway', 'dev3-ai-seo-assistant' ) }
							</Button>
							<Button size="sm" variant="ghost" onClick={ reject }>
								{ __( 'Keep current', 'dev3-ai-seo-assistant' ) }
							</Button>
						</Inline>
					}
				>
					{ ! isContent && conflict.current ? sprintf( /* translators: %s: current value */ __( 'Current value: “%s”', 'dev3-ai-seo-assistant' ), String( conflict.current ).slice( 0, 300 ) ) : __( 'Someone edited it after the draft was generated.', 'dev3-ai-seo-assistant' ) }
				</Notice>
			) }
			{ error && <ErrorNotice error={ error } onDismiss={ () => setError( null ) } /> }

			{ isContent ? (
				<section className="dev3se-ui-draft dev3se-content-draft" aria-label={ title }>
					<header className="dev3se-ui-draft__head">
						<div className="dev3se-ui-draft__title">
							<span className="dev3se-ui-draft__spark" aria-hidden="true">
								<Icon name={ draft.kind === 'faq' ? 'list' : 'external' } size={ 16 } />
							</span>
							<h3>{ title }</h3>
							<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-seo-assistant' ) }</Badge>
						</div>
						{ draft.note && <span className="dev3se-help">{ draft.note }</span> }
					</header>
					<div className="dev3se-ui-draft__body">
						<DiffView parts={ contextDiff( draft.original, draft.draft ) } block />
					</div>
					<footer className="dev3se-ui-draft__foot">
						<div className="dev3se-ui-draft__meta">
							<span>{ __( 'Applying updates the post content (a WordPress revision is kept too).', 'dev3-ai-seo-assistant' ) }</span>
						</div>
						<div className="dev3se-ui-draft__actions">
							<Button variant="ghost" icon="x" onClick={ reject } loading={ busy === 'reject' } disabled={ !! busy }>
								{ __( 'Reject', 'dev3-ai-seo-assistant' ) }
							</Button>
							<Button variant="primary" icon="check" onClick={ () => apply( null ) } loading={ busy === 'apply' } disabled={ !! busy }>
								{ __( 'Apply', 'dev3-ai-seo-assistant' ) }
							</Button>
						</div>
					</footer>
				</section>
			) : (
				<>
					<AiDraftReview
						title={ title }
						draft={ isJson ? safeJson( value ) : value }
						original={ isJson ? ( draft.original ? safeJson( draft.original ) : null ) : draft.original }
						format={ isJson ? 'json' : 'text' }
						acceptLabel={ __( 'Apply', 'dev3-ai-seo-assistant' ) }
						accepting={ busy === 'apply' }
						onAccept={ ( v ) => apply( v ) }
						onReject={ reject }
						meta={ { model: draft.model || ( draft.source === 'basic' ? __( 'Basic (no AI)', 'dev3-ai-seo-assistant' ) : '' ), tokens: draft.tokens } }
					/>
					<LengthMeter field={ draft.field } value={ value } />
					{ alternatives.length > 1 && (
						<div className="dev3se-alts" role="group" aria-label={ __( 'Alternative suggestions', 'dev3-ai-seo-assistant' ) }>
							<span className="dev3se-alts__label">{ __( 'Suggestions', 'dev3-ai-seo-assistant' ) }</span>
							{ alternatives.map( ( alt, i ) => (
								<button key={ i } type="button" className={ cx( 'dev3se-alts__opt', alt === value && 'is-active' ) } aria-pressed={ alt === value } onClick={ () => setValue( alt ) } title={ alt }>
									{ sprintf( /* translators: %d: option number */ __( 'Option %d', 'dev3-ai-seo-assistant' ), i + 1 ) }
								</button>
							) ) }
						</div>
					) }

					{ draft.note && <p className="dev3se-help">{ draft.note }</p> }
				</>
			) }
		</div>
	);
}

/**
 * Parses JSON for the JSON draft view (falls back to the raw string).
 *
 * @param {string} s JSON string.
 * @return {*} Value.
 */
function safeJson( s ) {
	try {
		return JSON.parse( s );
	} catch ( e ) {
		return s;
	}
}
