<?php
/**
 * DEV3 AI generators: meta, schema, FAQ, content gaps, site topics. Only the fields a feature
 * needs are sent (title, slug, excerpt, headings, up to 6,000 characters of text).
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Ai;

use Dev3\AiSeoAssistant\Content\Analyzer;
use Dev3\AiSeoAssistant\Content\Basic;
use Dev3\AiSeoAssistant\Core3\Api\Prompts;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Seo\Fields;
use Dev3\AiSeoAssistant\Seo\Schema_Output;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Generator.
 */
class Generator {

	const CONTENT_CHARS = 6000;

	/**
	 * Prompt variables shared by every post prompt.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function vars( $post ) {
		$headings = array_map( static fn( $h ) => 'H' . $h['level'] . ' ' . $h['text'], array_slice( Analyzer::headings( $post->post_content ), 0, 30 ) );
		$terms    = wp_get_post_terms( $post->ID, get_object_taxonomies( $post->post_type ), array( 'fields' => 'names' ) );
		$tones    = Settings::tones();
		$tone     = (string) Settings::get( 'tone', 'clear' );
		$lang     = (string) Settings::get( 'content_language', '' );
		$extra    = (string) Settings::get( 'instructions', '' );
		return array(
			'language'     => '' !== $lang ? $lang : self::site_language(),
			'tone'         => isset( $tones[ $tone ] ) ? $tones[ $tone ] : $tone,
			'instructions' => '' !== $extra ? 'House style: ' . $extra : '',
			'title'        => \Dev3\AiSeoAssistant\Content\Analyzer::title( $post ),
			'slug'         => (string) $post->post_name,
			'url'          => (string) get_permalink( $post ),
			'post_type'    => (string) $post->post_type,
			'excerpt'      => mb_substr( wp_strip_all_tags( (string) $post->post_excerpt ), 0, 400 ),
			'headings'     => $headings ? implode( ' / ', $headings ) : '(none)',
			'terms'        => is_array( $terms ) && $terms ? implode( ', ', array_slice( $terms, 0, 12 ) ) : '(none)',
			'keyword'      => Analyzer::primary_keyword( Adapters::read( $post->ID, 'focus_keyword' ) ),
			'content'      => mb_substr( Analyzer::text( $post->post_content ), 0, self::CONTENT_CHARS ),
		);
	}

	/**
	 * Site language name, e.g. "English (en_US)".
	 *
	 * @return string
	 */
	public static function site_language() {
		$locale = get_locale();
		return ( 0 === strpos( $locale, 'en' ) ? 'English' : 'the language of the content' ) . ' (' . $locale . ')';
	}

