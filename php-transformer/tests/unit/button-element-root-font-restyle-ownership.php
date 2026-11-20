<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A Tailwind-style source (imb-culinary-craft): the body sets the site
// typeface and every call-to-action link inherits it. The theme's button
// element must follow the Global Styles root typeface, not restate it as a
// preset that pins every button when the site typeface is restyled.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$css = ':root{--font-body:"Inter",ui-sans-serif,system-ui,sans-serif;--font-heading:"Plus Jakarta Sans",ui-sans-serif,system-ui,sans-serif}'
    . 'body{font-family:var(--font-body);font-size:16px;color:#15281f;background:#faf8f5}'
    . 'h1,h2{font-family:var(--font-heading)}'
    . '.btn{display:inline-flex;padding:10px 24px;border-radius:10px;font-size:14px;font-weight:500;background-color:#1c362b;color:#faf8f5;text-decoration:none}';
$files = array();
foreach (array('index.html', 'about.html', 'contact.html') as $route) {
    $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body><main>'
        . '<h1>Catering for every table</h1><p>Seasonal menus for schools, restaurants and events.</p>'
        . '<a class="btn" href="/contact.html">Book Now</a> <a class="btn" href="/about.html">Get Directions</a>'
        . '</main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$theme = null;
foreach ($plan['writes'] as $write) {
    if ('theme.json' === ($write['target_path'] ?? '')) $theme = json_decode($write['payload']['data'], true);
}
$assert(is_array($theme), 'Compiled theme.json is present');
$root = $theme['styles']['typography']['fontFamily'] ?? null;
$families = array_column($theme['settings']['typography']['fontFamilies'] ?? array(), 'fontFamily', 'slug');
$assert(is_string($root) && str_contains((string) ($families[str_replace('var:preset|font-family|', '', $root)] ?? ''), 'Inter'), 'The root typeface is the body preset: ' . json_encode($root));
$button = $theme['styles']['elements']['button'] ?? array();
$assert(isset($button['border']['radius']) || isset($button['spacing']['padding']) || isset($button['color']), 'The shared button look is projected to the button element: ' . json_encode($button));
$family = $button['typography']['fontFamily'] ?? 'inherit';
$assert('inherit' === $family, 'The button element inherits the root typeface instead of restating it: ' . json_encode($button['typography'] ?? null));
echo "Button element root font restyle ownership passed\n";
