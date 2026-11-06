<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Core serializes newlines around an embed's iframe. Under an inherited
// pre-wrap they paint as line boxes and grow the aspect-ratio wrapper.
$rule = ':root :where(.blocks-engine-embed-normal-whitespace > .wp-block-embed__wrapper){white-space:normal}';
$iframe = '<iframe src="https://www.youtube.com/embed/jkhiVNPMpuM?rel=0" style="display:block;border:0;width:100%;height:auto;aspect-ratio:1.77778 / 1" width="1035" height="582"></iframe>';
$failures = array();
foreach (array(
    'pre-wrap ancestor' => array('.doc{white-space:pre-wrap}', true),
    'nearest normal ancestor wins' => array('.doc{white-space:pre-wrap}.video{white-space:normal}', false),
    'no whitespace preservation' => array('.doc{width:640px}', false),
) as $label => [$css, $expected]) {
    $out = (new HtmlTransformer())->transform('<style>' . $css . '</style><div class="doc"><div class="video">' . $iframe . '</div></div>')->toArray();
    $sheet = implode("\n", array_column($out['assets'] ?? array(), 'content'));
    $marked = str_contains((string) $out['serialized_blocks'], 'blocks-engine-embed-normal-whitespace');
    if (!str_contains((string) $out['serialized_blocks'], '<!-- wp:embed ') || $expected !== $marked || $expected !== str_contains($sheet, $rule)) {
        $failures[] = $label;
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'Embed wrapper whitespace contract failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo "Embed wrapper whitespace contract passed.\n";
