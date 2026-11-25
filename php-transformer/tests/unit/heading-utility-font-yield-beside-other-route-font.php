<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A Tailwind-style site (imb-culinary-craft): a heading-family utility on
// the headings of most routes, and on one route (the menu) a serif utility
// on other headings. The serif utility only renders on that route, so its
// rule is route-scoped and never meets `.font-heading` on any heading: it
// must not stop `.font-heading` from yielding its family on headings to the
// Global Styles heading element, or headings ignore heading restyles.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$css = ':root{--font-heading:"Plus Jakarta Sans",ui-sans-serif,system-ui,sans-serif;--font-body:"Inter",ui-sans-serif,system-ui,sans-serif}'
    . 'body{font-family:var(--font-body);color:#1c2b22}'
    . 'h1,h2,h3,h4,h5,h6{font-family:var(--font-heading)}'
    . '.font-heading{font-family:var(--font-heading)}'
    . '.font-serif{font-family:ui-serif,Georgia,Cambria,"Times New Roman",Times,serif}'
    . '.text-lg{font-size:18px;line-height:28px}'
    . '.flex{display:flex;gap:10px}';
$page = static fn(string $title, string $main): string => '<!doctype html><html><head><title>' . $title . '</title><style>' . $css . '</style></head><body>'
    . '<header><a href="/">IMB Catering</a></header><main>' . $main . '</main><footer><p>Bangi, Selangor</p></footer></body></html>';
$files = array(
    'index.html' => $page('Home', '<h1>Catering for every occasion</h1><p>Seasonal menus.</p>'
        . '<h3 class="font-heading text-lg">School catering</h3><p>Balanced lunches.</p>'
        . '<h3 class="font-heading text-lg">Event catering</h3><p>Weddings and launches.</p>'),
    'about.html' => $page('About', '<h2 class="font-heading">Our story</h2><p>Since 2010.</p>'
        . '<h3 class="font-heading text-lg">Fresh ingredients</h3><p>Sourced daily.</p>'),
    'menu.html' => $page('Menu', '<h1 class="font-serif">Our menu</h1><p class="font-serif">Taste the season.</p>'
        . '<h2 class="font-serif">Mains</h2>'
        . '<div class="flex"><span class="font-serif text-lg">Nasi lemak</span><span class="font-serif">RM 20.90</span></div>'
        . '<div class="flex"><span class="font-serif text-lg">Mee goreng</span><span class="font-serif">RM 12.50</span></div>'),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$theme = null;
foreach ($plan['writes'] as $write) {
    if ('theme.json' === ($write['target_path'] ?? '')) $theme = json_decode($write['payload']['data'], true);
}
$assert(is_array($theme), 'Compiled theme.json is present');
$assert('var:preset|font-family|heading' === ($theme['styles']['elements']['heading']['typography']['fontFamily'] ?? null), 'The heading element owns the heading family: ' . json_encode($theme['styles']['elements']['heading'] ?? null));

$styles = implode("\n", array_column($plan['assets'], 'content'));
$assert(1 === preg_match('/\.font-heading(?::not\((?:[^(){}]|\([^(){}]*\))*\))*:not\(:where\(h1,h2,h3,h4,h5,h6\)\)[^{]*\{font-family:/', $styles), 'The heading utility yields its family to the heading element on headings, though a serif utility styles other headings on another route: ' . implode("\n", array_filter(explode('}', $styles), static fn(string $rule): bool => str_contains($rule, 'font-heading') && str_contains($rule, 'font-family'))));
echo "Heading utility font yield beside other route font passed\n";
