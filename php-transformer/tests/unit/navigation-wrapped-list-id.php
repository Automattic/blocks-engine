<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Issue #3423's reduced page, with independent wrapper rules to pin ownership.
$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Home | Studio</title>
<style>
body{margin:0;font-family:Arial,sans-serif}
footer{padding:20px;background:#2d2924}
#menu-wrap{padding:11px;border-top:3px solid red}
#menu-wrap #menu li{letter-spacing:2px}
#menu{margin:0;list-style:none;padding:0}
#menu li{float:left;padding-right:20px;margin-right:20px;background:url(sep.svg) right 5px no-repeat}
#menu li:last-child{margin-right:0;padding-right:0;background:none}
#menu li a{font:14px Arial,sans-serif;line-height:20px;text-transform:uppercase;color:#776c5a;text-decoration:none}
</style></head>
<body>
<main><h1>Home</h1><p>Body text.</p></main>
<footer><div id="menu-wrap"><ul id="menu">
<li><a href="/about.html">About</a></li>
<li><a href="/work.html">Work</a></li>
<li><a href="/contact.html">Contact</a></li>
</ul></div><div style="clear:both"></div></footer>
</body></html>
HTML;

$failures = 0;
$assert = static function (bool $value, string $message) use (&$failures): void {
    if (!$value) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$flatten = static function (array $blocks) use (&$flatten): array {
    $result = array();
    foreach ($blocks as $block) { $result[] = $block; array_push($result, ...$flatten($block['innerBlocks'] ?? array())); }
    return $result;
};
$result = (new HtmlTransformer())->transform($html)->toArray();
$blocks = $flatten($result['blocks'] ?? array());
$navs = array_values(array_filter($blocks, static fn(array $b): bool => 'core/navigation' === ($b['blockName'] ?? '')));
$assert(1 === count($navs), 'one native navigation is emitted');
$nav = $navs[0] ?? array();
$assert('menu' === ($nav['attrs']['anchor'] ?? ''), 'navigation keeps the list id, not the wrapper id');
$carriers = array_values(array_filter($blocks, static fn(array $b): bool => 'core/group' === ($b['blockName'] ?? '') && 'menu-wrap' === ($b['attrs']['anchor'] ?? '')));
$assert(1 === count($carriers), 'wrapper retains its independent identity');
$assert('div' === ($carriers[0]['attrs']['tagName'] ?? ''), 'wrapper keeps its authored tag');
$assert('core/navigation' === ($carriers[0]['innerBlocks'][0]['blockName'] ?? ''), 'wrapper still contains the navigation');

// Model Core's rendered navigation: block classes are repeated on its list.
$escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
$classes = $escape((string) ($nav['attrs']['className'] ?? ''));
$carrierClasses = $escape((string) ($carriers[0]['attrs']['className'] ?? ''));
$dom = new DOMDocument();
$dom->loadHTML('<div id="menu-wrap" class="wp-block-group ' . $carrierClasses . '"><nav class="wp-block-navigation ' . $classes . '"><ul id="' . $escape((string) ($nav['attrs']['anchor'] ?? '')) . '" class="wp-block-navigation__container ' . $classes . '"><li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content">About</a></li><li class="wp-block-navigation-item"><a>Work</a></li><li class="wp-block-navigation-item"><a>Contact</a></li></ul></nav></div>', LIBXML_NOERROR | LIBXML_NOWARNING);
$items = $dom->getElementsByTagName('li');
$wrapper = $dom->getElementsByTagName('div')->item(0);
$rules = array();
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'] ?? '', static function(string $selector, string $body, array $conditions) use (&$rules): void {
        if (array() === $conditions) $rules[] = array($selector, $body);
    });
}
$matchingCss = static function(DOMElement $node) use ($rules): string {
    $css = '';
    foreach ($rules as [$selector, $body]) {
        if (CssSelectorMatcher::matches($node, CssSelectorMatcher::parse($selector))['matches'] ?? false) $css .= $body . ';';
    }
    return $css;
};
$itemCss = $matchingCss($items->item(0));
foreach (array('float:left', 'padding-right:20px', 'margin-right:20px', 'sep.svg', 'letter-spacing:2px') as $declaration) {
    $assert(str_contains($itemCss, $declaration), 'projected item receives ' . $declaration);
}
$listCss = $matchingCss($dom->getElementsByTagName('ul')->item(0));
$assert(!str_contains($listCss, 'padding:11px') && !str_contains($listCss, 'border-top:3px solid red'), 'wrapper box rules do not leak onto the list');
$wrapperCss = $matchingCss($wrapper);
$assert(str_contains($wrapperCss, 'padding:11px'), 'wrapper padding still applies to the wrapper');
$assert(str_contains($wrapperCss, 'border-top:3px solid red'), 'wrapper border still applies to the wrapper');
$lastCss = $matchingCss($items->item(2));
$assert(str_contains($lastCss, 'background:none') && str_contains($lastCss, 'padding-right:0') && str_contains($lastCss, 'margin-right:0'), 'last-child exception survives');
if ($failures) exit(1);
echo "Wrapped navigation list identity contract passed\n";
