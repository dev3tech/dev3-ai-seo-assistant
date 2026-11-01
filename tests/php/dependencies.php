<?php
/**
 * Loads the free SEO plugins (Yoast SEO, Rank Math) before DEV3 in the test suite when wp-env installed them.
 *
 * @package Dev3\AiSeoAssistant
 */

tests_add_filter(
	'dev3se_test_dependencies',
	static function () {
		return array( 'wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php' );
	}
);

tests_add_filter( 'wp_mail_from', static fn() => 'wordpress@example.org' );
