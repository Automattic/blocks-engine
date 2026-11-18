<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Issue #6674: production menus sometimes put a bare anchor in a nested ul.
$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Home</title><style>ul{list-style:none}</style></head><body><main><h1>Home</h1>
<ul class="submenu">
<li><a href="/a/">Projects</a>
<ul class="submenu nested"><a href="/a/conf/">Conference 2026</a></ul></li>
<li><a href="/b/">Other</a></li>
</ul></main></body></html>
HTML;

$runtime = new Runtime();
$walk = static function (array $blocks) use (&$walk, $assert): int {
    $nestedLists = 0;
    foreach ($blocks as $block) {
        $children = $block['innerBlocks'] ?? array();
        if ('core/list-item' === ($block['blockName'] ?? null)) {
            foreach ($children as $child) {
                $assert('core/list' === ($child['blockName'] ?? null), 'Only core/list may be a direct child of core/list-item.');
                ++$nestedLists;
            }
        }
        $nestedLists += $walk($children);
    }
    return $nestedLists;
};

foreach (array($html, str_replace(
    '<ul class="submenu nested"><a href="/a/conf/">Conference 2026</a></ul>',
    '<ol class="submenu nested"><a href="/a/conf/">Conference 2026</a><li>Existing item</li><span>Another item</span><script>ignored()</script></ol>',
    $html
)) as $variant => $source) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $markup = (string) $plan['pages'][0]['canonical_block_markup'];
    $assert(1 === $walk($runtime->parseBlocks($markup)), 'The nested list must survive as a List block.');
    $assert(str_contains($markup, 'Conference 2026</a>') && str_contains($markup, 'href="/a/conf/"'), 'The conference link survives.');
    $assert(str_contains($markup, 'submenu nested'), 'The nested list keeps its source classes.');
    $assert((0 === $variant ? 3 : 5) === substr_count($markup, '<!-- wp:list-item'), 'Each visible non-li member gets its own item alongside existing items.');
    if (1 === $variant) {
        $assert(str_contains($markup, '"ordered":true'), 'A nested ol remains ordered.');
        $assert(str_contains($markup, 'Existing item') && str_contains($markup, 'Another item'), 'Mixed list members retain their content.');
        $assert(! str_contains($markup, 'ignored()'), 'Scripts do not become synthetic items.');
    }
    $assert(str_contains((string) json_encode($plan), 'list-style:none'), 'The authored marker-free list style survives.');
}

echo "nested malformed list contract passed\n";
