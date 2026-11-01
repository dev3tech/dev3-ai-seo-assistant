<?php
/**
 * Posts list integration: "SEO (DEV3)" score column, "Optimize with DEV3 AI" row + bulk actions.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Admin;

use Dev3\AiSeoAssistant\Content\Audit;
use Dev3\AiSeoAssistant\Core3\Core\Capabilities;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Posts list.
 */
class Posts_List {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'hooks' ) );
	}

	/**
	 * Per-type hooks.
	 */
	public function hooks() {
		if ( ! Capabilities::can_manage() ) {
			return;
		}
		foreach ( Settings::post_types() as $type ) {
			if ( Settings::get( 'posts_list_column', true ) ) {
				add_filter( "manage_{$type}_posts_columns", array( $this, 'column' ) );
				add_action( "manage_{$type}_posts_custom_column", array( $this, 'cell' ), 10, 2 );
			}
			add_filter( "bulk_actions-edit-{$type}", array( $this, 'bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_bulk' ), 10, 3 );
		}
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_head-edit.php', array( $this, 'styles' ) );
	}

	/**
	 * Bulk screen URL for ids.
	 *
	 * @param int[] $ids Ids.
	 * @return string
	 */
	public static function bulk_url( array $ids ) {
		return admin_url( 'admin.php?page=' . DEV3SE_SLUG ) . '#/bulk/ids=' . implode( ',', array_map( 'intval', $ids ) );
	}

	/**
	 * Column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function column( $columns ) {
		$columns['dev3se_score'] = __( 'SEO (DEV3)', 'dev3-ai-seo-assistant' );
		return $columns;
	}

	/**
	 * Cell.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function cell( $column, $post_id ) {
		if ( 'dev3se_score' !== $column ) {
			return;
		}
		$rows = Audit::rows();
		if ( ! isset( $rows[ $post_id ] ) ) {
			echo '<span aria-hidden="true">&mdash;</span>';
			return;
		}
		$row   = $rows[ $post_id ];
		$tone  = $row['score'] >= 80 ? 'good' : ( $row['score'] >= 50 ? 'ok' : 'poor' );
		$label = Audit::issues();
		$tips  = array_map( static fn( $i ) => isset( $label[ $i ] ) ? $label[ $i ] : $i, $row['issues'] );
		/* translators: %d: score */
		$title = sprintf( __( 'SEO score %d/100', 'dev3-ai-seo-assistant' ), $row['score'] ) . ( $tips ? ': ' . implode( ', ', $tips ) : '' );
		printf( '<span class="dev3se-score dev3se-score--%1$s" title="%2$s">%3$d</span>', esc_attr( $tone ), esc_attr( $title ), (int) $row['score'] );
	}

	/**
	 * Row action.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( in_array( $post->post_type, Settings::post_types(), true ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['dev3se'] = '<a href="' . esc_url( self::bulk_url( array( $post->ID ) ) ) . '">' . esc_html__( 'Optimize with DEV3 AI', 'dev3-ai-seo-assistant' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Bulk action entry.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function bulk_action( $actions ) {
		$actions['dev3se_optimize'] = __( 'Optimize with DEV3 AI', 'dev3-ai-seo-assistant' );
		return $actions;
	}

	/**
	 * Redirects the bulk action to the Bulk screen.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'dev3se_optimize' !== $action ) {
			return $redirect;
		}
		return self::bulk_url( array_slice( (array) $ids, 0, 500 ) );
	}

	/**
	 * Column styles.
	 */
	public function styles() {
		echo '<style>.column-dev3se_score{width:90px}.dev3se-score{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:24px;padding:0 6px;border-radius:12px;font-weight:600;font-size:12px}.dev3se-score--good{background:#dcfce7;color:#14532d}.dev3se-score--ok{background:#fef3c7;color:#713f12}.dev3se-score--poor{background:#fee2e2;color:#7f1d1d}</style>';
	}
}
