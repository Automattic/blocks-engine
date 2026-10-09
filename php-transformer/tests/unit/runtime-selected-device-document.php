<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * A device document picked by a source head script keeps a live selection.
 *
 * The capture holds one document per device and a head script that writes
 * `data-dla-selected-document` on <html> in the visitor's browser. The default
 * rule `html:not([data-dla-selected-document]) [data-dla-device-document="desktop"]`
 * shows the desktop document only until that script runs. The captured root
 * never has the attribute, so a state marker frozen from it keeps the desktop
 * document visible next to the selected phone document. The rule must stay a
 * live attribute test on output that still carries those attributes.
 */

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$visibility = '[data-dla-device-document]{display:none!important}'
    . 'html:not([data-dla-selected-document]) [data-dla-device-document="desktop"]{display:contents!important}'
    . 'html[data-dla-selected-document="desktop"] [data-dla-device-document="desktop"]{display:contents!important}'
    . 'html[data-dla-selected-document="mobile"] [data-dla-device-document="mobile"]{display:contents!important}';
$selector = "(function(){var key=/iPhone|Android.*Mobile/i.test(navigator.userAgent)?'mobile':'desktop';"
    . "document.documentElement.setAttribute('data-dla-selected-document',key);})();";
$page = static fn (string $script): string => '<!doctype html><html lang="en"><head><title>Devices</title>'
    . '<style data-dla-device-visibility="">' . $visibility . '</style>' . $script . '</head><body>'
    . '<div class="data-liberation-desktop-document" data-dla-device-document="desktop" data-dla-document-scope=""><main><h1>Desktop welcome</h1><p>Wide layout copy.</p></main></div>'
    . '<div class="data-liberation-mobile-document" data-dla-device-document="mobile" data-dla-document-scope=""><main><h1>Phone welcome</h1><p>Narrow layout copy.</p></main></div>'
    . '</body></html>';
$compile = static function (string $html): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $css = implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_filter($result['assets'] ?? array(), static fn ($asset): bool => is_array($asset) && 'css' === ($asset['kind'] ?? ''))
    ));
    return array($css, (string) ($result['serialized_blocks'] ?? ''));
};
$desktopRules = static function (string $css): string {
    preg_match_all('/[^{}]*\{display:contents!important\}/', $css, $matches);
    return implode(' ', array_map('trim', $matches[0]));
};

[$css, $blocks] = $compile($page('<script data-dla-device-selection="">' . $selector . '</script>'));
$assert(
    str_contains($css, 'html:not([data-dla-selected-document]) [data-dla-device-document="desktop"]{display:contents!important}'),
    'The desktop default stays a live test of the attribute the head script writes. Got: ' . $desktopRules($css)
);
$assert(!str_contains($css, 'html:not(.blocks-engine-attribute-state-'), 'No captured-state marker stands in for the script-written root attribute.');
$assert(
    str_contains($css, 'html[data-dla-selected-document="mobile"] [data-dla-device-document="mobile"]{display:contents!important}'),
    'The phone document is still shown for the phone selection.'
);
$assert(
    1 === preg_match('/<div[^>]*data-dla-device-document="desktop"/', $blocks) && 1 === preg_match('/<div[^>]*data-dla-device-document="mobile"/', $blocks),
    'Both device documents keep the attribute the live selectors match.'
);
$assert(str_contains($blocks, 'Desktop welcome') && str_contains($blocks, 'Phone welcome'), 'Both device documents stay editable content.');

// Pages sharing a header compile it once as a site-wide shell. That synthetic
// document carries the head (and the script) but no device document roots, so
// the rule has no subject there; it must still not become a frozen marker.
$sitePage = static fn (string $title): string => str_replace(
    array('<title>Devices</title>', '<main><h1>Desktop welcome</h1>', '<main><h1>Phone welcome</h1>'),
    array('<title>' . $title . '</title>', '<header class="site-header"><p>Oak Hills HOA</p></header><main><h1>Desktop ' . $title . '</h1>', '<header class="site-header"><p>Oak Hills HOA</p></header><main><h1>Phone ' . $title . '</h1>'),
    $page('<script data-dla-device-selection="">' . $selector . '</script>')
);
$site = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $sitePage('Home'),
    'about/index.html' => $sitePage('About'),
)))->toArray();
$siteCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($site['assets'] ?? array(), static fn ($asset): bool => is_array($asset) && 'css' === ($asset['kind'] ?? ''))
));
$assert(str_contains($siteCss, 'html:not([data-dla-selected-document]) [data-dla-device-document="desktop"]'), 'Multi-page site keeps the live desktop default.');
$assert(!str_contains($siteCss, 'html:not(.blocks-engine-attribute-state-'), 'No page or shared shell projection freezes the script-written root attribute. Got: ' . $desktopRules($siteCss));

// Control: without a script that writes the root attribute, the captured state
// is the only state, and the existing projection is unchanged.
[$staticCss] = $compile($page(''));
$assert(
    !str_contains($staticCss, 'html:not([data-dla-selected-document]) [data-dla-device-document="desktop"]'),
    'Without a runtime writer the root predicate keeps its captured-state projection.'
);

echo "runtime-selected-device-document: ok\n";
