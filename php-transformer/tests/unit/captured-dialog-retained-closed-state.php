<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

// Source wiring can remain in place instead of becoming a projected dialog.
$html = <<<'HTML'
<html><head><style>
.panel{display:none}.panel:hover{display:block}
</style></head><body>
<button aria-controls="drawer" aria-expanded="false" data-blocks-engine-capture-dialog-trigger="drawer">Open panel</button>
<div id="drawer" class="panel" hidden data-blocks-engine-capture-dialog-panel="drawer"
 data-blocks-engine-capture-existing-panel='{"resting":{"style":null,"class":"panel","hidden":true},"opened":{"style":"display:block","class":"panel"}}'><p>Panel content</p></div>
</body></html>
HTML;

foreach (array('retained', 'orphan', 'mismatched') as $case) {
    $source = $html;
    if ('orphan' === $case) {
        $source = preg_replace('/<button\b.*?<\/button>/s', '', $source);
    } elseif ('mismatched' === $case) {
        $source = str_replace('aria-controls="drawer"', 'aria-controls="other"', $source);
    }
    $result = (new HtmlTransformer())->transform($source, array(
        'runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]', '[data-blocks-engine-capture-dialog-panel]'),
    ))->toArray();
    $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $repair = 1 === preg_match('/:root #drawer\{[^}]*display:revert!important/', $css);
    $assert('retained' === $case ? ! $repair : $repair, $case . ': only a panel with matching retained wiring is exempt from reveal repair');
    if ('retained' === $case) {
        $markup = (string) ($result['serialized_blocks'] ?? '');
        $assert(str_contains($markup, 'aria-controls="drawer"') && str_contains($markup, 'data-blocks-engine-capture-dialog-trigger="drawer"'), 'the trigger retains its panel binding');
        $document = new DOMDocument();
        @$document->loadHTML($markup);
        $panel = $document->getElementById('drawer');
        $assert($panel instanceof DOMElement && $panel->hasAttribute('hidden'), 'the panel keeps its authored hidden state');
    }
}

if ($failures > 0) {
    exit(1);
}
echo "captured-dialog-retained-closed-state: ok\n";
