<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A Bootstrap-agency-style source: buttons render the body typeface, but some
// sit in a footer set in the heading face. `inherit` on the button element
// would repaint those buttons in the footer's face, so the element keeps
// stating the root typeface it renders everywhere else.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$compile = static function (string $footerCss): array {
    $css = ':root{--font-body:"Roboto Slab",Georgia,serif;--font-heading:"Montserrat",ui-sans-serif,system-ui,sans-serif}'
        . 'body{font-family:var(--font-body);font-size:16px;color:#212529;background:#ffffff}'
        . 'h1,h2{font-family:var(--font-heading)}' . $footerCss
        . '.btn{display:inline-flex;padding:10px 24px;border-radius:10px;font-size:14px;font-weight:400;background-color:#212529;color:#ffffff;text-decoration:none}';
    $files = array();
    foreach (array('index.html', 'about.html', 'contact.html') as $route) {
        $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body><main>'
            . '<h1>We build brands</h1><p>Strategy, design and development.</p>'
            . '<a class="btn" href="/contact.html">Start a project</a> <a class="btn" href="/about.html">Meet the team</a>'
            . '</main><footer class="footer"><p>Copyright Agency</p><a class="btn" href="/about.html">Twitter</a></footer></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    foreach ($plan['writes'] as $write) {
        if ('theme.json' === ($write['target_path'] ?? '')) return json_decode($write['payload']['data'], true);
    }
    throw new RuntimeException('Compiled theme.json is present');
};

// Every button in root-family text: the element follows the root typeface.
$theme = $compile('.footer{text-align:center}');
$assert('inherit' === ($theme['styles']['elements']['button']['typography']['fontFamily'] ?? 'inherit'), 'Buttons in root-family text inherit the root typeface: ' . json_encode($theme['styles']['elements']['button']['typography'] ?? null));

// Footer buttons in the heading face: the element keeps the root preset.
$theme = $compile('.footer{text-align:center;font-family:var(--font-heading)}');
$root = $theme['styles']['typography']['fontFamily'] ?? null;
$families = array_column($theme['settings']['typography']['fontFamilies'] ?? array(), 'fontFamily', 'slug');
$assert(is_string($root) && str_contains((string) ($families[str_replace('var:preset|font-family|', '', $root)] ?? ''), 'Roboto Slab'), 'The root typeface is the body preset: ' . json_encode($root));
$family = $theme['styles']['elements']['button']['typography']['fontFamily'] ?? null;
$assert('inherit' !== $family, 'A button in a section of another face must not inherit it: ' . json_encode($theme['styles']['elements']['button'] ?? null));
echo "Button element contextual family pin passed\n";
