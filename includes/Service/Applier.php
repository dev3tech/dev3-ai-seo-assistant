<?php
/**
 * Applies drafts per field (with a conflict check) and undoes history rows.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Service;

use Dev3\AiSeoAssistant\Content\Audit;
use Dev3\AiSeoAssistant\Data\Drafts;
use Dev3\AiSeoAssistant\Data\History;
use Dev3\AiSeoAssistant\Links\Graph;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Seo\Fields;
use Dev3\AiSeoAssistant\Seo\Schema_Output;

defined( 'ABSPATH' ) || exit;

/**
 * Applier.
 */
class Applier {

	/**
	 * Same value (whitespace-insensitive at the ends)?
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return bool
	 */
	public static function same( $a, $b ) {
		return trim( str_replace( "\r\n", "\n", (string) $a ) ) === trim( str_replace( "\r\n", "\n", (string) $b ) );
	}

	/**
	 * Applies one draft.
	 *
	 * @param int         $draft_id Draft id.
	 * @param string|null $value    Edited value (null = the draft as generated).
	 * @param bool        $force    Apply even if the live value changed since the draft was made.
	 * @param string      $batch    History batch.
	 * @return array|\WP_Error { draft, history, value }
	 */
	public static function apply( $draft_id, $value = null, $force = false, $batch = '' ) {
		$draft = Drafts::get( $draft_id );
		if ( ! $draft ) {
			return new \WP_Error( 'dev3se_not_found', __( 'Draft not found.', 'dev3-ai-seo-assistant' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $draft['status'] ) {
			return new \WP_Error( 'dev3se_not_pending', __( 'This draft was already applied, rejected or replaced by a newer one.', 'dev3-ai-seo-assistant' ), array( 'status' => 409 ) );
		}
		$post = get_post( $draft['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'dev3se_post_missing', __( 'The post no longer exists.', 'dev3-ai-seo-assistant' ), array( 'status' => 404 ) );
		}
		$field = $draft['field'];
		$clean = Fields::sanitize( $field, null === $value ? $draft['draft'] : $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$adapter = Adapters::current();
		if ( in_array( $field, Fields::ADAPTER, true ) && ! $adapter->supports( $field ) ) {
			/* translators: 1: field label, 2: SEO plugin */
			return new \WP_Error( 'dev3se_field_unsupported', sprintf( __( '%1$s is not stored by %2$s.', 'dev3-ai-seo-assistant' ), Fields::label( $field ), $adapter->label() ), array( 'status' => 400 ) );
		}
		$live = Adapters::read( $post->ID, $field );
		if ( ! $force && ! self::same( $live, $draft['original'] ) ) {
			return new \WP_Error(
				'dev3se_conflict',
				__( 'This field changed after the draft was made. Review the current value, then apply anyway or regenerate.', 'dev3-ai-seo-assistant' ),
				array(
					'status'  => 409,
					'current' => $live,
				)
			);
		}
		$result = Adapters::write( $post->ID, $field, $clean );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'faq' === $draft['kind'] && $draft['alternatives'] ) {
			update_post_meta( $post->ID, Schema_Output::FAQ_META, wp_slash( (string) wp_json_encode( $draft['alternatives'] ) ) );
		}
		$after      = Adapters::read( $post->ID, $field );
		$history_id = History::insert(
			array(
				'post_id'  => $post->ID,
				'field'    => $field,
				'adapter'  => in_array( $field, Fields::ADAPTER, true ) ? $adapter->id() : 'dev3',
				'before'   => $live,
				'after'    => $after,
				'draft_id' => $draft['id'],
				'batch'    => $batch,
			)
		);
		Drafts::set_status( $draft['id'], 'applied', $clean );
		self::changed();
		/**
		 * Fires after a draft was applied.
		 *
		 * @param array $draft      Draft.
		 * @param int   $history_id History row.
		 */
		do_action( 'dev3se_draft_applied', Drafts::get( $draft['id'] ), $history_id );
		return array(
			'draft'   => Drafts::get( $draft['id'] ),
			'history' => History::get( $history_id ),
			'value'   => $after,
		);
	}

	/**
	 * Rejects a draft.
	 *
	 * @param int $draft_id Draft id.
	 * @return array|\WP_Error
	 */
	public static function reject( $draft_id ) {
		$draft = Drafts::get( $draft_id );
		if ( ! $draft ) {
			return new \WP_Error( 'dev3se_not_found', __( 'Draft not found.', 'dev3-ai-seo-assistant' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $draft['status'] ) {
			return new \WP_Error( 'dev3se_not_pending', __( 'Only pending drafts can be rejected.', 'dev3-ai-seo-assistant' ), array( 'status' => 409 ) );
		}
		Drafts::set_status( $draft['id'], 'rejected' );
		return Drafts::get( $draft['id'] );
	}

	/**
	 * Undoes one history row.
	 *
	 * @param int  $history_id Row id.
	 * @param bool $force      Undo even if the live value changed since.
	 * @return array|\WP_Error Updated row.
	 */
	public static function undo( $history_id, $force = false ) {
		$row = History::get( $history_id );
		if ( ! $row ) {
			return new \WP_Error( 'dev3se_not_found', __( 'History entry not found.', 'dev3-ai-seo-assistant' ), array( 'status' => 404 ) );
		}
		if ( $row['undone_at'] ) {
			return new \WP_Error( 'dev3se_already_undone', __( 'This change was already undone.', 'dev3-ai-seo-assistant' ), array( 'status' => 409 ) );
		}
		if ( ! get_post( $row['post_id'] ) ) {
			return new \WP_Error( 'dev3se_post_missing', __( 'The post no longer exists.', 'dev3-ai-seo-assistant' ), array( 'status' => 404 ) );
		}
		$adapter = 'dev3' === $row['adapter'] ? null : $row['adapter'];
		$live    = Adapters::read( $row['post_id'], $row['field'], $adapter );
		if ( ! $force && ! self::same( $live, $row['after'] ) ) {
			return new \WP_Error(
				'dev3se_conflict',
				__( 'The value changed after this edit, so undo could overwrite newer work. Undo anyway?', 'dev3-ai-seo-assistant' ),
				array(
					'status'  => 409,
					'current' => $live,
				)
			);
		}
		$result = Adapters::write( $row['post_id'], $row['field'], $row['before'], $adapter );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		History::mark_undone( $row['id'] );
		self::changed();
		return History::get( $row['id'] );
	}

	/**
	 * Undoes a batch (newest first). Conflicting rows are skipped unless forced.
	 *
	 * @param string $batch Batch.
	 * @param bool   $force Force.
	 * @return array { undone, skipped: [ {id, message} ] }
	 */
	public static function undo_batch( $batch, $force = false ) {
		$undone  = 0;
		$skipped = array();
		foreach ( History::batch_ids( $batch ) as $id ) {
			$result = self::undo( $id, $force );
			if ( is_wp_error( $result ) ) {
				$skipped[] = array(
					'id'      => $id,
					'message' => $result->get_error_message(),
				);
			} else {
				++$undone;
			}
		}
		return compact( 'undone', 'skipped' );
	}

	/**
	 * Drops cached reports after a write.
	 */
	private static function changed() {
		Graph::reset();
		Audit::flush();
	}
}
