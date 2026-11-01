<?php
/**
 * FAQ block markup: the active SEO plugin's native FAQ block (Yoast / Rank Math print FAQPage schema
 * themselves), or core details blocks that DEV3's schema output covers.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Markup builders (must match each block's save() output so the editor validates them).
 */
class Faq_Markup {

	/**
	 * Cleans items to { question, answer } plain text.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function clean( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = trim( wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) );
			$a = trim( wp_strip_all_tags( (string) ( $item['answer'] ?? '' ) ) );
			if ( '' !== $q && '' !== $a ) {
				$out[] = array(
					'question' => mb_substr( $q, 0, 300 ),
					'answer'   => mb_substr( $a, 0, 1500 ),
				);
			}
		}
		return array_slice( $out, 0, 12 );
	}

	/**
	 * Block markup for a block name.
	 *
	 * @param array  $items   Items.
	 * @param string $block   yoast/faq-block|rank-math/faq-block|'' (core).
	 * @param bool   $heading Prepend a "Frequently asked questions" heading.
	 * @return string
	 */
	public static function blocks( array $items, $block, $heading = true ) {
		$items = self::clean( $items );
		if ( ! $items ) {
			return '';
		}
		$out = '';
		if ( $heading ) {
			$out .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html__( 'Frequently asked questions', 'dev3-ai-seo-assistant' ) . "</h2>\n<!-- /wp:heading -->\n\n";
		}
		switch ( $block ) {
			case 'yoast/faq-block':
				return $out . self::yoast( $items );
			case 'rank-math/faq-block':
				return $out . self::rank_math( $items );
		}
		return $out . self::core( $items );
	}

	/**
	 * Stable question id.
	 *
	 * @param string $prefix Prefix.
	 * @param int    $i      Index.
	 * @param string $q      Question.
	 * @return string
	 */
	private static function qid( $prefix, $i, $q ) {
		return $prefix . ( $i + 1 ) . '-' . substr( md5( $q ), 0, 6 );
	}

	/**
	 * Yoast FAQ block.
	 *
	 * @param array $items Items.
	 * @return string
	 */
	public static function yoast( array $items ) {
		$attrs = array();
		$html  = '';
		foreach ( $items as $i => $item ) {
			$id      = self::qid( 'faq-question-', $i, $item['question'] );
			$q       = esc_html( $item['question'] );
			$a       = esc_html( $item['answer'] );
			$attrs[] = array(
				'id'           => $id,
				'question'     => $q,
				'answer'       => $a,
				'jsonQuestion' => $q,
				'jsonAnswer'   => $a,
			);
			$html   .= '<div class="schema-faq-section" id="' . esc_attr( $id ) . '"><strong class="schema-faq-question">' . $q . '</strong> <p class="schema-faq-answer">' . $a . '</p> </div>';
		}
		return '<!-- wp:yoast/faq-block ' . self::json( array( 'questions' => $attrs ) ) . " -->\n<div class=\"schema-faq wp-block-yoast-faq-block\">" . $html . "</div>\n<!-- /wp:yoast/faq-block -->\n";
	}

	/**
	 * Rank Math FAQ block.
	 *
	 * @param array $items Items.
	 * @return string
	 */
	public static function rank_math( array $items ) {
		$attrs = array();
		$html  = '';
		foreach ( $items as $i => $item ) {
			$q       = esc_html( $item['question'] );
			$a       = esc_html( $item['answer'] );
			$attrs[] = array(
				'id'      => self::qid( 'faq-question-', $i, $item['question'] ),
				'title'   => $q,
				'content' => $a,
				'visible' => true,
			);
			$html   .= '<div class="rank-math-faq-item"><h3 class="rank-math-question">' . $q . '</h3><div class="rank-math-answer">' . $a . '</div></div>';
		}
		return '<!-- wp:rank-math/faq-block ' . self::json( array( 'questions' => $attrs ) ) . " -->\n<div class=\"wp-block-rank-math-faq-block\">" . $html . "</div>\n<!-- /wp:rank-math/faq-block -->\n";
	}

	/**
	 * Core blocks: details (WP 6.3+) or heading + paragraph pairs in a group.
	 *
	 * @param array $items Items.
	 * @return string
	 */
	public static function core( array $items ) {
		$out = '';
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'core/details' ) ) {
			foreach ( $items as $item ) {
				$out .= "<!-- wp:details {\"className\":\"dev3se-faq\"} -->\n<details class=\"wp-block-details dev3se-faq\"><summary>" . esc_html( $item['question'] ) . "</summary><!-- wp:paragraph -->\n<p>" . esc_html( $item['answer'] ) . "</p>\n<!-- /wp:paragraph --></details>\n<!-- /wp:details -->\n\n";
			}
			return $out;
		}
		$out .= "<!-- wp:group {\"className\":\"dev3se-faq\"} -->\n<div class=\"wp-block-group dev3se-faq\">";
		foreach ( $items as $item ) {
			$out .= "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . esc_html( $item['question'] ) . "</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>" . esc_html( $item['answer'] ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
		return $out . "</div>\n<!-- /wp:group -->\n";
	}

	/**
	 * Accessible HTML for the shortcode.
	 *
	 * @param array $items Items.
	 * @return string
	 */
	public static function details_html( array $items ) {
		$html = '<div class="dev3se-faq-list">';
		foreach ( self::clean( $items ) as $item ) {
			$html .= '<details class="dev3se-faq"><summary>' . esc_html( $item['question'] ) . '</summary><p>' . esc_html( $item['answer'] ) . '</p></details>';
		}
		return $html . '</div>';
	}

	/**
	 * Block-comment JSON (same escaping as serialize_block_attributes()).
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	private static function json( array $attrs ) {
		return function_exists( 'serialize_block_attributes' ) ? serialize_block_attributes( $attrs ) : (string) wp_json_encode( $attrs );
	}
}
