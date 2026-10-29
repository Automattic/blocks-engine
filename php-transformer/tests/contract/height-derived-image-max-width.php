<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Contract coverage for issue #3320.
 *
 * Core block-library CSS clamps every image with
 * `.wp-block-image img{max-width:100%}`. That clamp assumes the source clamps
 * too. When the source's computed max-width is the initial `none` -- no author
 * rule constrains the image -- and the width is derived, not carried (no
 * native width while the height is fixed), the browser sizes the width from
 * that fixed height through the aspect (or intrinsic) ratio exactly as the
 * source did, and the clamp is then the one declaration that breaks the box:
 * the width snaps to the container while the height stays fixed, distorting
 * or cropping the image (`height:82px;width:auto;aspect-ratio:82/82;
 * object-fit:cover` in a 69px container renders 69x82 instead of 82x82).
 *
 * The transform must restate `max-width:none` on that image through its
 * be-inline-geometry box carrier, while an image the source itself clamps --
 * a `width:100%` image, or one under an author `max-width:100%` -- keeps
 * core's responsive default untouched.
 */

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 3000)));
    }
};

$compile = static function (string $css, string $html): array {
    return (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><title>t</title><style>' . $css
                . '</style></head><body>' . $html . '</body></html>',
        ),
    ))->toArray();
};

/**
 * The be-inline-geometry box-constraint carrier rules the emitted image
 * blocks reference. Scoped to the carrier: the projected author stylesheet
 * legitimately keeps pre-existing `max-width:none` table-cell figure rules.
 *
 * @param array<string, mixed> $result
 */
$carrierRules = static function (array $result, string $markup): string {
    $css = '';
    foreach ( (array) ($result['assets'] ?? array()) as $asset ) {
        $css .= (string) ($asset['content'] ?? '');
    }
    $rules = '';
    $classes = array();
    preg_match_all('/be-inline-geometry-[0-9a-f]+/', $markup, $classes);
    foreach ( array_unique($classes[0] ?? array()) as $class ) {
        if ( 1 === preg_match('/\.' . $class . ' img\{[^}]*\}/', $css, $rule) ) {
            $rules .= $rule[0] . "\n";
        }
    }

    return $rules;
};

$imageBlock = static function (string $markup) use ($assert): array {
    $assert(
        1 === preg_match('/<!-- wp:image (\{.*?\}) -->(.*?)<!-- \/wp:image -->/s', $markup, $match),
        'the image is emitted as a core/image block.',
        $markup
    );
    $attrs = json_decode($match[1], true);
    $assert(is_array($attrs), 'the core/image block attributes decode.', $match[1]);

    return array( 'attrs' => $attrs, 'markup' => $match[2] );
};

// The issue fixture: the source renders the icon 82x82 inside a 69px-wide
// box (it overflows, `max-width` is the initial `none`); the width comes
// from the fixed 82px height through aspect-ratio:82/82.
$fixture = $compile(
    '.box{width:69px;height:82px}.box img{height:82px;width:auto;aspect-ratio:82/82;object-fit:cover;display:block}',
    '<main><div class="box"><img src="i.png" alt="icon"></div><p>Text</p></main>'
);
$markup = (string) ($fixture['serialized_blocks'] ?? '');
$assert('' !== $markup, 'fixture: the page compiles to serialized blocks.');
$block = $imageBlock($markup);
$assert(
    '82px' === (string) ($block['attrs']['height'] ?? ''),
    'fixture: the core/image keeps the fixed 82px height the width derives from.',
    $markup
);
$assert(
    1 === preg_match('/<img\b[^>]*\bstyle="[^"]*\bheight:\s*82px[^"]*"/', $block['markup']),
    'fixture: the emitted img carries the fixed 82px height.',
    $block['markup']
);
$assert(
    1 !== preg_match('/<img\b[^>]*\bstyle="[^"]*\bwidth:\s*\d+px/', $block['markup']),
    'fixture: the width stays derived (no pinned px width) as in the source.',
    $block['markup']
);
$rules = $carrierRules($fixture, $markup);
$assert('' !== $rules, 'fixture: the image carries a be-inline-geometry box rule.', $markup);
$assert(
    str_contains($rules, 'max-width:none') && !str_contains($rules, '!important'),
    'fixture: the box rule neutralizes core\'s max-width:100% clamp so the derived width renders 82x82.',
    $rules
);

// Control: a `width:100%` image the source itself clamps keeps core's
// responsive max-width:100% (nothing neutralizes it).
$control = $compile(
    '.box{width:69px;height:82px}.box img{height:82px;width:100%;object-fit:cover;display:block}',
    '<main><div class="box"><img src="i.png" alt="icon"></div><p>Text</p></main>'
);
$markup = (string) ($control['serialized_blocks'] ?? '');
$assert('' !== $markup, 'width:100% control: the page compiles to serialized blocks.');
$block = $imageBlock($markup);
$rules = $carrierRules($control, $markup);
$assert(
    1 !== preg_match('/max-width:none/', $rules),
    'width:100% control: core\'s responsive max-width:100% clamp is not neutralized.',
    $rules
);

// Control: an author `max-width:100%` on the image means the source clamps
// too, so core's clamp stays and the author's declaration is restated.
$authored = $compile(
    '.box{width:69px;height:82px}.box img{height:82px;width:auto;aspect-ratio:82/82;object-fit:cover;max-width:100%;display:block}',
    '<main><div class="box"><img src="i.png" alt="icon"></div><p>Text</p></main>'
);
$markup = (string) ($authored['serialized_blocks'] ?? '');
$assert('' !== $markup, 'author max-width control: the page compiles to serialized blocks.');
$block = $imageBlock($markup);
$rules = $carrierRules($authored, $markup);
$assert(
    !str_contains($rules, 'max-width:'),
    'author max-width control: the source clamp stays in its authored rule, not a carrier.',
    $rules
);
$assert(
    1 !== preg_match('/max-width:none/', $rules),
    'author max-width control: core\'s responsive max-width:100% clamp is not neutralized.',
    $rules
);

echo "height-derived image max-width ok\n";
