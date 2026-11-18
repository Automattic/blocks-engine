<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// A capture after scrolling the page: the source's viewport driver added
// `fadeIn` to what it revealed. A JS-gated element it never revealed is
// settled visible; a carousel loop clone the source keeps hidden beside its
// revealed twin, and an element whose entrance the copy replays, must not be
// caught by that repair just because they share its class.
$entrance = htmlspecialchars('{"attributes":{"class":{"before":"preFade","after":"preFade fadeIn"}},"rootMargin":"0px","threshold":[0],"repeat":false}', ENT_QUOTES);
$html = <<<HTML
<!doctype html><html><head><style>
.preFade{opacity:0;transition:opacity .3s}
.preFade.fadeIn{opacity:1!important}
.slides{display:grid;grid-template-columns:repeat(2,1fr)}
</style></head><body><main>
<section><p class="preFade">Gated story text</p></section>
<section><p class="preFade fadeIn" data-blocks-engine-capture-viewport-entrance="{$entrance}">Witnessed entrance</p></section>
<section><ul class="slides">
<li class="slide preFade"><h3>Buy.</h3><p>Purchase advice.</p></li>
<li class="slide preFade fadeIn"><h3>Buy.</h3><p>Purchase advice.</p></li>
</ul></section>
</main></body></html>
HTML;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
$repairs = array();
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body) use (&$repairs): void {
    if (str_contains(str_replace(' ', '', $body), 'opacity:1!important') && ! str_contains($prelude, 'fadeIn')) {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) $repairs[] = CssSelectorMatcher::parse(trim($selector));
    }
});

$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="utf-8"?><html><body>' . preg_replace('/<!--.*?-->/s', '', (string) $result['serialized_blocks']) . '</body></html>');
$xpath = new DOMXPath($document);
$repaired = static function (string $query) use ($xpath, $repairs): bool {
    $element = $xpath->query($query)->item(0);
    if (! $element instanceof DOMElement) throw new RuntimeException("Missing {$query}");
    foreach ($repairs as $selector) if (CssSelectorMatcher::matches($element, $selector)['matches']) return true;
    return false;
};

foreach (array(
    array('//p[normalize-space(.)="Gated story text"]', true, 'The never-revealed gated text is settled visible.'),
    array('//p[normalize-space(.)="Witnessed entrance"]', false, 'The witnessed entrance keeps its hidden start pose for replay.'),
    array('(//li[contains(concat(" ", normalize-space(@class), " "), " slide ")])[1]', false, 'The loop clone stays hidden beside its revealed twin.'),
    array('(//li[contains(concat(" ", normalize-space(@class), " "), " slide ")])[2]', false, 'The revealed twin needs no repair.'),
) as [$query, $expected, $message]) {
    $assert($expected === $repaired($query), $message);
}

fwrite(STDOUT, "Closed-state repair scope contract passed\n");
