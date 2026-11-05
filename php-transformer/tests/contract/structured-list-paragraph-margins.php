<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<ul><li><p><a href="/a">A</a></p></li><li><p><a href="/b">B</a></p></li><li><p><a href="/c">C</a></p></li></ul>';
$fixtures = array();
foreach (array('ua' => '', 'author' => 'p{margin:24px 0}li{margin:4px 0}', 'zero' => 'p,li{margin:0}') as $name => $author) {
    // Typography only: the acceptance case has no authored p/li margins.
    $html = '<!doctype html><style>body{font-size:16px;line-height:26px}' . $author . '</style>' . $source;
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = $result['serialized_blocks'] ?? '';
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    if (3 !== substr_count($markup, '<li ') || 3 !== preg_match_all('/<p[ >]/', $markup)) {
        throw new RuntimeException('Structured list must retain three item groups with native paragraphs.');
    }
    if (!str_contains($css, 'margin-top:revert;margin-bottom:revert') || !str_contains($css, 'margin-block-start:revert;margin-block-end:revert')) {
        throw new RuntimeException('Flow reset must restore source UA margins, not erase them.');
    }
    // A host's flow margins must lose, while UA and authored margins survive.
    $core = '.wp-block-group > *{margin-block-start:0;margin-block-end:0}.wp-block-group > * + *{margin-block-start:32px}';
    $fixtures[$name] = array('source' => $html, 'native' => '<!doctype html><style>' . $core . $css . '</style>' . $markup);
}
if ($path = getenv('STRUCTURED_LIST_MARGIN_ARTIFACT')) {
    file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
}
echo "Structured list paragraph margin contract passed.\n";
