<?php
declare(strict_types=1);

/** Only block sizes below the control's padding/border floor move to the link. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-padding-height.html');
$failures = 0;
foreach ( array('height:2.25rem', 'min-height:2.25rem', 'max-height:2.25rem', 'height:var(--control-height,2.25rem)') as $size ) {
    $result = (new HtmlTransformer())->transform(str_replace('height:2.25rem', $size, $fixture))->toArray();
    $css = implode('', array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $found = false;
    foreach ( explode('}', $css) as $rule ) {
        if ( str_contains($rule, $size) ) {
            $selector = strstr($rule, '{', true);
            if ( false === $selector || ! str_contains($selector, '.wp-block-button__link') ) {
                fwrite(STDERR, "FAIL: {$size} must not size padding-free wrappers: {$rule}\n");
                ++$failures;
            } else {
                $found = true;
            }
        }
    }
    if ( ! $found ) {
        fwrite(STDERR, "FAIL: {$size} must reach the padded link\n");
        ++$failures;
    }
}
// Sizes at/above the 48px floor and layout-dependent values retain trunk ownership.
foreach ( array( 'height:48px', 'min-height:48px', 'max-height:48px', 'height:80px', 'min-height:80px', 'max-height:80px', 'height:100%', 'height:auto', 'height:stretch', 'min-height:100%', 'max-height:100%', 'height:var(--missing)' ) as $size ) {
    $result = (new HtmlTransformer())->transform(str_replace('height:2.25rem', $size, $fixture))->toArray();
    $css = implode('', array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $found = false;
    foreach ( explode('}', $css) as $rule ) {
        if ( str_contains($rule, $size) && ! str_contains((string) strstr($rule, '{', true), '.wp-block-button__link') ) {
            $found = true;
        }
    }
    if ( ! $found ) {
        fwrite(STDERR, "FAIL: {$size} must keep wrapper ownership\n");
        ++$failures;
    }
}

// Floor evidence may come from a different utility rule or from borders alone.
foreach ( array(
    '<style>.size{height:36px}.pad{padding:24px}</style><button class="size pad">Order</button>',
    '<style>.cta{height:36px;padding:0;border:24px solid red}</style><button class="cta">Order</button>',
) as $html ) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode('', array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    if ( ! preg_match('/wp-block-button__link[^{}]*\\{[^{}]*height:36px/', $css) ) {
        fwrite(STDERR, "FAIL: separately authored padding/border floor must own height\n");
        ++$failures;
    }
}
if ( $failures > 0 ) {
    exit(1);
}
echo "Padded button height ownership: 18 cases passed\n";