	/**
	 * Meta drafts.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string[] $fields Fields wanted.
	 * @param string   $context AI context.
	 * @return array|\WP_Error { values: field=>value, alternatives: field=>[], model, tokens, cached }
	 */
	public static function meta( $post, array $fields, $context = 'admin' ) {
		$adapter = Adapters::current();
		$vars    = self::vars( $post );
		$brand   = Settings::brand();
		$vars   += array(
			'title_max'    => Fields::max( 'seo_title' ),
			'desc_min'     => (int) Settings::get( 'description_min', 120 ),
			'desc_max'     => Fields::max( 'meta_description' ),
			'brand_rule'   => Settings::get( 'brand_suffix', true ) ? 'End with " ' . Settings::get( 'title_separator', '-' ) . ' ' . $brand . '" only if it still fits the limit.' : 'Do not add the site name.',
			'keyword_rule' => '' !== $vars['keyword'] ? 'Keep the current focus keyword unless it clearly does not fit the content.' : '',
		);
		$result  = Prompts::run( 'dev3se_meta', $vars, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data   = (array) ( $result['data'] ?? array() );
		$t_max  = Fields::max( 'seo_title' );
		$d_max  = Fields::max( 'meta_description' );
		$fit    = static fn( $items, $max ) => array_values( array_unique( array_filter( array_map( static fn( $s ) => Basic::trim_words( Fields::sanitize( 'seo_title', $s ), $max ), (array) $items ) ) ) );
		$titles = $fit( $data['titles'] ?? array(), $t_max );
		$descs  = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $s ) => Basic::trim_words( (string) Fields::sanitize( 'meta_description', $s ), $d_max ),
						(array) ( $data['descriptions'] ?? array() )
					)
				)
			)
		);
		$kw     = mb_strtolower( (string) Fields::sanitize( 'focus_keyword', $data['focus_keyword'] ?? '' ) );
		$second = array_values( array_filter( array_map( static fn( $s ) => mb_strtolower( (string) Fields::sanitize( 'focus_keyword', $s ) ), (array) ( $data['secondary_keywords'] ?? array() ) ) ) );
		if ( $adapter->multi_keywords() && '' !== $kw && $second ) {
			$kw_value = implode( ', ', array_slice( array_unique( array_merge( array( $kw ), $second ) ), 0, 5 ) );
		} else {
			$kw_value = $kw;
		}
		$all    = array(
			'seo_title'        => $titles,
			'meta_description' => $descs,
			'focus_keyword'    => array_values( array_unique( array_filter( array( $kw_value, $kw ) ) ) ),
			'og_title'         => array_filter( array( Basic::trim_words( (string) Fields::sanitize( 'og_title', $data['og_title'] ?? '' ), 90 ) ) ),
			'og_description'   => array_filter( array( Basic::trim_words( (string) Fields::sanitize( 'og_description', $data['og_description'] ?? '' ), 200 ) ) ),
		);
		$values = array();
		$alts   = array();
		foreach ( $fields as $field ) {
			if ( isset( $all[ $field ] ) && $all[ $field ] ) {
				$list             = array_values( $all[ $field ] );
				$values[ $field ] = $list[0];
				$alts[ $field ]   = array_slice( $list, 1 );
			}
		}
		if ( ! $values ) {
			return new \WP_Error( 'dev3se_empty_ai', __( 'DEV3 AI returned no usable suggestions. Try again.', 'dev3-ai-seo-assistant' ), array( 'status' => 502 ) );
		}
		return array(
			'values'       => $values,
			'alternatives' => $alts,
			'model'        => (string) ( $result['model'] ?? '' ),
			'tokens'       => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'       => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Schema suggestion.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $context AI context.
	 * @return array|\WP_Error { type, jsonld, reason, model, tokens }
	 */
	public static function schema( $post, $context = 'admin' ) {
		$vars          = self::vars( $post );
		$vars['types'] = implode( ', ', array_merge( Fields::ARTICLE_TYPES, Fields::PAGE_TYPES ) );
		$result        = Prompts::run( 'dev3se_schema', $vars, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = (array) ( $result['data'] ?? array() );
		$type = trim( (string) ( $data['main_type'] ?? '' ) );
		if ( ! Fields::valid_schema_type( $type ) || '' === $type ) {
			$type = Basic::schema_type( $post );
		}
		$json = trim( (string) ( $data['pieces_json'] ?? '' ) );
		$json = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $json );
		$norm = '' === $json || '[]' === $json ? '' : Schema_Output::normalize_json( $json );
		if ( is_wp_error( $norm ) ) {
			$norm = '';
		}
		return array(
			'type'   => $type,
			'jsonld' => $norm,
			'reason' => sanitize_text_field( (string) ( $data['reason'] ?? '' ) ),
			'model'  => (string) ( $result['model'] ?? '' ),
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
		);
	}

	/**
	 * FAQ items.
	 *
	 * @param \WP_Post $post    Post.
	 * @param int      $count   Count.
	 * @param string   $context AI context.
	 * @return array|\WP_Error { items, model, tokens }
	 */
	public static function faq( $post, $count = 5, $context = 'admin' ) {
		$vars             = self::vars( $post );
		$vars['count']    = max( 2, min( 10, (int) $count ) );
		$vars['keyword']  = '' !== $vars['keyword'] ? $vars['keyword'] : $vars['title'];
		$vars['existing'] = implode( ' / ', array_map( static fn( $i ) => $i['question'], Basic::faq( $post ) ) );
		$vars['existing'] = '' !== $vars['existing'] ? $vars['existing'] : '(none)';
		$result           = Prompts::run( 'dev3se_faq', $vars, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = \Dev3\AiSeoAssistant\Seo\Faq_Markup::clean( (array) ( $result['data']['items'] ?? array() ) );
		if ( ! $items ) {
			return new \WP_Error( 'dev3se_empty_ai', __( 'DEV3 AI returned no FAQ items. Try again.', 'dev3-ai-seo-assistant' ), array( 'status' => 502 ) );
		}
		return array(
			'items'  => array_slice( $items, 0, $vars['count'] ),
			'model'  => (string) ( $result['model'] ?? '' ),
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
		);
	}

	/**
	 * Content gaps + outline.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $keyword Keyword (default: focus keyword or title).
	 * @param array    $related Related site posts [ { id, title } ].
	 * @param string   $context AI context.
	 * @return array|\WP_Error
	 */
	public static function gaps( $post, $keyword = '', array $related = array(), $context = 'admin' ) {
		$vars            = self::vars( $post );
		$keyword         = trim( (string) $keyword );
		$vars['keyword'] = '' !== $keyword ? $keyword : ( '' !== $vars['keyword'] ? $vars['keyword'] : $vars['title'] );
		$vars['related'] = $related ? implode( '; ', array_map( static fn( $r ) => $r['title'], array_slice( $related, 0, 8 ) ) ) : '(none)';
		$result          = Prompts::run( 'dev3se_gaps', $vars, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data     = (array) ( $result['data'] ?? array() );
		$existing = array_map( static fn( $h ) => mb_strtolower( $h['text'] ), Analyzer::headings( $post->post_content ) );
		$outline  = array();
		foreach ( (array) ( $data['outline'] ?? array() ) as $item ) {
			$heading = sanitize_text_field( (string) ( $item['heading'] ?? '' ) );
			if ( '' === $heading ) {
				continue;
			}
			$outline[] = array(
				'level'   => 3 === (int) ( $item['level'] ?? 2 ) ? 3 : 2,
				'heading' => $heading,
				'notes'   => sanitize_text_field( (string) ( $item['notes'] ?? '' ) ),
				'exists'  => in_array( mb_strtolower( $heading ), $existing, true ),
			);
		}
		$clean_list = static fn( $items ) => array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $items ) ) ) );
		return array(
			'keyword'   => $vars['keyword'],
			'score'     => max( 0, min( 100, (int) ( $data['coverage_score'] ?? 0 ) ) ),
			'summary'   => sanitize_text_field( (string) ( $data['summary'] ?? '' ) ),
			'missing'   => array_values(
				array_filter(
					array_map(
						static fn( $m ) => array(
							'topic' => sanitize_text_field( (string) ( $m['topic'] ?? '' ) ),
							'why'   => sanitize_text_field( (string) ( $m['why'] ?? '' ) ),
						),
						(array) ( $data['missing_subtopics'] ?? array() )
					),
					static fn( $m ) => '' !== $m['topic']
				)
			),
			'questions' => $clean_list( $data['questions'] ?? array() ),
			'terms'     => $clean_list( $data['terms'] ?? array() ),
			'outline'   => $outline,
			'related'   => $related,
			'model'     => (string) ( $result['model'] ?? '' ),
			'tokens'    => (int) ( $result['usage']['total'] ?? 0 ),
		);
	}

	/**
	 * Site topic gaps.
	 *
	 * @param array  $rows    Audit rows.
	 * @param int    $count   Count.
	 * @param string $context AI context.
	 * @return array|\WP_Error { topics, model, tokens }
	 */
	public static function topics( array $rows, $count = 8, $context = 'admin' ) {
		$rows  = array_slice( array_values( array_filter( $rows, static fn( $r ) => 'publish' === $r['status'] ) ), 0, 300 );
		$lines = array_map( static fn( $r ) => $r['id'] . ' | ' . mb_substr( $r['title'], 0, 100 ) . ' | ' . $r['keyword'], $rows );
		if ( ! $lines ) {
			return new \WP_Error( 'dev3se_no_posts', __( 'Publish a few posts first: topic ideas are based on what the site already covers.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		$lang   = (string) Settings::get( 'content_language', '' );
		$result = Prompts::run(
			'dev3se_topics',
			array(
				'posts'    => implode( "\n", $lines ),
				'count'    => max( 3, min( 12, (int) $count ) ),
				'language' => '' !== $lang ? $lang : self::site_language(),
			),
			$context
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$ids    = array_map( static fn( $r ) => (int) $r['id'], $rows );
		$topics = array();
		foreach ( (array) ( $result['data']['topics'] ?? array() ) as $t ) {
			$title = sanitize_text_field( (string) ( $t['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$from     = array_values( array_intersect( array_map( 'intval', (array) ( $t['link_from'] ?? array() ) ), $ids ) );
			$topics[] = array(
				'title'     => $title,
				'keyword'   => mb_strtolower( sanitize_text_field( (string) ( $t['keyword'] ?? '' ) ) ),
				'why'       => sanitize_text_field( (string) ( $t['why'] ?? '' ) ),
				'link_from' => array_map(
					static fn( $id ) => array(
						'id'    => $id,
						'title' => \Dev3\AiSeoAssistant\Content\Analyzer::title( $id ),
					),
					$from
				),
			);
		}
		return array(
			'topics' => $topics,
			'model'  => (string) ( $result['model'] ?? '' ),
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
		);
	}
}
