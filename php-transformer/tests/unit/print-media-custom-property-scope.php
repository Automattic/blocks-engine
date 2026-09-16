<?php
declare(strict_types=1);

/**
 * A design token redefined for another output medium is not the value the
 * rendered screen resolves. Conversion must keep the screen palette even when
 * a print `:root` block restates the same tokens later in the stylesheet.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$compile = static function (string $html, string $css): string {
    $result = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html, 'styles.css' => $css ),
    ))->toArray();
    $out = '';
    foreach ( $result['source_reports']['compiled_site']['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $out .= (string) ($asset['content'] ?? '');
        }
    }
    return $out;
};

$document = '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body><main>'
    . '<nav class="jumpnav" aria-label="Sections"><a href="#one">One</a><a href="#two">Two</a><a href="#three">Three</a></nav>'
    . '<section id="one"><h2>One</h2><p>First.</p></section>'
    . '<section id="two"><h2>Two</h2><p>Second.</p></section>'
    . '<section id="three"><h2>Three</h2><p>Third.</p></section>'
    . '</main></body></html>';

// The print block restates the token after the screen block.
$printOverride = $compile(
    $document,
    ':root{--muted:#6a6660}.jumpnav{display:flex}.jumpnav a{color:var(--muted);text-decoration:none}'
    . '@media print{:root{--muted:#444}}'
);
$assert(str_contains($printOverride, '#6a6660'), 'the screen token value reaches conversion');
$assert(! preg_match('/\.wp-block-navigation[^{}]*\{[^{}]*#444/', $printOverride), 'the print token value does not reach the rendered menu');

// A screen-scoped redefinition is still legitimate.
$screenOverride = $compile(
    $document,
    ':root{--muted:#6a6660}@media screen and (min-width:60rem){:root{--muted:#123456}}'
    . '.jumpnav{display:flex}.jumpnav a{color:var(--muted);text-decoration:none}'
);
$assert(str_contains($screenOverride, '#6a6660') || str_contains($screenOverride, '#123456'), 'a screen-scoped token redefinition still resolves');

// Print-only tokens that nothing else defines remain available.
$printOnly = $compile(
    $document,
    '.jumpnav{display:flex}.jumpnav a{color:var(--ink);text-decoration:none}@media print{:root{--ink:#000}}'
);
$assert('' !== $printOnly, 'a stylesheet whose tokens are only defined for print still compiles');

// The observed academic CV corpus case: --muted is #6a6660 on screen and #444
// in the print block.
$cvDirectory = dirname(__DIR__, 3) . '/fixtures/websites/31-personal-cv-academic';
$cv = $compile((string) file_get_contents($cvDirectory . '/index.html'), (string) file_get_contents($cvDirectory . '/styles.css'));
$navRules = '';
foreach ( explode('}', $cv) as $chunk ) {
    if ( str_contains($chunk, 'navigation') && str_contains($chunk, 'color') ) {
        $navRules .= $chunk . '}';
    }
}
$assert(! str_contains($navRules, '#444'), 'academic CV menu links keep the screen muted token');
$assert(str_contains($navRules, '#6a6660'), 'academic CV menu links resolve the screen muted token');

if ( 0 < $failures ) {
    fwrite(STDERR, "Print-media custom property scope tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Print-media custom property scope tests: {$passes} passed" . PHP_EOL);
