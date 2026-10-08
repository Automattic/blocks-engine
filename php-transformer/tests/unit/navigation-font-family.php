<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message) use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

// Core's legacy navigation migration recognizes style.typography.fontFamily,
// drops the modern ref, and selects a different fallback menu on editor load.
// Keep authored families in CSS without changing menu content or other type.
$fixtures = array(
    'inline list' => '<nav style="font-family:Georgia,serif;font-weight:600"><ul style="font-family:Georgia,serif;font-weight:600"><li><a href="/prints">Prints</a></li><li><a href="/about">About</a></li></ul></nav>',
    'variable direct links' => '<style>.menu{font-family:var(--menu-font);font-weight:600}</style><nav class="menu"><a href="/prints">Prints</a><a href="/about">About</a></nav>',
    'brand carrier' => '<nav style="font-family:Georgia,serif;font-weight:600"><a href="/" class="brand"><img src="logo.png" alt="Brand"></a><ul style="font-family:Georgia,serif;font-weight:600"><li><a href="/prints">Prints</a></li><li><a href="/about">About</a></li></ul></nav>',
);
foreach ( $fixtures as $name => $html ) {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    preg_match_all('/<!-- wp:navigation (\{.*?\}) -->/', $markup, $matches);
    $assert(1 === count($matches[1]), $name . ': emits one native menu');
    foreach ( $matches[1] as $json ) {
        $attrs = json_decode($json, true);
        $assert(! isset($attrs['style']['typography']['fontFamily']), $name . ': does not trigger the legacy font-family migration');
        $assert('600' === (string) ($attrs['style']['typography']['fontWeight'] ?? ''), $name . ': retains other supported typography');
        preg_match('/\bblocks-engine-navigation-font-family-[a-f0-9]+\b/', (string) ($attrs['className'] ?? ''), $marker);
        $family = 'variable direct links' === $name ? 'var(--menu-font)' : 'Georgia,serif';
        $assert(isset($marker[0]) && str_contains($css, '.wp-block-navigation.' . $marker[0] . '{font-family:' . $family . '}'), $name . ': delivers the authored family on the native host through scoped CSS');
    }
    $assert(str_contains($markup, '"label":"Prints"') && str_contains($markup, '"label":"About"'), $name . ': retains both menu labels');
}

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation font family FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
fwrite(STDOUT, "navigation font family passed: {$passes} assertions\n");
