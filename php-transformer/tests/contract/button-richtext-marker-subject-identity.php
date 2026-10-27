<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$html = <<<'HTML'
<style>
.cta{display:block;width:142px;height:42px;background:#fff;border:1px solid #000}
.cta__container{display:flex;justify-content:center;align-items:center;width:100%;height:100%}
.cta__label{font-size:16px}
</style>
<main>
<div><a class="cta button" href="/b"><span class="cta__container"><span class="cta__label">Learn More</span><span aria-hidden="true">›</span></span></a></div>
<p>See also <a class="cta-inline" href="/c"><span class="cta__container">inline marker</span></a>.</p>
</main>
HTML;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = implode('', array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
$assert(str_contains($markup, '<!-- wp:button '), 'The fixture produces a native button.');
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$marker = static fn (string $href): ?DOMNode => $xpath->query('//a[@href="' . $href . '"]//*[contains(concat(" ", normalize-space(@class), " "), " cta__container ")]')->item(0);
$button = $marker('/b');
$paragraph = $marker('/c');
$assert($button instanceof DOMElement && $paragraph instanceof DOMElement, 'Both source spans survive as label carriers.');
$classes = static fn (DOMElement $element): array => preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array();
$buttonClasses = $classes($button);
$paragraphClasses = $classes($paragraph);
$assert(in_array('blocks-engine-role-richtext', $buttonClasses, true), 'The button container is a RichText marker.');
$expected = array('blocks-engine-role-richtext', ...EngineMarker::subjectClasses('span', array('cta__container')));
$assert(array() === array_diff($expected, $paragraphClasses), 'The paragraph marker keeps its role, span subject and authored-class subject identity.');

$matched = false;
$foundRule = false;
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) ($asset['content'] ?? ''), $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        if (! str_contains($rule[1], '.cta__container') || ! str_contains($rule[2], 'display:flex') || ! str_contains($rule[2], 'justify-content:center')) continue;
        $foundRule = true;
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $branch) {
            // These branches are class compounds with :where() and :not().
            // Remove negations before collecting the required positive classes;
            // reject the branch if any negated class compound matches instead.
            preg_match_all('/:not\((:where\([^()]*\)|[^()]*)\)/', $branch, $negations);
            $excluded = false;
            foreach ($negations[1] as $negation) {
                preg_match_all('/\.([a-zA-Z0-9_-]+)/', $negation, $negativeClasses);
                if (array() !== $negativeClasses[1] && array() === array_diff($negativeClasses[1], $buttonClasses)) $excluded = true;
            }
            $positive = preg_replace('/:not\((:where\([^()]*\)|[^()]*)\)/', '', $branch);
            preg_match_all('/\.([a-zA-Z0-9_-]+)/', $positive, $required);
            if (! $excluded && array() !== $required[1] && array() === array_diff($required[1], $buttonClasses)) $matched = true;
        }
    }
}
$assert($foundRule, 'The emitted stylesheet retains the rewritten .cta__container layout rule.');
$assert($matched, 'The button .cta__container RichText marker must match at least one branch of the rewritten .cta__container rule.');
$assert(array() === array_diff($expected, $buttonClasses), 'The button marker carries the same subject identity as the paragraph marker.');
echo "button-richtext-marker-subject-identity: ok\n";
