<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Tailwind's `space-y-*` utility declares the variable its own margin reads in
// the same rule. A link lowered to core/button takes that margin on its outer
// core/buttons wrapper; the variable must reach the wrapper too, or the
// wrapper's margin references an undefined variable and computes to 0.
$html = '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><main><div class="space-y-6"><div class="card"><h2>Operation Hours</h2><p>Monday to Saturday</p></div><a class="cta" href="/contact">Book Now</a></div></main></body></html>';
$css = '.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem * var(--tw-space-y-reverse))}'
    . '.cta{display:inline-flex;width:100%;height:36px;padding:8px 16px;border-radius:10px;background:#123456;color:#fff;font-size:14px}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'styles.css' => $css)))->toArray();

$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$xpath = new DOMXPath($document);
$link = $xpath->query('//a[@href="/contact"]')->item(0);
$assert($link instanceof DOMElement, 'CTA is emitted as a link.');
$wrapper = $link->parentNode?->parentNode;
$assert($wrapper instanceof DOMElement && str_contains($wrapper->getAttribute('class'), 'wp-block-buttons'), 'CTA lowers to core/buttons > core/button > link.');

$marginOnWrapper = false;
$variableOnWrapper = false;
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $asset['content'] ?? '', $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        $margin = (bool) preg_match('/(?:^|;)\s*margin-top\s*:[^;]*var\(--tw-space-y-reverse\)/', $rule[2]);
        $variable = (bool) preg_match('/(?:^|;)\s*--tw-space-y-reverse\s*:\s*0\b/', $rule[2]);
        if (! $margin && ! $variable) continue;
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (! $parsed['supported'] || ! CssSelectorMatcher::matches($wrapper, $parsed)['matches']) continue;
            $marginOnWrapper = $marginOnWrapper || $margin;
            $variableOnWrapper = $variableOnWrapper || $variable;
        }
    }
}
$assert($marginOnWrapper, 'The sibling-stacking margin lands on the core/buttons wrapper.');
$assert($variableOnWrapper, 'The variable that margin reads is declared on the same core/buttons wrapper.');

echo "Button wrapper margin variable contract passed.\n";
