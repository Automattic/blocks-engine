<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

foreach (array(
    'page' => array('page', null),
    'location' => array('location', null),
    'empty current value' => array('', null),
    'nested hidden' => array('page', null, 'hidden'),
    'nested responsive flex' => array('page', null, 'md:flex'),
    'nested hidden responsive flex' => array('page', null, 'hidden md:flex'),
    'nested neutral' => array('page', null, 'links'),
    'hidden tools' => array('page', null, 'hidden', true),
    'responsive flex tools' => array('page', null, 'md:flex', true),
    'hidden responsive flex tools' => array('page', null, 'hidden md:flex', true),
    'neutral tools' => array('page', null, 'links', true),
    'hidden tools and toggle' => array('page', null, 'hidden', true, true),
    'responsive flex tools and toggle' => array('page', null, 'md:flex', true, true),
    'hidden responsive flex tools and toggle' => array('page', null, 'hidden md:flex', true, true),
    'hidden tools different label' => array('page', 'label', 'hidden', true),
    'responsive flex tools different href' => array('page', 'href', 'md:flex', true),
    'nested hidden different label' => array('page', 'label', 'hidden'),
    'nested responsive flex different href' => array('page', 'href', 'md:flex'),
    'different label' => array('page', 'label'),
    'different href' => array('page', 'href'),
) as $case => $row) {
    [$currentValue, $difference] = $row;
    $containerClass = $row[2] ?? null;
    $files = array();
    foreach (array('index.html' => 'Home', 'about/index.html' => 'About', 'services/index.html' => 'Services') as $path => $title) {
        $links = '';
        $items = array('services' => 'Services', 'about' => 'About');
        if (null !== $containerClass) $items['contact'] = 'Contact';
        foreach ($items as $slug => $label) {
            $current = $title === $label;
            $href = '/' . $slug . '/';
            if ('Services' === $title && 'about' === $slug) {
                if ('label' === $difference) $label = 'Our team';
                if ('href' === $difference) $href = '/team/';
            }
            $links .= '<a ' . ($current ? 'aria-current="' . $currentValue . '" class="text-fg"' : 'class="text-muted hover:text-fg"') . ' href="' . $href . '">' . $label . '</a>';
        }
        $links = null === $containerClass ? $links : '<div class="' . $containerClass . '">' . $links . '</div>';
        if ($row[3] ?? false) $links .= '<div class="tools"><button type="button" aria-label="Switch theme"></button></div>';
        if ($row[4] ?? false) $links .= '<button class="md:hidden" type="button" aria-label="Open menu"></button>';
        $files[$path] = '<!DOCTYPE html><html><head><title>' . $title . '</title><style>@layer utilities{.flex{display:flex}.gap-6{gap:1.5rem}.text-muted{color:#888}.text-fg{color:#111}.hover\\:text-fg:hover{color:#111}.p-4{padding:1rem}}</style></head><body><header class="p-4"><nav aria-label="Main"><div class="flex gap-6"><a href="/">Brand</a><div class="' . (null === $containerClass ? 'flex gap-6' : 'actions') . '">' . $links . '</div></div></nav></header><main><h1>' . $title . '</h1><p>Welcome to ' . $title . '.</p></main><footer class="p-4"><p>© Brand</p></footer></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), "$case: one shared header part");
    $templates = ThemeTemplates::rendered($plan);
    foreach ($plan['pages'] as $page) {
        $shared = null === $difference || 'services/index.html' !== $page['source_path'];
        $template = $templates[!empty($page['entrypoint']) ? 'front-page' : (isset($templates['page-' . $page['slug']]) ? 'page-' . $page['slug'] : 'page')];
        $assert($shared === str_contains($template, '"slug":"header"'), "$case: {$page['source_path']} header template membership");
        $assert($shared === !str_contains($page['canonical_block_markup'], 'wp:navigation'), "$case: {$page['source_path']} inline header membership");
        if ($shared) $assert(!str_contains($page['canonical_block_markup'], '<header'), "$case: no page header copy");
    }
    $assert(1 === preg_match('/<!-- wp:navigation \{[^}]*"className":"[^"]*(blocks-engine-navigation-instance-[a-f0-9]+-\d+)/', $headers[0]['canonical_block_markup'], $marker), "$case: runtime navigation current-colour marker");
    $css = implode("\n", array_column($plan['assets'], 'content'));
    $assert(1 === preg_match('/\.' . $marker[1] . '[^{]*\.current-menu-item[^{]*\{color:[^}]*#111/', $css), "$case: projected current-link colour survives");
}
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/nested-current-header-routes.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (array('with skip link' => true, 'without skip link' => false) as $case => $withSkipLink) {
    $files = $withSkipLink ? $fixture : array_map(static fn(string $html): string => str_replace('<a href="#main" class="skip-link">Skip to content</a>', '', $html), $fixture);
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), "$case: one shared fixture header");
    $templates = ThemeTemplates::rendered($plan);
    $assert(6 === count($plan['pages']), "$case: all fixture routes compiled");
    foreach ($plan['pages'] as $page) {
        $template = $templates[$page['route_template'] ?? (!empty($page['entrypoint']) ? 'front-page' : 'page')];
        $assert(str_contains($template, '"slug":"header"'), "$case: {$page['source_path']} shares header");
        $assert(0 === substr_count($page['canonical_block_markup'], '<!-- wp:navigation '), "$case: {$page['source_path']} has no inline navigation");
        $assert(!str_contains($page['canonical_block_markup'], '<header'), "$case: no inline fixture header");
    }
    $assert(!array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)), "$case: header tools are not a footer");
    $assert(str_contains($headers[0]['canonical_block_markup'], 'Switch to light mode'), "$case: tools stay in header");
}
echo "ok\n";
