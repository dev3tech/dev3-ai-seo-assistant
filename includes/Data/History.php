<?php
/**
 * History: before/after of every applied field, for undo.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Data;

use Dev3\AiSeoAssistant\Seo\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * History repository.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
 */
class History {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3se_history';
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $row { post_id, field, adapter, before, after, draft_id, batch }.
	 * @return int
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'post_id'      => (int) $row['post_id'],
				'field'        => (string) $row['field'],
				'adapter'      => substr( (string) ( $row['adapter'] ?? '' ), 0, 20 ),
				'before_value' => (string) $row['before'],
				'after_value'  => (string) $row['after'],
				'draft_id'     => (int) ( $row['draft_id'] ?? 0 ),
				'batch'        => substr( (string) ( $row['batch'] ?? '' ), 0, 40 ),
				'user_id'      => get_current_user_id(),
				'created_at'   => current_time( 'mysql', true ),
				'undone_by'    => 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Marks undone.
	 *
	 * @param int $id Id.
	 */
	public static function mark_undone( $id ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'undone_at' => current_time( 'mysql', true ),
				'undone_by' => get_current_user_id(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Lists rows.
	 *
	 * @param array $args { post_id, batch, field, search, page, per_page }.
	 * @return array { items, total }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$where = array( '1 = 1' );
		if ( ! empty( $args['post_id'] ) ) {
			$where[] = $wpdb->prepare( 'h.post_id = %d', (int) $args['post_id'] );
		}
		if ( ! empty( $args['batch'] ) ) {
			$where[] = $wpdb->prepare( 'h.batch = %s', (string) $args['batch'] );
		}
		if ( ! empty( $args['field'] ) && Fields::valid( $args['field'] ) ) {
			$where[] = $wpdb->prepare( 'h.field = %s', $args['field'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[] = $wpdb->prepare( 'p.post_title LIKE %s', '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' );
		}
		$from     = 'FROM ' . self::table() . " h LEFT JOIN {$wpdb->posts} p ON p.ID = h.post_id WHERE " . implode( ' AND ', $where );
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) {$from}" );
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT h.*, p.post_title {$from} ORDER BY h.id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		return array(
			'items' => array_map( array( self::class, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Not-undone ids of a batch, newest first.
	 *
	 * @param string $batch Batch.
	 * @return int[]
	 */
	public static function batch_ids( $batch ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE batch = %s AND undone_at IS NULL ORDER BY id DESC', (string) $batch ) ) );
	}

	/**
	 * Count since N days.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public static function count_since( $days = 30 ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE created_at >= %s AND undone_at IS NULL', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Public shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$user = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
		return array(
			'id'          => (int) $row['id'],
			'post_id'     => (int) $row['post_id'],
			'post_title'  => isset( $row['post_title'] ) ? (string) $row['post_title'] : get_the_title( (int) $row['post_id'] ),
			'field'       => (string) $row['field'],
			'field_label' => Fields::label( (string) $row['field'] ),
			'adapter'     => (string) $row['adapter'],
			'before'      => (string) $row['before_value'],
			'after'       => (string) $row['after_value'],
			'draft_id'    => (int) $row['draft_id'],
			'batch'       => (string) $row['batch'],
			'user'        => $user ? $user->display_name : '',
			'created_at'  => Drafts::iso( $row['created_at'] ),
			'undone_at'   => $row['undone_at'] ? Drafts::iso( $row['undone_at'] ) : null,
		);
	}
}
