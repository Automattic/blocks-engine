<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\StylesheetScopes;

// An earlier support rule repeated verbatim by a later support sheet of the
// same delivery, loaded on every route the earlier one loads, is dead (#7301):
// it goes, and a sheet it leaves empty is not emitted.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$routes = array('index.html', 'about.html', 'menu.html');
$documents = array();
foreach ($routes as $route) $documents[$route] = array('source_path' => $route, 'canonical_block_markup' => '<div class="a b c d e f g h k"></div>');
$scope = static fn(string $route): array => array('kind' => 'page', 'source_path' => $route);
$sheet = static function (string $content, ?array $owners, string $placement = 'after-author', string $target = 'both') use ($scope): array {
    $path = 'assets/assets/css/engine-support-' . $placement . '-' . substr(hash('sha256', $content . json_encode($owners) . $target), 0, 16) . '.css';
    return array('source' => 'engine-support', 'kind' => 'css', 'content' => $content, 'scopes' => null === $owners ? array(array('kind' => 'global')) : array_map($scope, $owners), 'stylesheet_placement' => $placement, 'stylesheet_target' => $target, 'source_path' => substr($path, 7), 'target_path' => $path);
};
$fold = static fn(array $assets): array => StylesheetScopes::foldSupport($assets, $documents, $routes);
$contents = static fn(array $assets): string => implode("\n", array_column($assets, 'content'));
// What one route loads, in order.
$loaded = static fn(array $assets, string $route): string => $contents(array_filter($assets, static fn(array $asset): bool => in_array('global', array_column($asset['scopes'], 'kind'), true) || in_array($route, array_column($asset['scopes'], 'source_path'), true)));

// A route sheet whose only rule repeats in a later global sheet disappears;
// a route sheet with other rules keeps them. The selector list matches
// after normalization.
$output = $fold(array(
    $sheet('.a{color:red}', array('index.html')),
    $sheet('.b,.c{margin:0 !important}.d{padding:0}', array('about.html', 'menu.html')),
    $sheet('.a{color:red}.b, .c{ margin: 0 !important }', null),
));
$assert(1 === substr_count($contents($output), '.a{color:red}'), 'The dead route copy of a global rule is dropped: ' . $contents($output));
$assert(1 === substr_count($contents($output), 'margin'), 'A normalized duplicate selector list is dropped: ' . $contents($output));
$assert(str_contains($contents($output), '.d{padding:0}'), 'The route sheet keeps its other rules.');
$assert(!in_array('index.html', array_column(array_merge(...array_column($output, 'scopes')), 'source_path'), true), 'An emptied route sheet is not emitted: ' . json_encode($output));

// Counter-cases keep the earlier rule.
$kept = array(
    'fewer routes' => array($sheet('.e{color:red}', array('index.html', 'about.html')), $sheet('.e{color:red}', array('index.html'))),
    'different declarations' => array($sheet('.e{color:red}', array('index.html')), $sheet('.e{color:blue}', null)),
    'different importance' => array($sheet('.e{color:red}', array('index.html')), $sheet('.e{color:red!important}', null)),
    'declaration order' => array($sheet('.e{color:red;color:blue}', array('index.html')), $sheet('.e{color:blue;color:red}', null)),
    'different selectors' => array($sheet('.e{color:red}', array('index.html')), $sheet('.e,.f{color:red}', null)),
    'escaped semicolon' => array($sheet('.e{--x:foo\\;}', array('index.html')), $sheet('.e{--x:foo}', null)),
    'url in other rule' => array($sheet('.e{color:red}.f{background:url(a.png)}', array('index.html')), $sheet('.e{color:red}', null)),
    'different media' => array($sheet('@media (min-width:600px){.e{color:red}}', array('index.html')), $sheet('@media (min-width:700px){.e{color:red}}', null)),
    'no media' => array($sheet('@media (min-width:600px){.e{color:red}}', array('index.html')), $sheet('.e{color:red}', null)),
    'different layer' => array($sheet('@layer x{.e{color:red}}', array('index.html')), $sheet('@layer y{.e{color:red}}', null)),
    'reversed context chain' => array($sheet('@supports (display:grid){@media screen{.e{color:red}}}', array('index.html')), $sheet('@media screen{@supports (display:grid){.e{color:red}}}', null)),
    'different outer context' => array($sheet('@supports (display:grid){@media screen{.e{color:red}}}', array('index.html')), $sheet('@supports (display:flex){@media screen{.e{color:red}}}', null)),
    'anonymous layers' => array($sheet('@layer{.e{color:red}}', array('index.html')), $sheet('@layer{.e{color:red}}', null)),
    'import dependent' => array($sheet('@import "relative.css";.e{color:red}', array('index.html')), $sheet('@import "relative.css";.e{color:red}', null)),
    'different target' => array($sheet('.e{color:red}', array('index.html'), 'after-author', 'frontend'), $sheet('.e{color:red}', null)),
    'separate smaller copies' => array($sheet('.e{color:red}', array('index.html', 'about.html')), $sheet('.e{color:red}', array('index.html')), $sheet('.e{color:red}', array('about.html'))),
    'different placement' => array($sheet('.e{color:red}', array('index.html'), 'before-author'), $sheet('.e{color:red}', null)),
    'editor only' => array($sheet('.e{color:red}', array('index.html'), 'after-author', 'editor'), $sheet('.e{color:red}', null, 'after-author', 'editor')),
    'location dependent' => array($sheet('.e{background:url(a.png)}', array('index.html')), $sheet('.e{background:url(a.png)}', null)),
    'earlier copy later' => array($sheet('.e{color:red}', null), $sheet('.e{color:red}', array('index.html'))),
);
foreach ($kept as $case => $input) {
    $output = $fold($input);
    foreach ($routes as $route) $assert(substr_count($loaded($input, $route), '.e{') === substr_count($loaded($output, $route), '.e{'), 'The earlier rule is kept (' . $case . '): ' . $route . ' ' . $contents($output));
}

// The same context and a superset of routes drop the earlier copy; a
// layer-order statement and an emptied layer block stay.
$output = $fold(array($sheet('@layer x,y;@media (min-width: 600px){.e{color:red}}@layer x{.f{color:red}}', array('index.html')), $sheet('@media (min-width:600px){.e{color:red}}@layer x{.f{color:red}}', null)));
$assert(1 === substr_count($contents($output), '.e{') && 1 === substr_count($contents($output), '.f{'), 'Copies in the same at-rule and layer chain are dropped: ' . $contents($output));
$assert(str_contains($contents($output), '@layer x,y;') && str_contains($contents($output), '@layer x{}'), 'Layer statements and emptied layer blocks stay: ' . $contents($output));
$output = $fold(array($sheet('.e{color:red}', array('index.html')), $sheet('.e{color:red}', array('index.html', 'about.html'))));
$assert(1 === substr_count($contents($output), '.e{'), 'A later copy on a superset of routes drops the earlier one: ' . $contents($output));
echo "Support shadowed duplicate rules passed\n";
