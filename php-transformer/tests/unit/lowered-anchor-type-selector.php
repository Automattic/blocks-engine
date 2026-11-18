<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
};

$html = '<style>body{margin:0;font-family:sans-serif}'
    . '.cards{display:flex;gap:24px;padding:24px}'
    . '.cards > a{display:flex;flex-direction:column;width:300px;text-decoration:none;color:#2a493b;box-shadow:0 1px 3px rgba(0,0,0,.25)}'
    . '.cards > a .card-img{aspect-ratio:16/9;background-size:cover;background-position:center}'
    . '.card-name{padding:16px;text-align:center;background:#fff}'
    . 'a .card-img{border-radius:7px}'
    . '.cards > a:hover .card-img{opacity:.8}'
    . '@media(max-width:600px){.cards > a .card-img{aspect-ratio:4/3}}'
    . '.cards > :not(a){outline-width:3px}'
    . '</style><main><div class="cards">'
    . '<a href="/focus/"><div class="card-img" style="background-image:url(card.jpg)"></div><div class="card-name">Focus Areas</div></a>'
    . '<a href="/accelerator/"><div class="card-img" style="background-image:url(card.jpg)"></div><div class="card-name">Accelerator</div></a>'
    . '<div class="ordinary"><div class="card-img" style="background-image:url(card.jpg)"></div><p>Not a link</p></div>'
    . '</div><p><a href="/inline/">Inline link</a></p></main>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'Lowered cards remain editor-valid');

$css = '';
foreach ($result['assets'] as $asset) {
    if ('author-css' === ($asset['source'] ?? '')) $css .= $asset['content'];
}
$rules = array();
(new CssStylesheetTransformer())->transformStyleRules($css, static function (string $selector, string $body) use (&$rules): string {
    $rules[$body][] = $selector;
    return $selector . '{' . $body . '}';
});
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $result['serialized_blocks'] . '</body>', LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$images = iterator_to_array($xpath->query('//*[contains(concat(" ",@class," ")," card-img ")]'));
$assert(3 === count($images), 'Both card image boxes and the non-link control survive');
foreach (array('aspect-ratio:16/9', 'background-size:cover', 'border-radius:7px', 'aspect-ratio:4/3') as $declaration) {
    $selectors = array();
    foreach ($rules as $body => $arms) {
        if (str_contains($body, $declaration)) array_push($selectors, ...$arms);
    }
    $assert(array() !== $selectors, 'Author declaration survives: ' . $declaration);
    foreach ($images as $index => $image) {
        $matches = false;
        foreach ($selectors as $selector) {
            $matches = $matches || CssSelectorMatcher::matches($image, CssSelectorMatcher::parse($selector))['matches'];
        }
        $assert(($index < 2) === $matches, $declaration . ' reaches only linked image boxes');
    }
}
$assert(str_contains($css, ':is(a,:where(.blocks-engine-subject-tag-a:not(.blocks-engine-role-control))):hover'), 'Ancestor hover remains live on native and lowered anchors');
$assert(str_contains($css, '@media'), 'Conditional geometry stays conditional');
$assert(str_contains($css, ':not(:is(a,:where(.blocks-engine-subject-tag-a:not(.blocks-engine-role-control))))'), 'Negation excludes native and lowered anchors');

$inlineLink = $xpath->query('//a[@href="/inline/"]')->item(0);
$bridge = CssSelectorMatcher::parse(':is(a,:where(.blocks-engine-subject-tag-a:not(.blocks-engine-role-control)))');
$assert($inlineLink instanceof DOMElement && CssSelectorMatcher::matches($inlineLink, $bridge)['matches'], 'The same bridge still reaches native inline links');
foreach (array_slice($images, 0, 2) as $image) {
    $assert($image->parentNode instanceof DOMElement && CssSelectorMatcher::matches($image->parentNode, $bridge)['matches'], 'The bridge reaches the lowered card carrier');
}

// The bridge has exactly type specificity, not the marker class weight.
$assert(CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse('a')) === CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse(':is(a,:where(.blocks-engine-subject-tag-a:not(.blocks-engine-role-control)))')), 'Anchor bridge preserves specificity');
// Pages without a lowered link carrier (native links, button controls) keep native `a` selectors.
$plain = (new HtmlTransformer())->transform('<style>.row a{color:red}.row > a .x{opacity:.5}</style><div class="row"><a class="btn" href="/x">Go</a></div><p class="row"><a href="/y">Y</a></p>')->toArray();
$plainCss = '';
foreach ($plain['assets'] as $asset) {
    if ('author-css' === ($asset['source'] ?? '')) $plainCss .= $asset['content'];
}
$assert(! str_contains($plainCss, ':is(a,:where(.blocks-engine-subject-tag-a'), 'Pages without lowered anchor carriers keep native anchor selectors');
echo "Lowered anchor type selectors passed\n";
