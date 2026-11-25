<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// A Tailwind `space-y-*` rule sets its own reverse variable and spaces
// siblings with it. When the spaced sibling is a link lowered to a button,
// the margin moves to the outer core/buttons wrapper. The variable that
// margin reads must reach that wrapper too: an undefined variable makes the
// margin invalid at computed-value time and the spacing collapses to zero.
$html = '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><main>'
    . '<div class="space-y-6"><div class="card"><h3>Operation Hours</h3><p>Monday to Saturday</p></div>'
    . '<a class="cta" href="/contact">Book Now</a></div>'
    . '</main></body></html>';
$css = '.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem * calc(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem * var(--tw-space-y-reverse))}'
    . '.card{border:1px solid #ddd;padding:24px}'
    . '.cta{display:inline-flex;height:36px;width:100%;padding:8px 16px;border-radius:10px;background:#1c362b;color:#fff;font-size:14px}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'styles.css' => $css)))->toArray();
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$xpath = new DOMXPath($document);
$link = $xpath->query('//a[@href="/contact"]')->item(0);
$assert($link instanceof DOMElement, 'CTA is emitted as a native button link.');
$wrapper = $link->parentNode?->parentNode;
$assert($wrapper instanceof DOMElement && str_contains($wrapper->getAttribute('class'), 'wp-block-buttons'), 'CTA keeps the buttons > button > link chain.');

// Elements whose computed value of the variable the wrapper inherits from.
$scope = array();
for ($node = $wrapper; $node instanceof DOMElement; $node = $node->parentNode) {
    $scope[] = $node;
}
$marginOnWrapper = false;
$variableInScope = false;
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $asset['content'] ?? '', $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        $readsVariable = (bool) preg_match('/(?:^|;)\s*margin-top\s*:[^;]*var\(--tw-space-y-reverse\)/', $rule[2]);
        $setsVariable = (bool) preg_match('/(?:^|;)\s*--tw-space-y-reverse\s*:/', $rule[2]);
        if (! $readsVariable && ! $setsVariable) continue;
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (! $parsed['supported']) continue;
            if ($readsVariable && CssSelectorMatcher::matches($wrapper, $parsed)['matches']) {
                $marginOnWrapper = true;
            }
            if ($setsVariable) {
                foreach ($scope as $element) {
                    $variableInScope = $variableInScope || CssSelectorMatcher::matches($element, $parsed)['matches'];
                }
            }
        }
    }
}
$assert($marginOnWrapper, 'The spaced sibling margin reaches the core/buttons wrapper.');
$assert($variableInScope, 'The variable the wrapper margin reads is declared on the wrapper or an ancestor.');
echo "Button margin custom-property carrier contract passed.\n";
