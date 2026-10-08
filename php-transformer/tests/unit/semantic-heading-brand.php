<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = require dirname(__DIR__) . '/fixtures/semantic-heading-brand.php';
$compile = static fn(string $html): array => (new ArtifactCompiler())->compile(array('entrypoint'=>'index.html','files'=>array('index.html'=>$html)))->toArray();
$headings = static function (array $blocks) use (&$headings): array {
    $result = array();
    foreach ($blocks as $block) {
        if ('core/heading' === ($block['blockName'] ?? '')) $result[] = $block;
        array_push($result, ...$headings($block['innerBlocks'] ?? array()));
    }
    return $result;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
foreach (array($source, str_replace('href="/index.html"', 'class="brand" href="/index.html"', $source), str_replace('class="bar"', 'class="bar brand"', $source)) as $html) {
    $result = $compile($html);
    $brand = array_values(array_filter($headings($result['blocks'] ?? array()), static fn(array $block): bool => str_contains($block['attrs']['content'] ?? '', 'Harbor Studio')));
    $assert(1 === count($brand), 'nested home wordmark remains an editable semantic heading');
    $assert(3 === ($brand[0]['attrs']['level'] ?? null), 'wordmark preserves source heading level');
    $assert(str_contains($brand[0]['attrs']['content'] ?? '', '<a') && str_contains($brand[0]['attrs']['content'] ?? '', '/index.html'), 'wordmark preserves home link through native heading RichText');
    $assert(0 === ($result['metrics']['fallback_count'] ?? -1), 'semantic wordmark introduces no HTML fallback');
    $assert(str_contains($result['serialized_blocks'] ?? '', '"label":"Work"') && str_contains($result['serialized_blocks'] ?? '', '"label":"Contact"'), 'branding recognition preserves the adjacent menu as native items');
}
$menu = $compile('<style>.wordmark{font-size:26px}</style><header><nav><ul><li><a href="/index.html"><h3 class="wordmark">Menu heading</h3></a></li><li><a href="/about">About</a></li></ul></nav></header>');
$assert(array() === $headings($menu['blocks'] ?? array()), 'list-owned heading links stay native navigation labels');
$ordinary = $compile(str_replace('href="/index.html"', 'href="/topic"', $source));
$ordinaryHeadings = array_values(array_filter($headings($ordinary['blocks'] ?? array()), static fn(array $block): bool => str_contains($block['attrs']['content'] ?? '', 'Harbor Studio')));
$assert(1 === count($ordinaryHeadings), 'an ordinary heading link outside the nested menu preserves its heading semantics');
$assert(3 === ($ordinaryHeadings[0]['attrs']['level'] ?? null), 'an ordinary heading link preserves its source heading level');
$assert('<a href="/topic">Harbor Studio</a>' === ($ordinaryHeadings[0]['attrs']['content'] ?? ''), 'an ordinary heading link preserves its non-home destination and text');
$navigationItems = static function (array $blocks) use (&$navigationItems): array {
    $result = array();
    foreach ($blocks as $block) {
        if (in_array($block['blockName'] ?? '', array('core/navigation-link', 'core/navigation-submenu'), true)) $result[] = $block['attrs'] ?? array();
        array_push($result, ...$navigationItems($block['innerBlocks'] ?? array()));
    }
    return $result;
};
$items = $navigationItems($ordinary['blocks'] ?? array());
$assert(array('Work', 'Contact') === array_column($items, 'label') && array('#work', '#contact') === array_column($items, 'url'), 'an unbranded non-home heading link does not claim menu ownership or lose the adjacent menu');
$assert(!str_contains($ordinary['serialized_blocks'] ?? '', 'blocks-engine-brand-navigation-carrier'), 'an unbranded non-home heading link does not establish an independent brand carrier');
$assert(0 === ($ordinary['metrics']['fallback_count'] ?? -1), 'ordinary heading link conservation introduces no HTML fallback');
echo "Semantic heading brand: passed\n";
