<?php
declare(strict_types=1);

// A source control with no text, only an accessible name (a colour swatch),
// must still render: core/button renders nothing when its element is empty.
$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || !is_file($testsDir . '/includes/bootstrap.php')) {
    fwrite(STDOUT, "Empty button accessible label render skipped: WP_TESTS_DIR is unavailable.\n");
    exit('1' === getenv('REQUIRE_WP_TESTS') ? 1 : 0);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require $testsDir . '/includes/bootstrap.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>.sw{display:flex;gap:8px}.sw button{width:18px;height:18px;border-radius:50%;padding:0}</style>'
    . '<div class="sw" role="group" aria-label="Gold"><button type="button" aria-label="Yellow gold" title="Yellow gold"></button>'
    . '<button type="button" aria-label="White gold"></button></div>';
$rendered = do_blocks((new HtmlTransformer())->transform($html)->toArray()['serialized_blocks']);
foreach (array('Yellow gold', 'White gold') as $label) {
    if (1 !== preg_match('/<button\b[^>]*>(?:(?!<\/button>).)*' . preg_quote($label, '/') . '(?:(?!<\/button>).)*<\/button>/s', $rendered)) {
        throw new RuntimeException('The text-less control must render with its accessible name "' . $label . '": ' . $rendered);
    }
}
echo "Empty button accessible label render passed.\n";
