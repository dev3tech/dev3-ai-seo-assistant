<?php
/**
 * Field-level drafts: every generated value waits here until an editor applies or rejects it.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Data;

use Dev3\AiSeoAssistant\Seo\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Drafts repository.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
 */
class Drafts {

	const STATUSES = array( 'pending', 'applied', 'rejected', 'superseded' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3se_drafts';
	}

	/**
	 * CREATE TABLE statements (dev3se_schema_sql filter).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$drafts  = self::table();
		$history = History::table();
		$sql[]   = "CREATE TABLE {$drafts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field varchar(40) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT 'meta',
			adapter varchar(20) NOT NULL DEFAULT '',
			original longtext NOT NULL,
			draft longtext NOT NULL,
			alternatives longtext NOT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			source varchar(20) NOT NULL DEFAULT 'editor',
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			model varchar(80) NOT NULL DEFAULT '',
			tokens int(10) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_status (post_id,status),
			KEY status (status),
			KEY job (job_id)
		) {$charset};";
		$sql[]   = "CREATE TABLE {$history} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field varchar(40) NOT NULL DEFAULT '',
			adapter varchar(20) NOT NULL DEFAULT '',
			before_value longtext NOT NULL,
			after_value longtext NOT NULL,
			draft_id bigint(20) unsigned NOT NULL DEFAULT 0,
			batch varchar(40) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			undone_at datetime DEFAULT NULL,
			undone_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY post (post_id),
			KEY batch (batch)
		) {$charset};";
		return $sql;
	}

	/**
	 * Creates a pending draft; older pending drafts of the same post + field become superseded.
	 *
	 * @param array $row { post_id, field, kind, adapter, original, draft, alternatives, note, source, job_id, model, tokens }.
	 * @return int Draft id.
	 */
	public static function create( array $row ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'superseded', updated_at = %s WHERE post_id = %d AND field = %s AND status = 'pending'",
				$now,
				(int) $row['post_id'],
				(string) $row['field']
			)
		);
		$wpdb->insert(
			self::table(),
			array(
				'post_id'      => (int) $row['post_id'],
				'field'        => (string) $row['field'],
				'kind'         => substr( (string) ( $row['kind'] ?? 'meta' ), 0, 20 ),
				'adapter'      => substr( (string) ( $row['adapter'] ?? '' ), 0, 20 ),
				'original'     => (string) ( $row['original'] ?? '' ),
				'draft'        => (string) $row['draft'],
				'alternatives' => (string) wp_json_encode( array_values( (array) ( $row['alternatives'] ?? array() ) ) ),
				'note'         => mb_substr( (string) ( $row['note'] ?? '' ), 0, 250 ),
				'status'       => 'pending',
				'source'       => substr( (string) ( $row['source'] ?? 'editor' ), 0, 20 ),
				'job_id'       => (int) ( $row['job_id'] ?? 0 ),
				'model'        => substr( (string) ( $row['model'] ?? '' ), 0, 80 ),
				'tokens'       => (int) ( $row['tokens'] ?? 0 ),
				'user_id'      => get_current_user_id(),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * One draft.
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
	 * Updates status (+ optional final value).
	 *
	 * @param int    $id     Id.
	 * @param string $status Status.
	 * @param string $value  Applied value (null keeps the draft).
	 */
	public static function set_status( $id, $status, $value = null ) {
		global $wpdb;
		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( null !== $value ) {
			$data['draft'] = (string) $value;
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Lists drafts.
	 *
	 * @param array $args { status, post_id, job_id, field, search, user_id, page, per_page }.
	 * @return array { items, total, counts }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table = self::table();
		$where = array( '1 = 1' );
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[] = $wpdb->prepare( 'd.status = %s', $args['status'] );
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[] = $wpdb->prepare( 'd.post_id = %d', (int) $args['post_id'] );
		}
		if ( ! empty( $args['job_id'] ) ) {
			$where[] = $wpdb->prepare( 'd.job_id = %d', (int) $args['job_id'] );
		}
		if ( ! empty( $args['field'] ) && Fields::valid( $args['field'] ) ) {
			$where[] = $wpdb->prepare( 'd.field = %s', $args['field'] );
		}
		if ( ! empty( $args['author'] ) ) {
			$where[] = $wpdb->prepare( 'p.post_author = %d', (int) $args['author'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[] = $wpdb->prepare( 'p.post_title LIKE %s', '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' );
		}
		$from     = "FROM {$table} d LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE " . implode( ' AND ', $where );
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) {$from}" );
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 500, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT d.*, p.post_title {$from} ORDER BY d.id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		return array(
			'items' => array_map( array( self::class, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Counts by status.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Pending draft ids of a job.
	 *
	 * @param int $job_id Job id.
	 * @return int[]
	 */
	public static function pending_ids_for_job( $job_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE job_id = %d AND status = 'pending' ORDER BY id ASC", (int) $job_id ) ) );
	}

	/**
	 * Distinct job ids that created drafts, newest first.
	 *
	 * @param int $limit Limit.
	 * @return array [ { job_id, pending, total, created_at } ]
	 */
	public static function runs( $limit = 20 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT job_id, SUM(status = \'pending\') AS pending, COUNT(*) AS total, MIN(created_at) AS created_at FROM ' . self::table() . ' WHERE job_id > 0 GROUP BY job_id ORDER BY job_id DESC LIMIT %d', (int) $limit ), ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'job_id'     => (int) $r['job_id'],
				'pending'    => (int) $r['pending'],
				'total'      => (int) $r['total'],
				'created_at' => self::iso( $r['created_at'] ),
			),
			(array) $rows
		);
	}

	/**
	 * Deletes drafts of a post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function delete_for_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * ISO date.
	 *
	 * @param string $mysql MySQL UTC date.
	 * @return string
	 */
	public static function iso( $mysql ) {
		return $mysql ? mysql_to_rfc3339( (string) $mysql ) : '';
	}

	/**
	 * Public shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$alts = json_decode( (string) $row['alternatives'], true );
		return array(
			'id'           => (int) $row['id'],
			'post_id'      => (int) $row['post_id'],
			'post_title'   => isset( $row['post_title'] ) ? (string) $row['post_title'] : get_the_title( (int) $row['post_id'] ),
			'field'        => (string) $row['field'],
			'field_label'  => Fields::label( (string) $row['field'] ),
			'kind'         => (string) $row['kind'],
			'adapter'      => (string) $row['adapter'],
			'original'     => (string) $row['original'],
			'draft'        => (string) $row['draft'],
			'alternatives' => is_array( $alts ) ? $alts : array(),
			'note'         => (string) $row['note'],
			'status'       => (string) $row['status'],
			'source'       => (string) $row['source'],
			'job_id'       => (int) $row['job_id'],
			'model'        => (string) $row['model'],
			'tokens'       => (int) $row['tokens'],
			'user_id'      => (int) $row['user_id'],
			'created_at'   => self::iso( $row['created_at'] ),
			'updated_at'   => self::iso( $row['updated_at'] ),
		);
	}
}
