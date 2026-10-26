<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A media query made only of media types (`screen`, `all`) holds at every
// screen width, so its rules shape structure exactly like unconditional ones.
// Legacy themes load every sheet with `<link media="screen">`; reading those
// rules as viewport-conditional hid a wrapper's margins, so its text became a
// source-box paragraph whose links inherited the wrapper's own negative margin.
$css = '#languages{position:absolute;width:200px;margin-left:-155px;margin-top:16px}';
$page = static fn (string $head): string => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Home</title>' . $head . '</head><body>'
    . '<header><div class="container"><div style="float:right"><div id="languages"><a href="/">Français</a>&nbsp;|&nbsp;<a href="/en/">English</a></div></div>'
    . '<div class="navbar"><h1><a href="/"><img src="/logo.png" alt="Studio"></a></h1></div></div></header><main><p>Hello.</p></main></body></html>';

$languagesParagraph = static function (string $head, array $sheets) use ($page): string {
    $files = array( array( 'path' => 'index.html', 'kind' => 'html', 'content' => $page($head) ) );
    foreach ( $sheets as $path => $content ) {
        $files[] = array( 'path' => $path, 'kind' => 'css', 'content' => $content );
    }
    $result = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'index.html', 'files' => $files ))->toArray();
    $found = '';
    array_walk_recursive($result, static function ($value) use (&$found): void {
        if ('' === $found && is_string($value) && 1 === preg_match('/<p id="languages" class="([^"]*)"/', $value, $match)) {
            $found = $match[1];
        }
    });
    return $found;
};
$failures = 0;
foreach ( array(
    'unconditional <style>' => array( '<style>' . $css . '</style>', array() ),
    '@media screen' => array( '<style>@media screen{' . $css . '}</style>', array() ),
    '@media only screen, print' => array( '<style>@media only screen, print{' . $css . '}</style>', array() ),
    '<link media="screen">' => array( '<link rel="stylesheet" href="style.css" media="screen">', array( 'style.css' => $css ) ),
) as $label => [$head, $sheets] ) {
    $classes = $languagesParagraph($head, $sheets);
    if (! str_contains($classes, 'blocks-engine-lowered-paragraph') || str_contains($classes, 'blocks-engine-source-box-paragraph')) {
        ++$failures;
        fwrite(STDERR, "FAIL: $label: the margin wrapper should lower to its own paragraph, got class=\"$classes\"\n");
    }
}

// A width feature is still a viewport condition.
$classes = $languagesParagraph('<style>@media (min-width: 2000px){' . $css . '}</style>', array());
if (str_contains($classes, 'blocks-engine-lowered-paragraph')) {
    ++$failures;
    fwrite(STDERR, "FAIL: a min-width query must stay conditional, got class=\"$classes\"\n");
}

if ($failures > 0) {
    fwrite(STDERR, "media-type-only rules: $failures failed\n");
    exit(1);
}
echo "Media-type-only @media rules shape structure like unconditional rules\n";
