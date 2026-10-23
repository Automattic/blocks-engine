<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$html = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Menu spacing</title>
<style>
.menu{display:table;margin:0;padding:0;list-style:none}
.menu li{display:inline-table}
.menu a{display:block;padding:10px 0}
.menu .divider{display:table-cell;padding:0 15px}
</style></head><body>
<header><nav><ul class="menu">
<li><a href="#services">Services</a><div class="divider"></div></li>
<li><a href="#about">About</a><div class="divider"></div></li>
<li><a href="#contact">Contact</a></li>
</ul></nav></header>
<main><section id="services"><h2>Services</h2></section><section id="about"><h2>About</h2></section><section id="contact"><h2>Contact</h2></section></main>
</body></html>
HTML;

$check = static function (string $source, string $gap): void {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    preg_match('/<!-- wp:navigation (\{.*?\})\s*\/?-->/s', $markup, $match);
    $attrs = json_decode($match[1] ?? '{}', true);
    // Model Core's rendering box, not its display:contents inner list.
    $dom = new DOMDocument();
    $dom->loadHTML('<nav class="wp-block-navigation ' . htmlspecialchars($attrs['className'] ?? '') . '"></nav>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $nav = $dom->getElementsByTagName('nav')->item(0);
    $facts = array();
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'] ?? '', static function (string $selectors, string $body, array $conditions) use (&$facts, $nav): void {
            if (array() !== $conditions || !(CssSelectorMatcher::matches($nav, CssSelectorMatcher::parse($selectors))['matches'] ?? false)) return;
            foreach (array('display', 'gap', 'column-gap') as $property) {
                if (preg_match('/(?:^|;)\s*' . $property . '\s*:\s*([^;!}]+)/', $body, $m)) $facts[$property] = trim($m[1]);
            }
        });
    }
    if ($gap !== ($attrs['style']['spacing']['blockGap'] ?? null)
        || ('default' === ($attrs['layout']['type'] ?? '') && ('flex' !== ($facts['display'] ?? '') || $gap !== ($facts['column-gap'] ?? $facts['gap'] ?? null)))
        || 3 !== substr_count($markup, '<!-- wp:navigation-link ')
        || preg_match('/<div[^>]*class="(?:divider|neutral-cell)"/', $markup)
    ) {
        fwrite(STDERR, "Empty sibling navigation spacing failed (expected {$gap}): {$markup}\n");
        exit(1);
    }
};

$check($html, '30px');
$check(str_replace('divider', 'neutral-cell', $html), '30px');
$check(str_replace('display:table;margin', 'display:flex;gap:4px;margin', $html), '34px');
$check(str_replace('display:table;margin', 'display:table;gap:4px;margin', $html), '30px');
$check(str_replace('display:table-cell', 'display:none', $html), '0px');
$check(str_replace('display:table-cell', 'display:block', $html), '0px');
$check(str_replace('padding:0 15px', 'padding:0 5px;border-left:2px solid;width:18px', $html), '30px');
$check(str_replace('padding:0 15px', 'box-sizing:border-box;padding:0 5px;border-left:2px solid;width:30px', $html), '30px');
$check(str_replace('<a href="#about">About</a><div class="divider">', '<a href="#about">About</a><div class="divider" style="padding:0 10px">', $html), '0px');

echo "Empty sibling navigation spacing passed\n";
