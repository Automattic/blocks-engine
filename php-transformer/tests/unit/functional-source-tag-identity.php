<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjectionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceStyleResolutionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$css = '.theme :not(a):not(input){color:#fff!important}.btn{color:#3c3c3c;background:#fff}';
$dom = new DOMDocument();
$dom->loadHTML('<body><div class="theme"><input type="submit" class="btn"><button type="submit" class="btn">Send</button><a>Link</a><span>Text</span></div></body>');
$styles = new AuthorStyleAnalysis('', $css, array(), $dom->getElementsByTagName('body')->item(0));
$projections = new AuthorSelectorProjectionState();
$projections->installAuthorStyles($styles);
$inputMarker = $projections->ensureTagMarker('input');
$projections->ensureTagMarker('a');
$context = new AuthorStylesheetProjectionContext($styles, new SourceStyleResolutionState(), $projections, new TransformationEvidenceState());
// Isolate selector projection from native-control materialization: the consumer
// is free to render the source input as any tag, provided it carries its marker.
$projector = (new ReflectionClass(AuthorStylesheetProjector::class))->newInstanceWithoutConstructor();
$rewrite = new ReflectionMethod(AuthorStylesheetProjector::class, 'rewriteSourceTagTypes');
$project = static fn(string $selector): string => $rewrite->invoke($projector, $selector, CssSelectorMatcher::parse($selector), $context);
$projectedCss = (new CssStylesheetTransformer())->transformStyleRules($css, static fn(string $selector, string $body): string => $project($selector) . '{' . $body . '}');
$assert(str_contains($projectedCss, 'color:#fff!important') && str_contains($projectedCss, 'color:#3c3c3c;background:#fff'), 'Declarations and importance survive');
$source = CssSelectorMatcher::parse('.theme :not(a):not(input)');
$destination = CssSelectorMatcher::parse($project('.theme :not(a):not(input)'));
$assert($destination['supported'], 'Projected negation remains matchable');
$assert(CssSelectorMatcher::specificityCounts($source) === CssSelectorMatcher::specificityCounts($destination), 'Negation specificity is preserved');
foreach (array('button', 'div', 'input') as $renderedTag) {
    $consumer = new DOMDocument();
    $consumer->loadHTML('<body><div class="theme"><button class="btn">Send</button><span>Text</span></div></body>');
    $theme = $consumer->getElementsByTagName('div')->item(0);
    $rendered = $consumer->createElement($renderedTag);
    $rendered->setAttribute('class', 'btn ' . $inputMarker);
    $theme->insertBefore($rendered, $theme->firstChild);
    $elements = $theme->childNodes;
    $assert(!CssSelectorMatcher::matches($elements->item(0), $destination)['matches'], 'A source input stays excluded on ' . $renderedTag);
    $assert(CssSelectorMatcher::matches($elements->item(1), $destination)['matches'], 'A source button still matches');
    $assert(CssSelectorMatcher::matches($elements->item(2), $destination)['matches'], 'An unconverted span still matches');
}
foreach (array('.theme :is(input,button)', '.theme :where(input,button)', '.theme :not(:is(input,button))', '.theme :is(input):hover') as $selector) {
    $actual = $project($selector);
    $assert(str_contains($actual, ':where(.' . $inputMarker . ')'), 'Nested input identity: ' . $selector);
    $assert(CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($selector)) === CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($actual)), 'Functional specificity: ' . $selector);
}
$has = '.theme:has(> input, :is(a, button)):hover';
$actual = $project($has);
$assert(str_contains($actual, '> :where(.' . $inputMarker . ')') && str_ends_with($actual, ':hover'), 'Relative :has lists and runtime states survive');
$untouched = '.theme:not([title="input:not(a)"]):lang(input)';
$assert($untouched === $project($untouched), 'Strings, attributes and non-selector functions are not types');
$assert('.theme :not(button)' === $project('.theme :not(button)'), 'Tags without a conversion marker stay unchanged');
echo "Functional source tag identity passed\n";
