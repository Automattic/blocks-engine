<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\StylesheetScopes;

// Support allocated per owner subset is bundled per route (#7279): each route
// loads at most one route-owned support sheet per delivery key, in the same
// rule order as before, and never another route's exclusive rules (#6458).
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fold = new ReflectionMethod(StylesheetScopes::class, 'foldSupport');
$routes = array('index.html', 'about.html', 'menu.html', 'contact.html');
// Every route carries every class, so exclusive rules would match elsewhere
// too: only ownership, not liveness, decides where a rule goes.
$documents = array();
foreach ($routes as $route) $documents[$route] = array('source_path' => $route, 'canonical_block_markup' => '<div class="g"></div><div class="a1"></div><div class="ab"></div><div class="bc"></div><div class="c1"></div><div class="abc"></div><div class="a2"></div><div class="bd"></div><div class="h"></div>');
$scope = static fn(string $route): array => array('kind' => 'page', 'source_path' => $route);
$sheet = static function (string $content, ?array $owners, string $placement = 'after-author') use ($scope): array {
    $target = 'assets/assets/css/engine-support-' . $placement . '-' . substr(hash('sha256', $content . json_encode($owners)), 0, 16) . '.css';
    return array('source' => 'engine-support', 'kind' => 'css', 'content' => $content, 'scopes' => null === $owners ? array(array('kind' => 'global')) : array_map($scope, $owners), 'stylesheet_placement' => $placement, 'source_path' => substr($target, 7), 'target_path' => $target);
};
$loads = static fn(array $asset, string $route): bool => in_array('global', array_column($asset['scopes'], 'kind'), true) || in_array($route, array_column($asset['scopes'], 'source_path'), true);
$rules = static function (array $assets, string $route) use ($loads): array {
    $sequence = array();
    foreach ($assets as $asset) if ($loads($asset, $route)) {
        $tree = new CssRuleTree($asset['content']);
        foreach ($tree->rules() as $row) $sequence[] = trim(substr($asset['content'], $row['start'], $row['end'] - $row['start'] + 1));
    }
    return $sequence;
};
$input = array(
    $sheet('.h{color:navy}', null, 'before-author'),
    $sheet('.a1{color:teal}', array('index.html'), 'before-author'),
    $sheet('.ab{color:olive}', array('index.html', 'about.html'), 'before-author'),
    $sheet('.g{color:red}', null),
    $sheet('.a1{margin:0}', array('index.html')),
    $sheet('.ab{padding:0}', array('index.html', 'about.html')),
    $sheet('.bc{padding:1px}', array('about.html', 'menu.html')),
    $sheet('.c1{margin:1px}', array('menu.html')),
    $sheet('.abc{border:0}', array('index.html', 'about.html', 'menu.html')),
    $sheet('.a2{margin:2px}', array('index.html')),
    $sheet('.bd{outline:0}', array('about.html', 'contact.html')),
);
$exclusive = array('index.html' => array('.a1{color:teal}', '.a1{margin:0}', '.a2{margin:2px}'), 'menu.html' => array('.c1{margin:1px}'));
$output = $fold->invoke(null, $input, $documents, $routes);
$contents = array_column($output, 'content');
$assert(count($contents) === count(array_unique($contents)), 'No two support sheets are byte-identical: ' . json_encode($contents));
foreach ($routes as $route) {
    $assert($rules($input, $route) === $rules($output, $route), 'Each route keeps its exact support rule sequence: ' . $route . ' ' . json_encode($rules($output, $route)));
    $owned = array();
    foreach ($output as $asset) if ($loads($asset, $route) && !in_array('global', array_column($asset['scopes'], 'kind'), true)) $owned[] = $asset['stylesheet_placement'];
    $assert(count($owned) === count(array_unique($owned)), 'Each route loads at most one route-owned support sheet per delivery key: ' . $route);
    foreach ($exclusive as $owner => $exclusiveRules) if ($owner !== $route) foreach ($exclusiveRules as $rule) {
        $assert(!in_array($rule, $rules($output, $route), true), 'No route receives another route\'s exclusive rules: ' . $route . ' ' . $rule);
    }
}
$owned = array_filter($output, static fn(array $asset): bool => 'after-author' === $asset['stylesheet_placement'] && !in_array('global', array_column($asset['scopes'], 'kind'), true));
$assert(4 === count($owned), 'Four routes with distinct support load four after-author bundles.');
// Shared rules stay in the shared global sheet.
$global = array_values(array_filter($output, static fn(array $asset): bool => in_array('global', array_column($asset['scopes'], 'kind'), true)));
$assert(array('.h{color:navy}', '.g{color:red}') === array_column($global, 'content'), 'Rules every route loads stay global.');

// Two routes with the same support share one resource rather than two
// byte-identical copies.
$twins = $fold->invoke(null, array($sheet('.g{color:red}', null), $sheet('.ab{padding:0}', array('index.html', 'about.html')), $sheet('.c1{margin:1px}', array('menu.html'))), $documents, $routes);
$assert(3 === count($twins) && array('index.html', 'about.html') === array_column($twins[1]['scopes'], 'source_path') && '.ab{padding:0}' === $twins[1]['content'], 'Identical route bundles are one resource: ' . json_encode($twins));

// A rule competing with an intervening global rule keeps its place; each
// route's sequence is still unchanged.
$competing = array($sheet('.a1{color:red}', array('index.html')), $sheet('.a1{color:blue}', null), $sheet('.a1{color:purple}', array('index.html', 'about.html')), $sheet('.a1{color:green}', array('index.html')));
$output = $fold->invoke(null, $competing, $documents, $routes);
foreach ($routes as $route) $assert($rules($competing, $route) === $rules($output, $route), 'A competing global rule keeps each route\'s cascade order: ' . $route);
$assert(!in_array('.a1{color:green}', $rules($output, 'about.html'), true), 'A competing run still keeps exclusive rules on their route.');

// A subset sheet larger than the threshold keeps one resource for its owners.
$large = '.ab{padding:0}' . str_repeat('.bc{margin:1px}', intdiv(StylesheetScopes::SUBSET_SHEET_THRESHOLD, 15) + 1);
$output = $fold->invoke(null, array($sheet('.a1{margin:0}', array('index.html')), $sheet($large, array('index.html', 'about.html')), $sheet('.c1{margin:1px}', array('about.html'))), $documents, $routes);
$assert(1 === count(array_filter($output, static fn(array $asset): bool => $large === $asset['content'])), 'A large subset sheet is written once: ' . count($output));
echo "Support route bundles passed\n";
