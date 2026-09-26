<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' === $detail ? '' : ' - ' . $detail) . PHP_EOL);
};

$links = static function (array $items): string {
    $html = '';
    foreach ($items as $item) {
        $html .= '<a href="' . $item[1] . '">' . $item[0] . '</a>';
    }
    return $html;
};
$identical = array(array('Home', '/'), array('Journal', '/journal'), array('Shop', '/shop'));
$divergent = array(array('Home', '/'), array('Journal', '/journal'), array('Visits', '/visits'));
$document = static function (string $title, array $mobileItems) use ($links, $identical): string {
    $desktop = '<header class="site-header"><nav class="desktop-menu" aria-label="Site">' . $links($identical) . '</nav></header><main><h1>' . $title . '</h1></main>';
    $mobile = '<header class="site-header"><nav class="mobile-menu" aria-label="Site">' . $links($mobileItems) . '</nav></header><main><h1>' . $title . '</h1></main>';
    return '<div class="data-liberation-desktop-document">' . $desktop . '</div><div class="data-liberation-mobile-document">' . $mobile . '</div>';
};
$compile = static function (array $files): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
};
$markupOf = static function (array $plan): string {
    $markup = '';
    foreach (array_merge($plan['template_parts'], $plan['pages'], $plan['templates']) as $document) {
        $markup .= (string) ($document['canonical_block_markup'] ?? '');
    }
    return $markup;
};
$functionsOf = static function (array $plan): string {
    foreach ($plan['writes'] as $write) {
        if ('functions.php' === ($write['target_path'] ?? null)) {
            return (string) ($write['payload']['data'] ?? '');
        }
    }
    return '';
};
$entityCount = static function (string $functions): int {
    return preg_match_all("/'slug' => 'blocks-engine-navigation-\\d+'/", $functions);
};
$navigationRefs = static function (string $markup): array {
    $refs = array();
    if (!preg_match_all('/<!--\s*wp:navigation\s+(\{.*?\})\s*(?:\/)?-->/s', $markup, $matches)) {
        return $refs;
    }
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true);
        if (is_array($attrs) && isset($attrs['ref'])) {
            $refs[] = $attrs['ref'];
        }
    }
    return $refs;
};

$sharedPlan = $compile(array(
    'index.html' => $document('Home', $identical),
    'about.html' => $document('About', $identical),
));
$sharedMarkup = $markupOf($sharedPlan);
$sharedFunctions = $functionsOf($sharedPlan);
$sharedRefs = $navigationRefs($sharedMarkup);
$sharedNavCount = preg_match_all('/<!--\s*wp:navigation\s+/', $sharedMarkup);
$assert(2 === $sharedNavCount, 'identical variant menus stay two core/navigation blocks', 'count=' . $sharedNavCount);
$assert(2 === count($sharedRefs) && 1 === count(array_unique($sharedRefs)), 'identical variant menus share one navigation ref', json_encode($sharedRefs));
$assert(str_contains($sharedMarkup, '"className":"desktop-menu"') && str_contains($sharedMarkup, '"className":"mobile-menu"'), 'variant presentation stays on each navigation block', $sharedMarkup);
$assert(1 === $entityCount($sharedFunctions), 'identical variant menus emit one wp_navigation entity', (string) $entityCount($sharedFunctions));
$assert(str_contains($sharedFunctions, '"label":"Home"') && str_contains($sharedFunctions, '"label":"Journal"') && str_contains($sharedFunctions, '"label":"Shop"') && !str_contains($sharedFunctions, '"label":"Visits"'), 'the shared entity carries the identical item set');

$splitPlan = $compile(array('index.html' => $document('Home', $identical)));
$splitMarkup = $markupOf($splitPlan);
$splitRefs = $navigationRefs($splitMarkup);
$splitNavCount = preg_match_all('/<!--\s*wp:navigation\s+/', $splitMarkup);
$assert(2 === $splitNavCount && str_contains($splitMarkup, 'data-liberation-desktop-document') && str_contains($splitMarkup, 'data-liberation-mobile-document'), 'in-document variant menus remain two navigations inside their viewport wrappers', 'count=' . $splitNavCount);
$assert(2 === count($splitRefs) && 1 === count(array_unique($splitRefs)), 'in-document identical variant menus share one navigation ref', json_encode($splitRefs));
$assert(1 === $entityCount($functionsOf($splitPlan)), 'in-document identical variant menus emit one wp_navigation entity');

$splitDivergent = $compile(array('index.html' => $document('Home', $divergent)));
$assert(array() === $navigationRefs($markupOf($splitDivergent)), 'in-document divergent variant menus stay independent', json_encode($navigationRefs($markupOf($splitDivergent))));

$divergentPlan = $compile(array(
    'index.html' => $document('Home', $divergent),
    'about.html' => $document('About', $divergent),
));
$divergentMarkup = $markupOf($divergentPlan);
$divergentRefs = $navigationRefs($divergentMarkup);
$divergentNavCount = preg_match_all('/<!--\s*wp:navigation\s+/', $divergentMarkup);
$assert(2 === $divergentNavCount, 'divergent variant menus stay two core/navigation blocks', 'count=' . $divergentNavCount);
$assert(array() === $divergentRefs, 'divergent variant menus stay independent', json_encode($divergentRefs));
$assert(0 === $entityCount($functionsOf($divergentPlan)), 'divergent variant menus do not emit a shared wp_navigation entity');
$assert(str_contains($divergentMarkup, '"label":"Shop"') && str_contains($divergentMarkup, '"label":"Visits"'), 'divergent menus keep their own items');

if (0 < $failures) {
    fwrite(STDERR, "Shared variant navigation: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Shared variant navigation passed: {$passes} assertions\n";
