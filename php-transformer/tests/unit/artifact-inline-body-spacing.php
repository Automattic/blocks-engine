<?php
declare(strict_types=1);

/**
 * Authors declare page spacing inline on <body>, commonly reserving room for a
 * fixed footer bar. Artifact compilation re-wraps each document in a bare
 * <body> before it reaches the transformer, so that spacing is gone by then
 * and every compiled page comes up short by exactly that amount. Custom
 * properties used for page palettes and backgrounds must survive the same path.
 *
 * This asserts through ArtifactCompiler rather than HtmlTransformer on
 * purpose: the two take different stylesheet paths, and a fix proven only on
 * the transformer does not reach a compiled site.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

/** @return array{0: string, 1: int} Emitted stylesheet CSS and asset count. */
$compile = static function (string $bodyAttributes, string $head = '<style>.x{color:red}</style>'): array {
    $html = '<html><head>' . $head . '</head><body ' . $bodyAttributes . '><main><h1>Example heading</h1><p>Copy</p></main></body></html>';
    $result = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'website/index.html',
        'files' => array( 'website/index.html' => $html ),
    ))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $css = '';
    foreach ( $plan['assets'] ?? array() as $asset ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }

    return array( $css, count($plan['assets'] ?? array()) );
};

$hasBodyRule = static fn (string $css): bool => 1 === preg_match('/(?:^|[^-\w])body\s*\{/', $css);

// The captured Weebly body: a footer reserve alongside document mechanics.
list( $weebly, $weeblyAssets ) = $compile('style="min-height:100%;position:relative;height:auto !important;padding-bottom:62px !important"');
$assert(
    str_contains($weebly, 'padding-bottom:62px'),
    'the inline footer reserve reaches a compiled site',
    $weebly
);
$assert(
    ! str_contains($weebly, 'position:relative'),
    'document positioning is left to WordPress',
    $weebly
);
$assert(
    1 !== preg_match('/body\s*\{[^}]*height\s*:/', $weebly),
    'document sizing is left to WordPress, so the editor canvas still scrolls',
    $weebly
);

// Riding on the existing stylesheet keeps the file count a caller budgeted for.
list( , $plainAssets ) = $compile('class="plain"');
$assert(
    $weeblyAssets === $plainAssets,
    'carrying the spacing does not add a stylesheet to the compiled artifact',
    'with=' . $weeblyAssets . ' without=' . $plainAssets
);

// A body with no inline spacing introduces nothing.
list( $plain, ) = $compile('class="plain"');
$assert( ! $hasBodyRule($plain), 'a body without inline spacing introduces no rule', $plain );

// An inline style of only document mechanics introduces nothing.
list( $mechanics, ) = $compile('style="position:relative;overflow:hidden"');
$assert( ! $hasBodyRule($mechanics), 'an inline style of only mechanics introduces no rule', $mechanics );

// A document with no inline stylesheet at all still carries the spacing.
list( $noStyleTag, ) = $compile('style="padding-bottom:62px"', '');
$assert(
    str_contains($noStyleTag, 'padding-bottom:62px'),
    'a document without any inline stylesheet still carries its body spacing',
    $noStyleTag
);

// A declaration cannot break out of its block.
list( $hostile, ) = $compile('style="padding-bottom:1px}body{outline:7px solid lime"');
$assert( ! str_contains($hostile, 'lime'), 'an inline style cannot inject an extra rule', $hostile );

// Body palette data survives alongside spacing, but never document mechanics.
foreach ( array(
    'palette and background' => array('--accent:#e6312e;--bg:url(/m/bg.png);position:fixed;margin:4px', array('--accent:#e6312e', '--bg:url(/m/bg.png)', 'margin:4px')),
    'top-level separators only' => array('--accent:#e6312e;--bg:url(&quot;/m/bg;v=1.png&quot;);--tokens:rgb(1, 2, 3) &quot;a;b&quot;;position:fixed;margin:4px', array('--accent:#e6312e', '--bg:url("/m/bg;v=1.png")', '--tokens:rgb(1, 2, 3) "a;b"', 'margin:4px')),
    'unsafe brace' => array('--accent:#e6312e;--bg:url(/m/bg.png);position:fixed;margin:4px;--unsafe:{', array()),
) as $label => list($style, $declarations) ) {
    list($css, ) = $compile('style="' . $style . '"', '<style>h1{color:var(--accent)}</style>');
    preg_match_all('/(?<![-\\w])body(?::not\\([^)]*\\))?\\s*\\{([^}]*)\\}/', $css, $bodyRules);
    $bodyCss = implode(';', $bodyRules[1]);
    foreach ( $declarations as $declaration ) {
        // A used palette color may be projected onto a WordPress preset,
        // retaining the authored color as its fallback.
        $carried = '--accent:#e6312e' === $declaration
            ? 1 === preg_match('/--accent:(?:#e6312e|var\(--wp--preset--color--[^,]+,#e6312e\))/', $bodyCss)
            : str_contains($bodyCss, $declaration);
        $assert($carried, $label . ': body carries ' . $declaration, $css);
    }
    $assert(! str_contains($css, 'position:fixed'), $label . ': positioning is dropped', $css);
    if ( array() === $declarations ) {
        $assert('' === $bodyCss, $label . ': unsafe style carries nothing', $css);
    }
}

if ( 0 < $failures ) {
    fwrite(STDERR, "artifact inline body spacing FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "artifact inline body spacing passed: {$passes} assertions\n";
