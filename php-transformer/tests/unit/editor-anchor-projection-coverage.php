<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};
$bytes = 0;
foreach (require dirname(__DIR__) . '/fixtures/editor-anchor-projection-pages.php' as $html) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $editor = '';
    $projected = '';
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        if ('editor-static-state' === ($asset['source'] ?? '')) {
            $editor .= $asset['content'] ?? '';
        } else {
            $projected .= $asset['content'] ?? '';
        }
    }
    $bytes += strlen($editor);
    $assert(str_contains($projected, ':is(#panel,.blocks-engine-editor-anchor-panel)'), 'The main projection covers the duplicated id.');
    $assert(str_contains($projected, ':is([id="panel"],.blocks-engine-editor-anchor-panel)'), 'The main projection covers attribute ids too.');
    foreach (array('rgb(12,34,56)', 'padding-left:17px', 'border-top-width:3px') as $body) {
        $assert(!str_contains($editor, $body), 'Do not replay a fully covered selector: ' . $body);
    }
    $assert(str_contains($editor, 'blocks-engine-editor-anchor-ordinary'), 'An uncovered id is still restated.');
    $assert(str_contains($editor, 'rgb(65,43,21)'), 'The uncovered rule retains its declarations.');
    $assert(str_contains($editor, 'margin-left:19px'), 'A selector mixing covered and uncovered ids is still restated.');
    $assert(!preg_match('/[^{}]*blocks-engine-editor-anchor-panel[^{}]*\{letter-spacing:2px/', $editor), 'Covered selector-list arms are omitted independently.');
    $assert(str_contains($editor, 'letter-spacing:2px'), 'The uncovered selector-list arm remains.');
    $assert((bool) preg_match('/blocks-engine-editor-anchor-ordinary[^{}]*\{[^}]*animation:none!important;opacity:1!important/', $editor), 'Remaining anchor animation copies are settled.');
}
fwrite(STDOUT, 'Multi-page fixture editor-static-state bytes: ' . $bytes . PHP_EOL);
exit($failures ? 1 : 0);
