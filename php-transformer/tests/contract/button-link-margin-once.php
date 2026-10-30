<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Reduced source rules: an intrinsic/auto-track header, an inherited 25px
// spacing variable, and a final painted link-button. The data predicate on
// the ancestor matters: its projection uses a different path from class-only
// selectors. Padding, border, background and typography are surface properties.
$html = '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><main><div class="header-row" data-row="" style="--item-spacing:25px"><span>Brand</span><nav><a href="/about">About</a><a href="/news">News</a></nav><span>Language</span><a class="action-button" data-control="" href="/contact">Contact us</a></div></main></body></html>';
foreach (array('grid margin', 'grid alignment', 'flex alignment') as $case) {
    $alignment = str_contains($case, 'alignment') ? 'align-self:center;justify-self:end;place-self:center end' : '';
    $layout = str_starts_with($case, 'grid')
        ? 'display:grid;grid-auto-flow:column;grid-template-columns:auto auto auto auto'
        : 'display:flex';
    $css = '.header-row{' . $layout . ';max-width:1224px;align-items:center}'
        . '.header-row[data-row] .action-button{margin-left:var(--item-spacing);' . $alignment . '}'
        . '.action-button[data-control]{padding:12px 24px;border:1px solid #123456;border-radius:6px;background:#234567;color:#fff;font-size:14px;white-space:nowrap}';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'styles.css' => $css)))->toArray();
    $document = new DOMDocument();
    @$document->loadHTML($result['serialized_blocks']);
    $xpath = new DOMXPath($document);
    $link = $xpath->query('//a[@href="/contact"]')->item(0);
    $assert($link instanceof DOMElement, $case . ': CTA is emitted as a native button link.');
    $button = $link->parentNode;
    $wrapper = $button->parentNode;
    $assert($button instanceof DOMElement && $wrapper instanceof DOMElement && str_contains($button->getAttribute('class'), 'wp-block-button') && str_contains($wrapper->getAttribute('class'), 'wp-block-buttons'), $case . ': CTA keeps the buttons > button > link chain.');
    $chain = array($wrapper, $button, $link);
    $marginOwners = array();
    $alignmentOwners = array();
    foreach ($result['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        // Match actual emitted selectors against the saved DOM, not marker
        // text: ancestry arms can carry a different marker from control arms.
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $asset['content'] ?? '', $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            $hasMargin = (bool) preg_match('/(?:^|;)\s*margin-left\s*:/', $rule[2]);
            $hasAlignment = (bool) preg_match('/(?:^|;)\s*(?:align-self|justify-self|place-self)\s*:/', $rule[2]);
            if (! $hasMargin && ! $hasAlignment) continue;
            foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
                // Equivalent exclusion, flattened for the static matcher's
                // limited nested functional-pseudo grammar.
                $selector = preg_replace('/:where\((\.[\w-]+):not\(\.wp-block-buttons,\.wp-block-button\)\)/', ':where($1):not(.wp-block-buttons):not(.wp-block-button)', $selector) ?? $selector;
                $parsed = CssSelectorMatcher::parse($selector);
                $assert($parsed['supported'], 'Ownership selectors must be testable: ' . $selector);
                foreach ($chain as $index => $element) {
                    if (! CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
                    if ($hasMargin) $marginOwners[$index] = true;
                    if ($hasAlignment) $alignmentOwners[$index] = true;
                    $assert(0 === $index, $case . ': source margin/self-alignment must not reach an inner button element: ' . $selector);
                }
            }
        }
    }
    $assert(array(0 => true) === $marginOwners, $case . ': source left margin reaches exactly one element, the outer wrapper.');
    if ('' !== $alignment) {
        $assert(array(0 => true) === $alignmentOwners, $case . ': source self-alignment reaches only the outer wrapper.');
    }
    $assert(! preg_match('/(?:^|;)\s*margin(?:-[a-z-]+)?\s*:/', $link->getAttribute('style')), $case . ': inner link has no inline margin.');
    foreach (array('padding-left:24px', 'border-width:1px', 'background-color:#234567', 'font-size:14px') as $paint) {
        $assert(str_contains($link->getAttribute('style'), $paint), $case . ': link keeps native surface styling: ' . $paint);
    }
}
echo "Button link margin-once contract passed.\n";
