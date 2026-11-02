<?php
declare(strict_types=1);

$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || !is_file($testsDir . '/includes/bootstrap.php')) {
    fwrite(STDOUT, "Social anchor WordPress render skipped: WP_TESTS_DIR is unavailable.\n");
    exit('1' === getenv('REQUIRE_WP_TESTS') ? 1 : 0);
}
require dirname(__DIR__) . '/unit/social-anchor-colors.php';
require $testsDir . '/includes/bootstrap.php';

// social-link is dynamic: the parent's context must paint the generated li.
$markup = '<!-- wp:social-links ' . wp_json_encode($attrs) . ' -->'
    . '<ul class="wp-block-social-links has-icon-color has-icon-background-color">'
    . '<!-- wp:social-link {"url":"https://facebook.com/example","service":"facebook"} /-->'
    . '</ul><!-- /wp:social-links -->';
$rendered = do_blocks($markup);
if (!preg_match('/<li[^>]*style="[^"]*color:\s*#5b5b5b;\s*background-color:\s*#cacaca(?:;|")/i', $rendered)) {
    throw new RuntimeException('Dynamic social-link li must render the source pair: ' . $rendered);
}
echo "Social anchor WordPress render passed.\n";
