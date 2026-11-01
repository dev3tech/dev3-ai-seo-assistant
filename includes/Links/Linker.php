<?php
/**
 * Paragraph-level link insertion: finds an anchor phrase in a paragraph's text (never inside an existing
 * link or a tag) and wraps the first occurrence in a link.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Linker.
 */
class Linker {

	/**
	 * Non-empty <p> elements with offsets.
	 *
	 * @param string $content Content.
	 * @return array [ { offset, length, inner, inner_offset, text } ]
	 */
	public static function paragraphs( $content ) {
		$out = array();
		if ( ! preg_match_all( '/(<p\b[^>]*>)(.*?)<\/p>/is', (string) $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		foreach ( $m[0] as $i => $whole ) {
			$inner = $m[2][ $i ][0];
			$text  = trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'offset'       => $whole[1],
				'length'       => strlen( $whole[0] ),
				'inner'        => $inner,
				'inner_offset' => $m[2][ $i ][1],
				'text'         => $text,
			);
		}
		return $out;
	}

	/**
	 * Text of a paragraph's HTML outside existing links (for anchor search).
	 *
	 * @param string $html Inner HTML.
	 * @return string
	 */
	public static function linkable_text( $html ) {
		$html = preg_replace( '/<a\b[^>]*>.*?<\/a>/is', ' ', (string) $html );
		return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Wraps the first linkable occurrence of $anchor in $html.
	 *
	 * @param string $html   Paragraph inner HTML.
	 * @param string $anchor Anchor text (plain).
	 * @param string $url    Target URL.
	 * @return string|null New HTML, or null when the anchor is not found outside links.
	 */
	public static function link_in( $html, $anchor, $url ) {
		$anchor = trim( (string) $anchor );
		if ( '' === $anchor ) {
			return null;
		}
		$parts   = preg_split( '/(<[^>]+>)/', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$in_link = 0;
		$needles = array_unique( array( $anchor, esc_html( $anchor ), htmlspecialchars( $anchor, ENT_NOQUOTES, 'UTF-8' ) ) );
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( preg_match( '/^<a\b/i', $part ) ) {
					++$in_link;
				} elseif ( preg_match( '/^<\/a>/i', $part ) ) {
					$in_link = max( 0, $in_link - 1 );
				}
				continue;
			}
			if ( $in_link ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				$pos = self::find( $part, $needle );
				if ( null !== $pos ) {
					$found       = substr( $part, $pos, strlen( $needle ) );
					$parts[ $i ] = substr( $part, 0, $pos ) . '<a href="' . esc_url( $url ) . '">' . $found . '</a>' . substr( $part, $pos + strlen( $needle ) );
					return implode( '', $parts );
				}
			}
		}
		return null;
	}

	/**
	 * Byte offset of a whole-word match (case-sensitive first, then case-insensitive).
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Needle.
	 * @return int|null
	 */
	private static function find( $haystack, $needle ) {
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u';
		if ( preg_match( $pattern, $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
			return $m[0][1];
		}
		if ( preg_match( $pattern . 'i', $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
			return $m[0][1];
		}
		return null;
	}

	/**
	 * Applies a list of links to content (by paragraph index). Links whose anchor is gone are skipped.
	 *
	 * @param string $content Content.
	 * @param array  $links   [ { paragraph, anchor, url } ].
	 * @return array { content, applied, skipped }
	 */
	public static function apply( $content, array $links ) {
		$paras   = self::paragraphs( $content );
		$by_para = array();
		foreach ( $links as $link ) {
			$by_para[ (int) $link['paragraph'] ][] = $link;
		}
		$applied = 0;
		$skipped = 0;
		// Work from the end so earlier offsets stay valid.
		krsort( $by_para );
		foreach ( $by_para as $index => $list ) {
			if ( ! isset( $paras[ $index ] ) ) {
				$skipped += count( $list );
				continue;
			}
			$inner = $paras[ $index ]['inner'];
			foreach ( $list as $link ) {
				$next = self::link_in( $inner, $link['anchor'], $link['url'] );
				if ( null === $next ) {
					++$skipped;
				} else {
					$inner = $next;
					++$applied;
				}
			}
			$content = substr( $content, 0, $paras[ $index ]['inner_offset'] ) . $inner . substr( $content, $paras[ $index ]['inner_offset'] + strlen( $paras[ $index ]['inner'] ) );
		}
		return compact( 'content', 'applied', 'skipped' );
	}

	/**
	 * Short context around an anchor in a paragraph.
	 *
	 * @param string $text   Paragraph text.
	 * @param string $anchor Anchor.
	 * @return string
	 */
	public static function context( $text, $anchor ) {
		$pos = mb_stripos( $text, $anchor );
		if ( false === $pos ) {
			return mb_substr( $text, 0, 200 );
		}
		$start = max( 0, $pos - 90 );
		$out   = mb_substr( $text, $start, 200 + mb_strlen( $anchor ) );
		return ( $start > 0 ? '…' : '' ) . $out . ( $start + mb_strlen( $out ) < mb_strlen( $text ) ? '…' : '' );
	}
}
