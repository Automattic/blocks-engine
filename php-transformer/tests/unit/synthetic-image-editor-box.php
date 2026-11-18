<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

// Transparent front-end carriers still need a hit-testable block root in
// Gutenberg: selecting the child img alone does not select core/image.
$cases = array(
    SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS => array(
        '<main><div style="text-align:center"><img src="/photo.jpg" width="80" height="60" alt="Photo"></div></main>',
        'display:inline-block;position:static',
    ),
    SourceBlockAttributeProjector::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS => array(
        '<style>.icon{display:flex}</style><main><div class="icon"><img src="/photo.jpg" width="80" height="60" alt="Photo"></div></main>',
        'display:block',
    ),
);
foreach ( $cases as $class => list($html, $display) ) {
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? null) ) {
            $css .= $asset['content'] ?? '';
        }
    }
    $selector = ':root .editor-styles-wrapper figure.' . $class . '.wp-block-image';
    if ( ! str_contains($result['serialized_blocks'], $class)
        || ! str_contains($css, $selector . '{' . $display . '}')
        || ! preg_match('/[^{}]*' . preg_quote($class, '/') . '[^{}]*\{display:contents\}/', $css) ) {
        fwrite(STDERR, "FAIL: {$class} needs a selectable editor box and a transparent front-end carrier\n");
        exit(1);
    }
}
echo "synthetic image editor box passed\n";
