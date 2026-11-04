<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
foreach (array('', ';color:#333;text-decoration:none') as $titlePresentation) {
foreach (array('site-title', 'brand-x') as $titleClass) {
foreach (array(true, false) as $homeLinked) {
foreach (array(true, false) as $paragraph) {
$cell = "$titleClass$titlePresentation / home linked=" . (int) $homeLinked . " / paragraph=" . (int) $paragraph;
$files = array();
foreach ($routes as $current => $title) {
    $wordmark = 'Garden Studio';
    if ('index' !== $current || $homeLinked) $wordmark = '<a' . (!$paragraph && 'index' !== $current ? ' class="' . $titleClass . '"' : '') . ' href="index.html">' . $wordmark . '</a>';
    if ('index' === $current) $wordmark = '<h1 class="' . $titleClass . '">' . $wordmark . '</h1>';
    elseif ($paragraph) $wordmark = '<p class="' . $titleClass . '">' . $wordmark . '</p>';
    $links = '';
    foreach ($routes as $slug => $label) {
        $links .= '<li><a class="menu-link' . ($slug === $current ? ' is-active-menu-item' : '') . '" href="' . $slug . '.html">' . $label . '</a></li>';
    }
    $files[$current . '.html'] = '<!doctype html><html><head><title>' . $title . '</title><style>.' . $titleClass . '{font-size:24px;font-weight:700;margin:0' . $titlePresentation . '}.menu-link{color:#123456}.is-active-menu-item{color:#abcdef;text-decoration:underline}</style></head><body><header><div class="logo">' . $wordmark . '</div><nav><ul>' . $links . '</ul></nav></header><main><h2>' . $title . '</h2><p>Content for ' . $title . '</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), $cell . ': Title level and current item must yield one shared header.');
$header = $headers[0]['canonical_block_markup'];
$assert(str_contains($header, 'wp:navigation '), 'The shared header contains native navigation.');
$assert(1 === count($plan['menus']), 'The header uses one shared menu.');
$assert(str_contains($header, '{{wordpress-site-plan:navigation:' . $plan['menus'][0]['token'] . '}}'), 'The header references the shared menu contract.');
$assert(str_contains($header, '<h1') && str_contains($header, 'Garden Studio'), $cell . ': The shared title preserves the home heading.');
$assert((bool) preg_match('/<h1\b[^>]*>\s*<a\b/', $header) === $homeLinked, $cell . ': The selected home title keeps its original self-link state.');
$assert(!str_contains($plan['menus'][0]['block_markup'], 'is-active-menu-item'), 'The shared menu does not freeze the captured selection.');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert((bool) preg_match('/[^{}]*(?:aria-current|current-menu-item)[^{}]*\{[^{}]*#abcdef/', $css), 'Current-item colour follows Core runtime selection.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], '<header') && !str_contains($page['canonical_block_markup'], 'Garden Studio'), $cell . ': Page content does not repeat header markup.');
    $template = array_column($plan['templates'], 'canonical_block_markup', 'slug')[$page['route_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page')] ?? '';
    $assert(str_contains($template . $page['canonical_block_markup'], 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), $cell . ': Every route references the shared header.');
}
}
}
}
}
// A different wordmark is different chrome, not another title level.
$distinctFiles = $files;
$distinctFiles['index.html'] = str_replace('Garden Studio', 'Meadow Studio', $distinctFiles['index.html']);
$distinct = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $distinctFiles))->toWordPressSitePlanView()['wordpress_site_plan'];
$distinctHeaders = array_values(array_filter($distinct['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($distinctHeaders) && !str_contains($distinctHeaders[0]['canonical_block_markup'], 'Meadow Studio'), 'The unique wordmark must not merge or become a single-use part.');
foreach (array('color:#933', 'font-size:32px') as $difference) {
    $differentFiles = $files;
    $differentFiles['index.html'] = str_replace('</style>', 'h1.' . $titleClass . '{' . $difference . '}</style>', $differentFiles['index.html']);
    $different = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $differentFiles))->toWordPressSitePlanView()['wordpress_site_plan'];
    $differentHeaders = array_values(array_filter($different['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $assert(1 === count($differentHeaders) && !str_contains($differentHeaders[0]['canonical_block_markup'], '<h1'), 'Different title presentation must not merge: ' . $difference);
    $home = array_values(array_filter($different['pages'], static fn(array $page): bool => !empty($page['entrypoint'])))[0];
    $assert(str_contains($home['canonical_block_markup'], '<header'), 'Different home presentation stays in content: ' . $difference);
}
$section = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><body><main><article><header><h1>Article title</h1></header><p>Article body</p></article></main></body></html>')))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(str_contains($section['pages'][0]['canonical_block_markup'], '<header'), 'A single-page article header stays in content.');
$assert(array() === array_filter($section['template_parts'], static fn(array $part): bool => 'header' === $part['area']), 'An article header is not site chrome.');
echo "shared-header-title-level: ok\n";
