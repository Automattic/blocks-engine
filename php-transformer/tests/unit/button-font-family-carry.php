<?php
declare(strict_types=1);

/**
 * Contract for an authored font-family on a button-mapped rule.
 *
 * core/button registers a `fontFamily` attribute (see
 * packages/blocks-engine/src/core-block-attrs.json, generated from
 * @wordpress/block-library), which core only injects when the typography
 * fontFamily support is enabled. A raw authored value is not a preset slug, so
 * it belongs in `style.typography.fontFamily` alongside the other carried
 * typography declarations.
 *
 * Dropping it is not benign: the authored `.btn` class is consumed into block
 * attributes, so the surviving author rule matches nothing at a specificity that
 * can win, and theme.json's styles.elements.button.typography.fontFamily
 * substitutes the design-direction typeface.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonStyleResolver;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

// -- G11: the support this fix relies on must actually exist.
$blockAttrs = json_decode(
    (string) file_get_contents(dirname(__DIR__, 3) . '/packages/blocks-engine/src/core-block-attrs.json'),
    true
);
$buttonAttributes = is_array($blockAttrs['blocks']['core/button'] ?? null) ? $blockAttrs['blocks']['core/button'] : array();

$assert(
    in_array('fontFamily', $buttonAttributes, true),
    'core/button registers a fontFamily attribute, so the support is real',
    implode(',', $buttonAttributes)
);

// -- G9 + G10 at the resolver: font-family joins the carried set, and every
// other carried declaration keeps mapping byte-for-byte as it does today.
$authoredButtonCss = 'background:#135e96;color:#ffffff;border:2px solid #0b3d6b;border-radius:6px;'
    . 'font-family:"Trebuchet MS", "Segoe UI", sans-serif;font-size:0.88rem;font-weight:700;'
    . 'letter-spacing:0.08em;text-transform:uppercase;padding:10px 16px';

$resolved = ( new ButtonStyleResolver() )->nativeAttributes($authoredButtonCss);
$expected = array(
    'style' => array(
        'color' => array( 'background' => '#135e96', 'text' => '#ffffff' ),
        'border' => array( 'width' => '2px', 'style' => 'solid', 'color' => '#0b3d6b', 'radius' => '6px' ),
        'spacing' => array( 'padding' => array( 'top' => '10px', 'right' => '16px', 'bottom' => '10px', 'left' => '16px' ) ),
        'typography' => array(
            'fontFamily' => '"Trebuchet MS", "Segoe UI", sans-serif',
            'fontSize' => '0.88rem',
            'fontWeight' => '700',
            'letterSpacing' => '0.08em',
            'textTransform' => 'uppercase',
        ),
    ),
);

$assert(
    '"Trebuchet MS", "Segoe UI", sans-serif' === (string) ($resolved['style']['typography']['fontFamily'] ?? ''),
    'resolver: the authored font-family reaches style.typography.fontFamily',
    json_encode($resolved['style']['typography'] ?? null)
);
$assert(
    json_encode($expected) === json_encode($resolved),
    'resolver: every carried declaration maps exactly, with font-family in canonical order',
    json_encode($resolved)
);

// -- A button whose rule authors no font-family gains none.
$withoutFamily = ( new ButtonStyleResolver() )->nativeAttributes('background:#135e96;color:#ffffff;font-size:0.88rem');

$assert(
    ! isset($withoutFamily['style']['typography']['fontFamily']),
    'resolver: no font-family is invented when the rule authors none',
    json_encode($withoutFamily)
);
$assert(
    array( 'fontSize' => '0.88rem' ) === ($withoutFamily['style']['typography'] ?? null),
    'resolver: the remaining typography subset is untouched',
    json_encode($withoutFamily['style']['typography'] ?? null)
);

// -- G9 end to end: the shape from the report, where `.btn` is consumed into
// block attributes and only the block attribute can still carry the typeface.
$result = ( new HtmlTransformer() )->transform(
    '<style>.btn{background:#135e96;color:#fff;border:2px solid #0b3d6b;border-radius:6px;'
    . 'font-family:"Trebuchet MS", "Segoe UI", sans-serif;font-size:0.88rem;font-weight:700;'
    . 'letter-spacing:0.08em;text-transform:uppercase;padding:10px 16px}</style>'
    . '<div class="hero-cta"><a class="btn" href="/a">Start</a><a class="btn" href="/b">Learn</a></div>',
    array()
)->toArray();
$button = $result['blocks'][0]['innerBlocks'][0] ?? array();
$buttonTypography = $button['attrs']['style']['typography'] ?? null;
$serialized = (string) ($result['serialized_blocks'] ?? '');

$assert(
    'core/button' === ($button['blockName'] ?? ''),
    'end to end: the authored anchor still becomes a core/button',
    (string) ($button['blockName'] ?? '(none)')
);
$assert(
    '"Trebuchet MS", "Segoe UI", sans-serif' === (string) ($buttonTypography['fontFamily'] ?? ''),
    'end to end: the block attributes carry the authored typeface',
    json_encode($buttonTypography)
);
$assert(
    str_contains($serialized, 'font-family:&quot;Trebuchet MS&quot;, &quot;Segoe UI&quot;, sans-serif')
        || str_contains($serialized, 'font-family:"Trebuchet MS", "Segoe UI", sans-serif'),
    'end to end: the rendered button link declares the authored typeface inline, where theme.json cannot outrank it',
    $serialized
);
$assert(
    array(
        'fontFamily' => '"Trebuchet MS", "Segoe UI", sans-serif',
        'fontSize' => '0.88rem',
        'fontWeight' => '700',
        'letterSpacing' => '0.08em',
        'textTransform' => 'uppercase',
    ) === $buttonTypography,
    'end to end: the other carried typography declarations are unchanged',
    json_encode($buttonTypography)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Button font-family carry contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Button font-family carry contract passed: {$passes} assertions\n");
