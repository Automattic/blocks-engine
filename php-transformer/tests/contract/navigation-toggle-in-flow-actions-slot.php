<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationOpenerRuntime;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$html = '<html><head><style>.site-header__main{display:flex;justify-content:space-between;align-items:center;gap:.75rem}.site-header__nav{display:none}.site-header__actions{display:flex;align-items:center;gap:.35rem}.icon-button{width:2.5rem;height:2.5rem;border:0;background:0 0;display:inline-grid;place-items:center}@media (min-width:1024px){.site-header__nav{display:flex}.site-header__menu{display:none}}</style></head><body><header class="site-header"><div class="site-header__main"><a class="site-logo" href="/"><span>Brand</span></a><nav class="site-header__nav"><a href="/a/">A</a><a href="/b/">B</a><a href="/c/">C</a></nav><div class="site-header__actions"><a class="icon-button" href="/search/" aria-label="Search"><svg viewBox="0 0 24 24" width="20" height="20"><circle cx="11" cy="11" r="8"/></svg></a><a class="button button--primary" href="/weekly/">Get Weekly</a><button class="icon-button site-header__menu" type="button" aria-label="Open menu"><svg viewBox="0 0 24 24" width="20" height="20"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button></div></div></header></body></html>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'] ?? '';
if (1 !== substr_count($markup, '<!-- wp:navigation ')) throw new RuntimeException('Expected one editable navigation.');
$assertActions = static function (array $blocks) use (&$assertActions): bool {
    foreach ($blocks as $block) {
        if (str_contains($block['attrs']['className'] ?? '', 'site-header__actions')) {
            $children = $block['innerBlocks'] ?? array();
            $last = end($children);
            if (!is_array($last) || !str_contains($last['attrs']['className'] ?? '', 'site-header__menu')
                || 'core/navigation' !== ($last['innerBlocks'][0]['blockName'] ?? '')) {
                throw new RuntimeException('The native navigation host must be the last actions item.');
            }
            return true;
        }
        if ($assertActions($block['innerBlocks'] ?? array())) return true;
    }
    return false;
};
if (!$assertActions($result['blocks'] ?? array())) throw new RuntimeException('Missing actions group.');
$weekly = strpos($markup, 'Get Weekly');
$navigation = strpos($markup, '<!-- wp:navigation ');
if (false === $weekly || false === $navigation || $navigation < $weekly) throw new RuntimeException('Native navigation must follow the weekly control in the actions slot.');
if (!preg_match('/class="[^"]*blocks-engine-navigation-toggle-slot[^\"]*icon-button[^\"]*site-header__menu[^\"]*"/', $markup)) throw new RuntimeException('Toggle slot must retain source classes.');
if (!str_contains($markup, 'data-blocks-engine-navigation-inline-slot') || !str_contains($markup, '"breakpoint":1023')) throw new RuntimeException('Inline position and source breakpoint must survive.');
if (!str_contains($markup, '"classes":"icon-button site-header__menu"')) throw new RuntimeException('Native opener must receive source classes.');
if (!str_contains(NavigationOpenerRuntime::source(), 'appendChild(nav)')) throw new RuntimeException('Runtime must move the single native navigation, not copy it.');
echo "Navigation in-flow actions slot passed.\n";
