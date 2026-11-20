<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A hero sized only by (responsive) aspect-ratio must not keep core's 430px
// cover min-height, and `background-attachment:fixed` (stylesheet or
// !important inline) maps to core's hasParallax. A plain scrolling hero
// without a ratio keeps neither.

$cover = static function (string $css, string $inline = ''): array {
    $html = '<style>.hero{background-image:url(https://example.com/bg.png);background-size:cover;background-repeat:no-repeat}' . $css . '</style>'
        . '<section class="hero"' . ('' === $inline ? '' : ' style="' . $inline . '"') . '><h1>Get in touch</h1></section>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    foreach ( $result['blocks'] ?? array() as $block ) {
        if ( 'core/cover' === ($block['blockName'] ?? null) ) {
            return array( $block['attrs'] ?? array(), (string) json_encode($result, JSON_UNESCAPED_SLASHES) );
        }
    }
    throw new RuntimeException('Hero must convert to a cover.');
};

$sizedClass = 'blocks-engine-cover-source-sized';
$classes = static fn (array $attrs): array => preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array();

[ $sized, $output ] = $cover('.hero{aspect-ratio:1/1;background-attachment:fixed}@media (min-width:576px){.hero{aspect-ratio:3/2}}');
if ( true !== ($sized['hasParallax'] ?? null) ) {
    throw new RuntimeException('Stylesheet background-attachment:fixed must map to hasParallax.');
}
if ( ! in_array($sizedClass, $classes($sized), true) || ! str_contains($output, '.wp-block-cover.' . $sizedClass . '{min-height:0}') ) {
    throw new RuntimeException('An aspect-ratio-sized cover must reset core\'s default min-height.');
}

[ $important ] = $cover('.hero{min-height:300px}', 'background-attachment:fixed !important');
if ( true !== ($important['hasParallax'] ?? null) ) {
    throw new RuntimeException('Inline !important background-attachment:fixed must map to hasParallax.');
}
if ( in_array($sizedClass, $classes($important), true) ) {
    throw new RuntimeException('A hero with its own min-height must not get the min-height reset.');
}

[ $plain ] = $cover('.hero{min-height:300px}');
if ( isset($plain['hasParallax']) || in_array($sizedClass, $classes($plain), true) ) {
    throw new RuntimeException('A scrolling hero must not become parallax or lose its min-height.');
}

echo "Cover source sizing and parallax contract passed.\n";
