<?php
/**
 * Every DEV3 AI prompt of the plugin (core Prompts library: versioned, filterable, unit-tested).
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Ai;

use Dev3\AiSeoAssistant\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Prompts.
 */
class Prompt_Library {

	/**
	 * Shared system rules.
	 */
	const SYSTEM = 'You are an expert technical SEO editor for a WordPress site. Write in {{language}}. Tone: {{tone}}. Use only facts found in the provided content; never invent prices, ratings, dates, statistics or claims. Plain text only: no markdown, no HTML, no emojis, no quotation marks around values. {{instructions}}';

	/**
	 * Registers prompts.
	 */
	public static function register() {
		$str = static fn( $max ) => array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);

		Prompts::register(
			'dev3se_meta',
			array(
				'version'  => '1',
				'task'     => 'seo_meta',
				'system'   => self::SYSTEM,
				'template' => "Write search snippet metadata for this page.\n\nRules:\n- SEO title: {{title_max}} characters or fewer, the primary keyword near the start, compelling, unique. {{brand_rule}}\n- Meta description: {{desc_min}}-{{desc_max}} characters, includes the primary keyword naturally, states the benefit, ends with a soft call to action.\n- Focus keyword: the single search phrase (2-4 words) this page can rank for, lower case. {{keyword_rule}}\n- Secondary keywords: up to 4 related phrases that appear in or fit the content.\n- Social title (Open Graph): up to 90 characters, more conversational than the SEO title, no brand suffix.\n- Social description: up to 200 characters.\n- Give 3 different options for the SEO title and the meta description, best first.\n\nPage type: {{post_type}}\nURL slug: {{slug}}\nCurrent post title: {{title}}\nCurrent focus keyword: {{keyword}}\nCategories / tags: {{terms}}\nHeadings: {{headings}}\nExcerpt: {{excerpt}}\n\nContent:\n{{content}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'titles'             => array(
							'type'     => 'ARRAY',
							'items'    => $str( 75 ),
							'minItems' => 1,
							'maxItems' => 3,
						),
						'descriptions'       => array(
							'type'     => 'ARRAY',
							'items'    => $str( 180 ),
							'minItems' => 1,
							'maxItems' => 3,
						),
						'focus_keyword'      => $str( 60 ),
						'secondary_keywords' => array(
							'type'     => 'ARRAY',
							'items'    => $str( 60 ),
							'maxItems' => 4,
						),
						'og_title'           => $str( 95 ),
						'og_description'     => $str( 210 ),
					),
					'required'   => array( 'titles', 'descriptions', 'focus_keyword', 'og_title', 'og_description' ),
				),
				'profile'  => 'fast',
				'cache'    => DAY_IN_SECONDS,
			)
		);

		Prompts::register(
			'dev3se_schema',
			array(
				'version'  => '1',
				'task'     => 'seo_schema',
				'system'   => self::SYSTEM,
				'template' => "Suggest schema.org structured data for this page.\n\n1. main_type: the best page or article type, one of: {{types}}.\n2. pieces_json: a JSON array (as a string) of 0-3 extra JSON-LD objects that genuinely fit the content, e.g. HowTo (with step names/text from the content), FAQPage (questions answered in the content), Product, Recipe, Event, LocalBusiness, Course, SoftwareApplication, VideoObject. Each object needs \"@type\". Do NOT include @context, Organization, WebSite, WebPage, BreadcrumbList or Article (the SEO plugin prints those). Only use values that appear in the content; omit properties you cannot fill. Use an empty array [] if nothing fits.\n3. reason: one sentence explaining the choice.\n\nPage URL: {{url}}\nTitle: {{title}}\nPage type: {{post_type}}\nHeadings: {{headings}}\n\nContent:\n{{content}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'main_type'   => $str( 40 ),
						'pieces_json' => $str( 6000 ),
						'reason'      => $str( 300 ),
					),
					'required'   => array( 'main_type', 'pieces_json', 'reason' ),
				),
				'profile'  => 'heavy',
				'cache'    => DAY_IN_SECONDS,
			)
		);

		Prompts::register(
			'dev3se_anchors',
			array(
				'version'  => '1',
				'task'     => 'seo_links',
				'system'   => 'You add helpful internal links to a WordPress article. Reply with JSON only.',
				'template' => "Source article paragraphs (numbered):\n{{paragraphs}}\n\nCandidate target posts (id: title | keyword):\n{{targets}}\n\nFor each target that is relevant, choose ONE paragraph and an anchor text of 2-6 words copied EXACTLY (same spelling and case) from that paragraph, which naturally describes the target. Prefer descriptive phrases over generic ones (never 'click here', 'this post'). Use each paragraph at most twice and each target at most once. Skip targets that do not fit.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'links' => array(
							'type'     => 'ARRAY',
							'maxItems' => 15,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'target_id' => array( 'type' => 'INTEGER' ),
									'paragraph' => array( 'type' => 'INTEGER' ),
									'anchor'    => $str( 80 ),
									'reason'    => $str( 160 ),
								),
								'required'   => array( 'target_id', 'paragraph', 'anchor' ),
							),
						),
					),
					'required'   => array( 'links' ),
				),
				'profile'  => 'fast',
				'cache'    => DAY_IN_SECONDS,
			)
		);

		Prompts::register(
			'dev3se_gaps',
			array(
				'version'  => '1',
				'task'     => 'seo_gaps',
				'system'   => self::SYSTEM,
				'template' => "Act as a content strategist. Compare this article with what a complete, top-ranking article for the search phrase \"{{keyword}}\" would cover.\n\nReturn:\n- coverage_score: 0-100, how completely the article covers the topic today.\n- summary: 1-2 sentences.\n- missing_subtopics: up to 8 subtopics the article lacks, each with why it matters.\n- questions: up to 8 questions searchers ask that the article does not answer.\n- terms: up to 12 related terms/entities to mention.\n- outline: an improved outline (6-14 items) of H2 and H3 headings, keeping the article's good existing sections and adding the missing ones, each with a short note on what to write.\n\nOther posts on this site already cover (do not duplicate, suggest linking instead): {{related}}\n\nTitle: {{title}}\nHeadings: {{headings}}\n\nContent:\n{{content}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'coverage_score'    => array( 'type' => 'INTEGER' ),
						'summary'           => $str( 400 ),
						'missing_subtopics' => array(
							'type'     => 'ARRAY',
							'maxItems' => 8,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'topic' => $str( 120 ),
									'why'   => $str( 240 ),
								),
								'required'   => array( 'topic', 'why' ),
							),
						),
						'questions'         => array(
							'type'     => 'ARRAY',
							'maxItems' => 8,
							'items'    => $str( 200 ),
						),
						'terms'             => array(
							'type'     => 'ARRAY',
							'maxItems' => 12,
							'items'    => $str( 60 ),
						),
						'outline'           => array(
							'type'     => 'ARRAY',
							'maxItems' => 14,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'level'   => array( 'type' => 'INTEGER' ),
									'heading' => $str( 120 ),
									'notes'   => $str( 240 ),
									'exists'  => array( 'type' => 'BOOLEAN' ),
								),
								'required'   => array( 'level', 'heading' ),
							),
						),
					),
					'required'   => array( 'coverage_score', 'summary', 'missing_subtopics', 'questions', 'terms', 'outline' ),
				),
				'profile'  => 'heavy',
				'cache'    => DAY_IN_SECONDS,
			)
		);

		Prompts::register(
			'dev3se_faq',
			array(
				'version'  => '1',
				'task'     => 'seo_faq',
				'system'   => self::SYSTEM,
				'template' => "Write {{count}} frequently asked questions with answers for this page. Questions are what real searchers ask about \"{{keyword}}\" and must be answered by the content below. Answers: 1-3 sentences (max 300 characters), self-contained, factual, no marketing fluff. Do not repeat questions already used as headings: {{existing}}.\n\nTitle: {{title}}\n\nContent:\n{{content}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'items' => array(
							'type'     => 'ARRAY',
							'minItems' => 1,
							'maxItems' => 10,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'question' => $str( 200 ),
									'answer'   => $str( 600 ),
								),
								'required'   => array( 'question', 'answer' ),
							),
						),
					),
					'required'   => array( 'items' ),
				),
				'profile'  => 'fast',
				'cache'    => DAY_IN_SECONDS,
			)
		);

		Prompts::register(
			'dev3se_topics',
			array(
				'version'  => '1',
				'task'     => 'seo_topics',
				'system'   => 'You are an SEO content strategist. Write in {{language}}. Reply with JSON only.',
				'template' => "Here are the posts on a website (id | title | focus keyword):\n{{posts}}\n\nSuggest up to {{count}} NEW articles that would fill content gaps in this site's topic clusters (subtopics, comparisons, beginner guides, troubleshooting, use cases). For each: a working title, the focus keyword (lower case), why it fills a gap (one sentence), and up to 3 ids of existing posts that should link to it. Do not suggest topics that are already covered.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'topics' => array(
							'type'     => 'ARRAY',
							'maxItems' => 12,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'title'     => $str( 120 ),
									'keyword'   => $str( 60 ),
									'why'       => $str( 240 ),
									'link_from' => array(
										'type'     => 'ARRAY',
										'maxItems' => 3,
										'items'    => array( 'type' => 'INTEGER' ),
									),
								),
								'required'   => array( 'title', 'keyword', 'why' ),
							),
						),
					),
					'required'   => array( 'topics' ),
				),
				'profile'  => 'heavy',
				'cache'    => 6 * HOUR_IN_SECONDS,
			)
		);
	}
}
