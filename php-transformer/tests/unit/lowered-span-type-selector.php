<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
};

// Duda reviews: the typography rule lowers every nested span to a mark.
$html = '<style>.rev-dark{background:#444}'
    . '.rev-dark div.comment span > span a{color:#fff !important}'
    . '.single-review .comment span{font-size:14px;line-height:1.7}'
    . '</style><main><div class="rev-dark"><div class="single-review"><div class="comment">'
    . '<span><span><span>I had a dental emergency.</span><br><span><span>able to get me in'
    . '<span>... <a href="/reviews/">read more</a></span></span></span></span></span>'
    . '</div></div></div></main>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$assert(str_contains($result['serialized_blocks'], '<mark'), 'The repro actually lowers styled spans to marks');
$css = '';
foreach ($result['assets'] as $asset) {
    if ('author-css' === ($asset['source'] ?? '')) $css .= $asset['content'];
}
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $result['serialized_blocks'] . '</body>', LIBXML_NOERROR);
$link = (new DOMXPath($dom))->query('//a[@href="/reviews/"]')->item(0);
$assert($link instanceof DOMElement, 'Review link survives');
$matched = false;
(new CssStylesheetTransformer())->transformStyleRules($css, static function (string $selector, string $body) use ($link, &$matched): string {
    if (str_contains($body, 'color:#fff') && str_contains($body, '!important')) {
        $matched = $matched || CssSelectorMatcher::matches($link, CssSelectorMatcher::parse($selector))['matches'];
    }
    return $selector . '{' . $body . '}';
});
$assert($matched, 'The important white link rule reaches descendants through lowered span ancestors');

$bridge = ':is(span:not(:where(.wp-block-navigation-item__label,.blocks-engine-subject:not(.blocks-engine-subject-tag-span))),:where(.blocks-engine-subject-tag-span))';
$assert(str_contains($css, $bridge . ' > ' . $bridge), 'Both combinator steps bridge native and lowered spans');
foreach (array('span', ':not(span)', ':where(span)', ':is(span,.other)') as $source) {
    $projected = str_replace('span', $bridge, $source);
    $assert(CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($source)) === CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($projected)), 'Type specificity is unchanged in ' . $source);
}

// The bridge must not admit engine chrome or a carrier for another tag.
$controls = new DOMDocument();
$controls->loadHTML('<body><span></span><mark class="blocks-engine-subject blocks-engine-subject-tag-span"></mark><span class="wp-block-navigation-item__label"></span><span class="blocks-engine-subject blocks-engine-subject-tag-p"></span><mark></mark></body>');
$elements = (new DOMXPath($controls))->query('//body/*');
foreach ($elements as $index => $element) {
    $matches = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($bridge))['matches'];
    $assert(($index < 2) === $matches, 'Only native/source-span carriers match the bridge');
    $assert(($index >= 2) === CssSelectorMatcher::matches($element, CssSelectorMatcher::parse(':not(' . $bridge . ')'))['matches'], 'Negation excludes both native and lowered spans');
}
echo "Lowered span type selectors passed\n";
