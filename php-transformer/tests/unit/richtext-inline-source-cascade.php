<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// #7529: contextual rules must still address spans lowered inside a link.
$html = <<<'HTML'
<style>
.countPill{color:#fff;display:none;font-size:12px}
.navUser-item.navUser-item--cart .navUser-action .theme-icon:before{color:#f37321;font-size:28px}
.navUser-item.navUser-item--cart .navUser-action .countPill{font-size:11px;color:#243849}
.navUser-item.navUser-item--cart .navUser-action .countPill:before{content:"\0028"}
.navUser-item.navUser-item--cart .navUser-action .countPill:after{content:"\0029"}
</style>
<header><nav class="navUser"><ul class="navUser-section navUser-section--alt">
 <li class="navUser-item show"><a class="navUser-action navUser-item--compare" href="/compare"><span class="icon-random show theme-icon" data-icon="&#xe900;"></span> <span class="show countPill countPill--positive countPill--alt"></span></a></li>
 <li class="navUser-item navUser-item--cart show"><a class="navUser-action" href="/cart.php"><span class="icon-shopping-cart show theme-icon" data-icon="&#xe901;"></span> <span class="navUser-item-cartLabel show">Cart</span> <span class="countPill cart-quantity show">0</span><span class="cart-decoration" aria-hidden="true" id="cart-decoration"></span></a><div class="dropdown-menu" aria-hidden="true"></div></li>
</ul></nav></header><main><p>x</p></main>
HTML;
$result = (new HtmlTransformer())->transform($html, array('source' => 'website/index.html'))->toArray();
$css = '';
foreach ($result['assets'] as $asset) {
    if ('author-css' === ($asset['source'] ?? '')) $css .= $asset['content'];
}
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="UTF-8"><body>' . $result['serialized_blocks'] . '</body>', LIBXML_NOERROR);
$xpath = new DOMXPath($document);

// Resolve the emitted author rules by specificity and order, then inline
// declarations. Pseudo rules match their host, not the principal box.
$effective = static function (DOMElement $element, string $pseudo) use ($css): array {
    $values = array();
    $weights = array();
    (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $selector, string $body) use ($element, $pseudo, &$values, &$weights): string {
        foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
            $host = CssSelectorMatcher::pseudoElementHost($arm);
            if ('' === $pseudo ? null !== $host : null === $host || !str_ends_with($arm, ':' . $pseudo)) continue;
            $parsed = $host['parsed'] ?? CssSelectorMatcher::parse($arm);
            if (!$parsed['supported'] || !CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
            $weight = CssSelectorMatcher::specificity($parsed);
            foreach (SourceDeclarations::parse($body) as $property => $value) {
                if ($weight < ($weights[$property] ?? -1)) continue;
                $weights[$property] = $weight;
                $values[$property] = $value;
            }
        }
        return $selector . '{' . $body . '}';
    });
    return '' === $pseudo ? array_merge($values, SourceDeclarations::inline($element)) : $values;
};

$cases = array(
    array('cart-quantity', '', array('color' => '#243849', 'font-size' => '11px', 'display' => 'none')),
    array('cart-quantity', 'before', array('content' => '"\\0028"')),
    array('cart-quantity', 'after', array('content' => '"\\0029"')),
    array('icon-shopping-cart', 'before', array('color' => '#f37321', 'font-size' => '28px')),
);
foreach ($cases as [$class, $pseudo, $expected]) {
    $element = $xpath->query('//mark[contains(concat(" ",@class," ")," ' . $class . ' ")]')->item(0);
    if (!$element instanceof DOMElement) throw new RuntimeException('Missing RichText mark: ' . $class);
    $actual = $effective($element, $pseudo);
    foreach ($expected as $property => $value) {
        if ($value !== ($actual[$property] ?? null)) {
            throw new RuntimeException($class . ':' . $pseudo . ' ' . $property . ': expected ' . $value . ', got ' . ($actual[$property] ?? '(missing)'));
        }
    }
}

// Carrying contextual selector identity must not materialize away unrelated
// decorative descendants of the same outer link.
$decoration = $xpath->query('//a[@href="/cart.php"]/span[@id="cart-decoration" and @class="cart-decoration" and @aria-hidden="true"]');
if (1 !== $decoration->length) throw new RuntimeException('Cart decorative descendant must survive outer serialization unchanged');

echo "RichText inline source cascade passed\n";
