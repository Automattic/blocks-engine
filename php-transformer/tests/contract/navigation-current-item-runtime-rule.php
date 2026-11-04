<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$template = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navigation-current-item-runtime-rule/page.template.html');
// One menu; which item is current is route state. Page two marks a top-level
// item active, page three a dropdown child whose active rule adds a box.
$crumbs = '<section class="crumbs"><ol><li><a href="/">Home</a></li><li>Page</li></ol></section>';
$routes = array(
    'index.html' => array('{{title}}' => 'Home', '{{about}}' => '', '{{build}}' => '', '{{style}}' => '', '{{crumbs}}' => ''),
    'about/index.html' => array('{{title}}' => 'About', '{{about}}' => ' active', '{{build}}' => '', '{{style}}' => ' style=""', '{{crumbs}}' => $crumbs),
    'services/build/index.html' => array('{{title}}' => 'Build', '{{about}}' => '', '{{build}}' => ' active', '{{style}}' => '', '{{crumbs}}' => $crumbs),
);
$files = array_map(static fn(array $route): string => strtr($template, $route), $routes);
$compile = static fn(string $entrypoint, array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => $entrypoint, 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = static fn(array $plan): array => array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));

// Each route alone serializes the same core/navigation block, and no item
// carries the served route's current-item presentation.
$navigations = array();
foreach ($files as $path => $html) {
    $plan = $compile($path, array($path => $html));
    $header = $headers($plan);
    $assert(1 === count($header) && 1 === preg_match('/<!-- wp:navigation \{.*?\} \/-->/', $header[0]['canonical_block_markup'], $navigation), 'Route has a header navigation: ' . $path);
    $navigations[$path] = $navigation[0];
    $menu = (string) ($plan['menus'][0]['block_markup'] ?? '');
    $assert(!preg_match('/blocks-engine-navigation-(?:current-color|current-item|link-box)-/', $navigation[0] . $menu), 'Current-item presentation is not baked into item or navigation classes: ' . $path);
}
$assert(1 === count(array_unique($navigations)), 'The core/navigation markup is identical on every route: ' . json_encode($navigations));

// Together the routes share one header part and one menu.
$plan = $compile('index.html', $files);
$shared = $headers($plan);
$assert(1 === count($shared) && count($routes) === count($shared[0]['provenance']['sources'] ?? array()), 'All routes share one header part: ' . json_encode(array_map(static fn(array $part): array => array_keys($part['provenance']['sources'] ?? array()), $shared)));
$assert(1 === count($plan['menus']), 'All routes share one menu.');
preg_match('/blocks-engine-navigation-instance-[0-9a-f]{12}-\d+/', $shared[0]['canonical_block_markup'], $scope);
$assert(isset($scope[0]), 'The shared navigation carries its instance scope.');

// The current item's presentation is one rule per item level, keyed on what
// core renders for the runtime current item under that navigation's scope.
$css = '';
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'];
$rules = array();
foreach (preg_split('/(?<=\})/', $css) as $rule) if (preg_match('/^\s*([^{}@]+)\{([^{}]*)\}$/', $rule, $match)) $rules[] = array(trim($match[1]), $match[2]);
$specificity = static fn(string $selector): int => preg_match_all('/\.[\w-]+|\[[^\]]+\]|:(?!not\()[\w-]+/', $selector);
$rule = static function (string $level) use ($rules, $scope): array {
    foreach ($rules as [$selector, $declarations]) {
        if (str_contains($selector, '.' . $scope[0] . ' .wp-block-navigation__' . $level . '>.wp-block-navigation-item>.wp-block-navigation-item__content[aria-current="page"]')
            && str_contains($selector, '.wp-block-navigation__' . $level . '>.wp-block-navigation-item.current-menu-item>.wp-block-navigation-item__content')) return array($selector, $declarations);
    }
    return array('', '');
};
[$top, $topDeclarations] = $rule('container');
[$sub, $subDeclarations] = $rule('submenu-container');
$assert(str_contains($topDeclarations, '#cc0000'), 'A current top-level item renders the source active colour: ' . $topDeclarations);
$assert(str_contains($subDeclarations, '#cc0000') && str_contains($subDeclarations, 'background-color:') && str_contains($subDeclarations, '#ffeecc'), 'A current dropdown item renders the source active colour and background: ' . $subDeclarations);
$assert(str_contains($subDeclarations, 'padding-top:8px') && str_contains($subDeclarations, 'padding-right:16px'), 'Every box declaration of the source active rule moves to the runtime-keyed rule: ' . $subDeclarations);

// The current dropdown rule outranks the resting per-item rules on its
// anchor in the states they apply to, as the source's `.active` rule
// outranks its resting rule.
foreach ($rules as [$selector, $declarations]) {
    if (!str_contains($selector, '.wp-block-navigation-item__content') || !preg_match('/blocks-engine-navigation-link-(?:color|box)-/', $selector)) continue;
    foreach (explode(',', $selector) as $resting) {
        foreach (explode(',', $sub) as $alternative) {
            $assert($specificity($alternative) > $specificity($resting), 'Current dropdown rule ' . $alternative . ' outranks resting rule ' . $resting);
        }
    }
}
echo "ok\n";
