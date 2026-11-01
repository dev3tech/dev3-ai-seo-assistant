<?php
/**
 * Turns generator output (AI or basic) into pending field-level drafts.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Service;

use Dev3\AiSeoAssistant\Ai\Generator;
use Dev3\AiSeoAssistant\Content\Basic;
use Dev3\AiSeoAssistant\Data\Drafts;
use Dev3\AiSeoAssistant\Links\Linker;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Seo\Faq_Markup;
use Dev3\AiSeoAssistant\Seo\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Drafter.
 */
class Drafter {

	/**
	 * Fields a run may ask for.
	 */
	const RUN_FIELDS = array( 'seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description', 'schema_type', 'schema_jsonld' );

	/**
	 * Cleans a field list ('schema' expands to both schema fields).
	 *
	 * @param array $fields Fields.
	 * @return string[]
	 */
	public static function fields( array $fields ) {
		$out = array();
		foreach ( $fields as $f ) {
			if ( 'schema' === $f ) {
				$out[] = 'schema_type';
				$out[] = 'schema_jsonld';
			} elseif ( in_array( $f, self::RUN_FIELDS, true ) ) {
				$out[] = $f;
			}
		}
		$adapter = Adapters::current();
		return array_values( array_filter( array_unique( $out ), static fn( $f ) => 'schema_jsonld' === $f || $adapter->supports( $f ) ) );
	}

