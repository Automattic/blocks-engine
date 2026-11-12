<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Issue #5663: max-content makes the entire label the grid track's minimum.
$html = '<!doctype html><html><head><style>.d{display:grid;justify-items:start;width:318px}.button{display:inline-flex;padding:.7rem 1rem}</style></head><body><div class="d"><a class="button" href="/x/">Use the HVAC Labor Rate Calculator and more words</a><p>After</p></div></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$assert(str_contains($result['serialized_blocks'], 'Use the HVAC Labor Rate Calculator and more words'), 'The unpainted source label survives.');
// Unpainted anchors remain textual links; an explicit control role exercises
// native button lowering without adding paint or changing the sizing CSS.
$html = str_replace('class="button" href=', 'class="button" role="button" href=', $html);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$link = (new DOMXPath($document))->query('//a[@href="/x/"]')->item(0);
$assert($link instanceof DOMElement, 'The control link survives.');
$button = $link->parentNode;
$wrapper = $button->parentNode;
$assert($button instanceof DOMElement && $wrapper instanceof DOMElement && str_contains($button->getAttribute('class'), 'wp-block-button') && str_contains($wrapper->getAttribute('class'), 'wp-block-buttons'), 'The control has intrinsic and outer button wrappers.');
$widths = $caps = array();
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $asset['content'] ?? '', $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (! $parsed['supported']) continue;
            foreach (array($wrapper, $button, $link) as $index => $element) {
                if (! CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
                $assert(! preg_match('/(?:^|;)\s*width\s*:\s*max-content(?:\s*!important)?\s*(?:;|$)/', $rule[2]), 'Neither control nor wrappers may impose a max-content minimum: ' . $selector);
                if (preg_match('/(?:^|;)\s*width\s*:\s*fit-content(?:\s*!important)?\s*(?:;|$)/', $rule[2])) $widths[$index] = true;
                if (preg_match('/(?:^|;)\s*max-width\s*:\s*100%(?:\s*!important)?\s*(?:;|$)/', $rule[2])) $caps[$index] = true;
            }
        }
    }
}
foreach (array('outer wrapper', 'intrinsic wrapper', 'control') as $index => $name) {
    $assert(isset($widths[$index]), 'The ' . $name . ' must shrink to fit so the grid track can wrap the label.');
    $assert(isset($caps[$index]), 'The ' . $name . ' must retain its max-width:100% cap.');
}
echo "Inline control fit-content grid track contract passed.\n";
