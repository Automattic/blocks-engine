<?php
declare(strict_types=1);

// Core's `.wp-block-group{box-sizing:border-box}` shrinks a content-box source
// box by its padding and border whenever any size constraint binds. A box sized
// by min/max width or height keeps the source content-box model, just as one
// sized by width or height does.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$assets = static function (string $html): array {
    $result = ( new ArtifactCompiler() )->compile(array( 'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => $html ),
    ) ) )->toArray();
    return $result['assets'] ?? array();
};
$css = static fn (string $html): string => implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $assets($html)));
// The restatement is support CSS: it lives in the after-author engine-support
// stylesheet, and the projected author rule no longer carries it.
$support = static fn (string $html): string => implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($assets($html), static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '') && 'after-author' === ($asset['stylesheet_placement'] ?? ''))
));
$body = '<div class="band"><p>Copy</p></div>';

foreach ( array( 'min-width:300px', 'max-width:900px', 'min-height:400px', 'max-height:600px' ) as $size ) {
    $out = $css('<style>.band{' . $size . ';padding:2rem}</style>' . $body);
    $assert(str_contains($out, '.band{' . $size . ';padding:2rem}'), "{$size} plus padding keeps the authored rule");
    $assert(str_contains($support('<style>.band{' . $size . ';padding:2rem}</style>' . $body), '.band{box-sizing:content-box}'), "{$size} plus padding restates content-box");

    $out = $css('<style>.band{' . $size . ';border:4px solid #333}</style>' . $body);
    $assert(str_contains($out, '.band{' . $size . ';border:4px solid #333}'), "{$size} plus border keeps the authored rule");
    $assert(str_contains($support('<style>.band{' . $size . ';border:4px solid #333}</style>' . $body), '.band{box-sizing:content-box}'), "{$size} plus border restates content-box");

    $out = $css('<style>.band{' . $size . ';margin:0 auto}</style>' . $body);
    $assert(! str_contains($out, 'box-sizing:content-box'), "{$size} without padding or border keeps the WordPress box model");

    $out = $css('<style>*,*::before,*::after{box-sizing:border-box}.band{' . $size . ';padding:2rem}</style>' . $body);
    $assert(! str_contains($out, 'box-sizing:content-box'), "{$size} under an authored border-box reset stays border-box");

    $out = $css('<style>.band{' . $size . ';padding:2rem;box-sizing:border-box}</style>' . $body);
    $assert(! str_contains($out, 'box-sizing:content-box'), "{$size} with an explicit box-sizing is left alone");
}

// Keyword constraints do not size the box.
$out = $css('<style>.band{max-width:none;min-height:auto;padding:2rem}</style>' . $body);
$assert(! str_contains($out, 'box-sizing:content-box'), 'keyword min/max values do not size the box');

// The size and the padding come from different rules.
$out = $support('<style>#strip{max-width:900px}.band{padding:40px}</style><div id="strip" class="band"><p>Copy</p></div>');
$assert(str_contains($out, '#strip{box-sizing:content-box}'), 'a max-width rule restates content-box when the cascade also pads the element');
$assert(str_contains($out, '.band{box-sizing:content-box}'), 'a padding rule restates content-box when the cascade also sets max-width');

// Links keep their own box model.
$out = $css('<style>.cta{max-width:200px;padding:1rem}</style><a class="cta" href="/go">Go</a>');
$assert(! str_contains($out, 'box-sizing:content-box'), 'links are not restated');

if ( $failures > 0 ) {
    exit(1);
}
echo "content-box min/max size: ok\n";
