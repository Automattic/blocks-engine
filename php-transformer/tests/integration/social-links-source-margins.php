<?php
declare(strict_types=1);

$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || !is_file($testsDir . '/includes/bootstrap.php')) {
    fwrite(STDOUT, "Social links margins WordPress render skipped: WP_TESTS_DIR is unavailable.\n");
    exit('1' === getenv('REQUIRE_WP_TESTS') ? 1 : 0);
}
require dirname(__DIR__) . '/unit/social-links-source-margins.php';
require $testsDir . '/includes/bootstrap.php';

$result = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($source)->toArray();
$rendered = do_blocks($result['serialized_blocks']);
$assert(1 === preg_match('/<ul[^>]*class="[^"]*blocks-engine-social-source-non-list/', $rendered), 'Dynamic rendering retains the zero-margin CSS target');
$assert(str_contains($rendered, 'wp-social-link-facebook'), 'WordPress renders the dynamic social-link children');
$css = implode("\n", array_column($result['assets'], 'content'));
$assert(str_contains($css, $reset), 'The rendered list has a generated top/bottom zero margin rule');
echo "Social links source margins WordPress render passed.\n";
