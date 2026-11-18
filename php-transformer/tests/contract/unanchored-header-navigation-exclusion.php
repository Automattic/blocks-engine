<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// An excluded navigation design is not evidence of a distinct header layout.
// The identical unanchored landmark must not turn that navigation into another
// template part merely because its copy differs from the shared menu.
$files = array();
foreach (array('index.html' => 'Home', 'about/index.html' => 'About', 'services/index.html' => 'Services') as $path => $title) {
    $links = '';
    foreach (array('services' => 'Services', 'about' => 'About', 'contact' => 'Contact') as $slug => $label) {
        $current = $title === $label;
        if ('Services' === $title && 'about' === $slug) $label = 'Our team';
        $links .= '<a ' . ($current ? 'aria-current="page" class="text-fg"' : 'class="text-muted hover:text-fg"') . ' href="/' . $slug . '/">' . $label . '</a>';
    }
    $files[$path] = '<html><head><title>' . $title . '</title><style>@layer utilities{.flex{display:flex}.gap-6{gap:1.5rem}.text-muted{color:#888}.text-fg{color:#111}.hover\\:text-fg:hover{color:#111}.p-4{padding:1rem}}</style></head><body><header class="p-4"><nav aria-label="Main"><div class="flex gap-6"><a href="/">Brand</a><div class="actions"><div class="hidden">' . $links . '</div><div class="tools"><button type="button" aria-label="Switch theme"></button></div></div></div></nav></header><main><h1>' . $title . '</h1></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null));
if (1 !== count($headers)) throw new RuntimeException('Navigation-only exclusions must not create route-local header parts.');
foreach ($plan['pages'] as $page) {
    $retained = str_contains($page['canonical_block_markup'], '<header');
    if ($retained !== ('services/index.html' === $page['source_path'])) throw new RuntimeException('Only the non-equivalent navigation remains route-owned.');
}
echo "unanchored-header-navigation-exclusion: passed\n";
