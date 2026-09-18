<?php
declare(strict_types=1);

/**
 * core/image save() writes the `width`/`height` attributes into the <img>
 * style verbatim (`style={{ width, height }}`), so a unitless `"800"` saves as
 * `width:800` while the stored markup says `width:800px`. Gutenberg then
 * reports "Expected attribute style" and marks the block invalid. A linked
 * image has to carry the same unit-bearing dimensions an unlinked one does.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

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

$cases = array(
    'linked image, HTML dimension attributes' => '<main><figure><a href="/servicios"><img src="https://example.test/a.jpg" width="800" height="600" alt="A"></a></figure></main>',
    'lightbox-linked image, HTML dimension attributes' => '<main><figure><a href="/media/full.jpg" class="fancybox"><img src="https://example.test/a.jpg" width="800" height="600" alt="A"></a></figure></main>',
    'unlinked image, HTML dimension attributes' => '<main><figure><img src="https://example.test/a.jpg" width="800" height="600" alt="A"></figure></main>',
);

foreach ( $cases as $label => $html ) {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $serialized = (string) ($result['serialized_blocks'] ?? '');
    $image = null;
    foreach ( $result['blocks'] ?? array() as $block ) {
        if ( 'core/image' === ($block['blockName'] ?? '') ) {
            $image = $block;
            break;
        }
    }
    $assert(is_array($image), $label . ': compiles to core/image', $serialized);
    if ( ! is_array($image) ) {
        continue;
    }

    $attrs = $image['attrs'] ?? array();
    foreach ( array( 'width', 'height' ) as $property ) {
        if ( ! array_key_exists($property, $attrs) ) {
            continue;
        }
        $value = (string) $attrs[$property];
        $assert(
            1 !== preg_match('/^(?:\d+|\d*\.\d+)$/', $value),
            $label . ': the ' . $property . ' attribute carries a CSS unit, because save() writes it into the style verbatim',
            $value
        );
        $assert(
            1 === preg_match('/<img[^>]*style="[^"]*\b' . $property . ':' . preg_quote($value, '/') . '(?:;|")/', (string) ($image['innerHTML'] ?? '')),
            $label . ': the saved <img> style states the same ' . $property . ' the attribute does',
            $value . ' vs ' . (string) ($image['innerHTML'] ?? '')
        );
    }

    $assert(
        'pass' === ( ( new BlockValidityValidator() )->validateBlocks($result['blocks'] ?? array())['status'] ?? '' ),
        $label . ': the block stays Gutenberg-valid',
        $serialized
    );
}

if ( 0 < $failures ) {
    fwrite(STDERR, "linked image dimension units FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "linked image dimension units passed: {$passes} assertions\n";
