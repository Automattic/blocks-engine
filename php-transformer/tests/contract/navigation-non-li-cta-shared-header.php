<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array('div', 'li') as $tag) {
    $files = array('theme.css' => '.menu>.menu-item.menu-cta{display:flex;align-items:center;margin:0 0 0 auto}');
    foreach (array('index', 'about', 'visit') as $route) {
        $files[$route . '.html'] = '<!doctype html><html><head><title>' . $route . '</title><link rel="stylesheet" href="theme.css"></head><body><header><nav><ul class="menu"><li class="menu-item"><a href="/">Home</a></li><' . $tag . ' class="menu-item menu-cta"><a href="/book/">Book now</a></' . $tag . '></ul></nav></header><main><p>Content for ' . $route . '</p></main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $assert(1 === count($headers), $tag . ': exactly one shared header');
    $header = $headers[0]['canonical_block_markup'];
    $assert(str_contains($header, 'wp:navigation '), $tag . ': shared header contains navigation');
    $assert(1 === count($plan['menus']), $tag . ': one shared menu');
    $menu = $plan['menus'][0]['block_markup'];
    $assert(str_contains($menu, '/book/') && str_contains($menu, 'Book now'), $tag . ': shared menu retains CTA destination and label');
    $assert(str_contains($menu, 'wp:navigation-link '), $tag . ': CTA lowers with native menu items');
    foreach ($plan['pages'] as $page) {
        $content = $page['canonical_block_markup'];
        $assert(!str_contains($content, '<header') && !str_contains($content, 'Book now') && !str_contains($content, 'wp:navigation'), $tag . ': page content excludes header/menu');
        $assert(str_contains($content, 'Content for '), $tag . ': page body survives');
        $template = WordPressSitePlan::renderedTemplates($plan)[$page['route_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page')] ?? '';
        $assert(str_contains($template . $content, 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), $tag . ': every route references header');
    }
    // Core renders the wrapper as an li: its projected item identity must still
    // own the authored flex/alignment/auto-margin rule, not the inner anchor.
    $css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
    preg_match('/blocks-engine-navigation-item-[a-z0-9-]+/', $menu, $identity);
    $assert(isset($identity[0]), $tag . ': CTA has projected item identity');
    $selector = '\\.wp-block-navigation-item:nth-child\\(2\\)';
    $assert((bool) preg_match('/' . $selector . '[^{}]*\{[^{}]*display:flex;align-items:center/', $css), $tag . ': flex layout targets rendered CTA item');
    $assert((bool) preg_match('/' . $selector . '[^{}]*\{[^{}]*margin:0 0 0 auto/', $css), $tag . ': auto margin targets rendered CTA item');
}
$ordinary = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><head><style>.extra{display:flex;align-items:center;margin:0 0 0 auto}</style></head><body><main><ul><li><a href="/">Reference</a></li><div class="extra"><a href="/book/">Book now</a></div></ul></main></body></html>')))->toWordPressSitePlanView()['wordpress_site_plan'];
$content = $ordinary['pages'][0]['canonical_block_markup'];
$assert(!str_contains($content, 'wp:navigation'), 'An ordinary main list is not promoted to navigation');
$assert(str_contains($content, 'Book now'), 'Ordinary styled list content survives');
echo "navigation-non-li-cta-shared-header: ok\n";
