<?php
declare(strict_types=1);

/**
 * A collapsible menu without a captured panel paints its opened overlay from
 * the menu's link colour. The home route's first link is its own current link
 * (a class swap: `text-primary` instead of `text-foreground/70`), so that
 * colour must come from a resting link: otherwise the home header gets an
 * overlay paint of its own, compares differently from every other route's
 * header, and the front page keeps its header inline instead of sharing the
 * site header part.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$links = array('/index.html' => 'Home', '/about/index.html' => 'About Us', '/menu/index.html' => 'Menu', '/contact/index.html' => 'Contact Us');
$page = static function (string $url, string $title) use ($links): string {
    $nav = '';
    foreach ($links as $href => $label) {
        $nav .= '<a class="text-sm font-medium hover:text-primary ' . ($href === $url ? 'text-primary' : 'text-foreground/70') . '" href="' . $href . '">' . $label . '</a>';
    }
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>'
        . '<header class="sticky top-0 bg-background"><div class="container flex items-center justify-between">'
        . '<a class="brand" href="/index.html"><span class="font-heading text-primary">Catering</span></a>'
        . '<nav class="hidden items-center gap-8 lg:flex">' . $nav . '</nav>'
        . '<div class="flex items-center gap-3"><a class="book bg-primary" href="/contact/index.html">Book Now</a>'
        . '<button aria-label="Open menu" class="flex h-10 w-10 items-center justify-center text-primary lg:hidden"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="4" x2="20" y1="12" y2="12"></line></svg></button>'
        . '</div></div></header>'
        . '<main><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main>'
        . '<footer class="bg-primary"><p>© 2026 Catering</p></footer></body></html>';
};

$files = array(
    'index.html' => $page('/index.html', 'Delicious food for every occasion'),
    'about/index.html' => $page('/about/index.html', 'About us'),
    'menu/index.html' => $page('/menu/index.html', 'Our menu'),
    'contact/index.html' => $page('/contact/index.html', 'Contact us'),
    'style.css' => '.hidden{display:none}.flex{display:flex}.items-center{align-items:center}.justify-between{justify-content:space-between}.gap-8{gap:2rem}.gap-3{gap:.75rem}'
        . '.bg-background{background-color:#faf8f5}.bg-primary{background-color:#1c362b;color:#faf8f5}.text-primary{color:#1c362b}.text-foreground\/70{color:rgba(21,40,31,.7)}'
        . '.text-sm{font-size:.875rem}.font-medium{font-weight:500}.h-10{height:2.5rem}.w-10{width:2.5rem}.sticky{position:sticky}.top-0{top:0}'
        . '@media (min-width:1024px){.lg\:flex{display:flex}.lg\:hidden{display:none}}',
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Every route shares one header part, got: ' . implode(', ', array_column($headers, 'slug')));
foreach ($plan['templates'] as $template) {
    $assert(str_contains($template['canonical_block_markup'], '"slug":"header"'), "The {$template['slug']} template uses the shared header.");
}
foreach ($plan['pages'] as $routePage) {
    $assert(!str_contains($routePage['canonical_block_markup'], 'wp:navigation'), "{$routePage['source_path']} keeps no inline header.");
}
echo "Navigation overlay paint with a current first link shares the header: passed\n";