	/**
	 * Creates meta/schema drafts for a post.
	 *
	 * @param \WP_Post $post       Post.
	 * @param array    $fields     Fields.
	 * @param array    $opts       { mode: ai|basic, source, job_id, only_empty, context }.
	 * @return array|\WP_Error { drafts: [], skipped: [], model, tokens, cached }
	 */
	public static function meta( $post, array $fields, array $opts = array() ) {
		$opts    = array_merge(
			array(
				'mode'       => 'ai',
				'source'     => 'editor',
				'job_id'     => 0,
				'only_empty' => false,
				'context'    => 'admin',
			),
			$opts
		);
		$fields  = self::fields( $fields );
		$adapter = Adapters::current();
		$skipped = array();
		if ( $opts['only_empty'] ) {
			$fields = array_values(
				array_filter(
					$fields,
					static function ( $f ) use ( $post, &$skipped ) {
						if ( '' !== trim( Adapters::read( $post->ID, $f ) ) ) {
							$skipped[] = $f;
							return false;
						}
						return true;
					}
				)
			);
		}
		if ( ! $fields ) {
			return array(
				'drafts'  => array(),
				'skipped' => $skipped,
				'model'   => '',
				'tokens'  => 0,
				'cached'  => false,
			);
		}
		$values = array();
		$alts   = array();
		$notes  = array();
		$model  = '';
		$tokens = 0;
		$cached = false;
		$meta   = array_values( array_intersect( $fields, Fields::META ) );
		$basic  = 'basic' === $opts['mode'];
		if ( $meta ) {
			if ( $basic ) {
				$values = Basic::meta( $post, $meta );
			} else {
				$res = Generator::meta( $post, $meta, $opts['context'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$values = $res['values'];
				$alts   = $res['alternatives'];
				$model  = $res['model'];
				$tokens = $res['tokens'];
				$cached = $res['cached'];
			}
		}
		$wants_schema = array_intersect( array( 'schema_type', 'schema_jsonld' ), $fields );
		if ( $wants_schema ) {
			if ( $basic ) {
				if ( in_array( 'schema_type', $fields, true ) ) {
					$values['schema_type'] = Basic::schema_type( $post );
				}
				$faq = Basic::faq( $post );
				if ( in_array( 'schema_jsonld', $fields, true ) && count( $faq ) >= 2 ) {
					$values['schema_jsonld'] = (string) wp_json_encode( array( \Dev3\AiSeoAssistant\Seo\Schema_Output::faq_piece( $faq, (string) get_permalink( $post ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
			} else {
				$res = Generator::schema( $post, $opts['context'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				if ( in_array( 'schema_type', $fields, true ) ) {
					$values['schema_type'] = $res['type'];
					$notes['schema_type']  = $res['reason'];
				}
				if ( in_array( 'schema_jsonld', $fields, true ) && '' !== $res['jsonld'] ) {
					$values['schema_jsonld'] = $res['jsonld'];
					$notes['schema_jsonld']  = $res['reason'];
				}
				$model   = $model ? $model : $res['model'];
				$tokens += $res['tokens'];
			}
		}
		$drafts = array();
		foreach ( $values as $field => $value ) {
			$clean = Fields::sanitize( $field, $value );
			if ( is_wp_error( $clean ) || '' === $clean ) {
				$skipped[] = $field;
				continue;
			}
			$original = Adapters::read( $post->ID, $field );
			if ( Applier::same( $original, $clean ) ) {
				$skipped[] = $field;
				continue;
			}
			$id       = Drafts::create(
				array(
					'post_id'      => $post->ID,
					'field'        => $field,
					'kind'         => in_array( $field, array( 'schema_type', 'schema_jsonld' ), true ) ? 'schema' : 'meta',
					'adapter'      => 'schema_jsonld' === $field ? 'dev3' : $adapter->id(),
					'original'     => $original,
					'draft'        => $clean,
					'alternatives' => isset( $alts[ $field ] ) ? $alts[ $field ] : array(),
					'note'         => isset( $notes[ $field ] ) ? $notes[ $field ] : '',
					'source'       => $basic ? 'basic' : $opts['source'],
					'job_id'       => (int) $opts['job_id'],
					'model'        => $basic ? '' : $model,
					'tokens'       => $basic ? 0 : (int) round( $tokens / max( 1, count( $values ) ) ),
				)
			);
			$drafts[] = Drafts::get( $id );
		}
		return compact( 'drafts', 'skipped', 'model', 'tokens', 'cached' );
	}

	/**
	 * Content draft that inserts internal links.
	 *
	 * @param \WP_Post $post  Post.
	 * @param array    $links [ { paragraph, anchor, url, title } ].
	 * @param string   $source Source.
	 * @return array|\WP_Error Draft.
	 */
	public static function links( $post, array $links, $source = 'editor' ) {
		$clean = array();
		foreach ( $links as $l ) {
			if ( ! isset( $l['paragraph'], $l['anchor'], $l['url'] ) || '' === trim( (string) $l['anchor'] ) ) {
				continue;
			}
			$target = url_to_postid( (string) $l['url'] );
			if ( ! $target && ! empty( $l['target_id'] ) ) {
				$target = (int) $l['target_id'];
			}
			if ( ! $target || 'publish' !== get_post_status( $target ) || $target === $post->ID ) {
				continue;
			}
			$clean[] = array(
				'paragraph' => (int) $l['paragraph'],
				'anchor'    => sanitize_text_field( (string) $l['anchor'] ),
				'url'       => (string) get_permalink( $target ),
			);
		}
		if ( ! $clean ) {
			return new \WP_Error( 'dev3se_no_links', __( 'Choose at least one link with an anchor.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		$result = Linker::apply( (string) $post->post_content, $clean );
		if ( ! $result['applied'] ) {
			return new \WP_Error( 'dev3se_no_links', __( 'None of the anchors could be found in the content any more.', 'dev3-ai-seo-assistant' ), array( 'status' => 409 ) );
		}
		/* translators: %d: number of links */
		$note = sprintf( _n( 'Adds %d internal link', 'Adds %d internal links', $result['applied'], 'dev3-ai-seo-assistant' ), $result['applied'] );
		$id   = Drafts::create(
			array(
				'post_id'  => $post->ID,
				'field'    => 'content',
				'kind'     => 'links',
				'adapter'  => 'dev3',
				'original' => (string) $post->post_content,
				'draft'    => $result['content'],
				'note'     => $note,
				'source'   => $source,
			)
		);
		return Drafts::get( $id );
	}

	/**
	 * Content draft that adds (or replaces) an FAQ block.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $items  [ { question, answer } ].
	 * @param string   $source Source.
	 * @return array|\WP_Error Draft.
	 */
	public static function faq( $post, array $items, $source = 'editor' ) {
		$items = Faq_Markup::clean( $items );
		if ( ! $items ) {
			return new \WP_Error( 'dev3se_no_faq', __( 'Add at least one question with an answer.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		$block   = Adapters::current()->faq_block();
		$content = (string) $post->post_content;
		$markup  = Faq_Markup::blocks( $items, $block, false );
		$pattern = '' !== $block ? '/<!-- wp:' . preg_quote( $block, '/' ) . ' .*?<!-- \/wp:' . preg_quote( $block, '/' ) . ' -->\n?/s' : '';
		if ( '' !== $pattern && preg_match( $pattern, $content ) ) {
			$next = preg_replace( $pattern, str_replace( '$', '\$', $markup ), $content, 1 );
		} else {
			$next = rtrim( $content ) . "\n\n" . Faq_Markup::blocks( $items, $block, true );
		}
		/* translators: %d: number of questions */
		$note = sprintf( _n( 'FAQ with %d question', 'FAQ with %d questions', count( $items ), 'dev3-ai-seo-assistant' ), count( $items ) );
		$id   = Drafts::create(
			array(
				'post_id'      => $post->ID,
				'field'        => 'content',
				'kind'         => 'faq',
				'adapter'      => 'dev3',
				'original'     => $content,
				'draft'        => (string) $next,
				'alternatives' => $items,
				'note'         => $note,
				'source'       => $source,
			)
		);
		return Drafts::get( $id );
	}
}
