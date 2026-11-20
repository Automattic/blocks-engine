<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A Tailwind-style source (imb-culinary-craft): one global heading family on
// `h1..h6`, a heading-family utility used on headings, a preflight root font
// on `html,:host`, and a brand utility only ever on a span. Every heading
// must follow the Global Styles heading element: no heading level may restate
// its family, and the heading utility must yield on headings.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$css = ':root{--font-heading:"Plus Jakarta Sans",ui-sans-serif,system-ui,sans-serif;--font-body:"Inter",ui-sans-serif,system-ui,sans-serif;--font-logo:"Playfair Display",Georgia,serif}'
    . 'html,:host{font-family:ui-sans-serif,system-ui,sans-serif}'
    . 'body{font-family:var(--font-body);color:#1c2b22}'
    . 'h1,h2,h3,h4,h5,h6{font-family:var(--font-heading)}'
    . '.font-heading{font-family:var(--font-heading)}'
    . '.font-logo{font-family:var(--font-logo)}'
    . '.hero-display{font-size:56px;line-height:1.05;font-weight:800}'
    . '.eyebrow{font-size:12px;font-weight:600}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body>'
    . '<header><a href="/"><span class="font-logo">Culinary Craft</span></a></header>'
    . '<main><h1 class="hero-display">Catering for every table</h1>'
    . '<p>Seasonal menus for schools, restaurants and events.</p>'
    . '<h2 class="hero-display">Our services</h2>'
    . '<h3 class="font-heading">School catering</h3><p>Balanced lunches.</p>'
    . '<h3 class="font-heading">Event catering</h3><p>Weddings and launches.</p>'
    . '<h4 class="eyebrow">Contact</h4><p>hello@example.com</p></main>'
    . '<footer><span class="font-logo">Culinary Craft</span></footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$theme = null;
foreach ($plan['writes'] as $write) {
    if ('theme.json' === ($write['target_path'] ?? '')) $theme = json_decode($write['payload']['data'], true);
}
$assert(is_array($theme), 'Compiled theme.json is present');
$elements = $theme['styles']['elements'] ?? array();
$assert('var:preset|font-family|heading' === ($elements['heading']['typography']['fontFamily'] ?? null), 'The heading element owns the heading family: ' . json_encode($elements['heading'] ?? null));
foreach (array('h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $level) {
    $assert(!isset($elements[$level]['typography']['fontFamily']), $level . ' does not restate the heading element family, which would pin it against heading restyles: ' . json_encode($elements[$level] ?? null));
}
$assert('800' === ($elements['h1']['typography']['fontWeight'] ?? null), 'Level typography other than the family survives: ' . json_encode($elements['h1'] ?? null));

$styles = implode("\n", array_column($plan['assets'], 'content'));
$assert(1 === preg_match('/\.font-heading(?::not\([^{]*\))*:not\(:where\(h1,h2,h3,h4,h5,h6\)\)[^{]*\{font-family:/', $styles), 'The heading utility yields its family to the heading element on headings, despite root and brand-span font rules');
echo "Heading element font restyle ownership passed\n";
