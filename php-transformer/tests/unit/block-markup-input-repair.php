<?php
declare(strict_types=1);

/**
 * Block markup with broken delimiters (#3559): a block left without its
 * closer, a block closed by a closer with another block's name, or an extra
 * closer after an element closer browsers ignore. Each closer is paired with
 * its block by the element nesting depth around it, so every block whose
 * delimiters are intact comes out as written and the broken ones come back as
 * blocks, each text once, with a diagnostic naming them. Well-formed markup
 * and delimiters stray inside HTML elements keep today's behaviour.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assertions = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL . ( '' === $detail ? '' : $detail . PHP_EOL ));
    }
};
$run = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$codes = static fn (array $result): array => array_column($result['diagnostics'], 'code');
$repairMessage = static function (array $result): ?string {
    foreach ( $result['diagnostics'] as $diagnostic ) {
        if ( 'block_markup_input_repaired_delimiters' === ( $diagnostic['code'] ?? '' ) ) {
            return (string) ( $diagnostic['message'] ?? '' );
        }
    }
    return null;
};

$cover = '<!-- wp:cover {"dimRatio":50,"isDark":true} --><div class="wp-block-cover is-dark"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading --><h2 class="wp-block-heading">Harbor nights</h2><!-- /wp:heading --></div></div><!-- /wp:cover -->';
$group = static fn (string $inner, string $closer = 'group'): string => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $inner . '</div><!-- /wp:' . $closer . ' -->';
$p = static fn (string $text, bool $closed = true): string => '<!-- wp:paragraph --><p>' . $text . '</p>' . ( $closed ? '<!-- /wp:paragraph -->' : '' );
$h = static fn (string $text, bool $closed = true): string => '<!-- wp:heading --><h2 class="wp-block-heading">' . $text . '</h2>' . ( $closed ? '<!-- /wp:heading -->' : '' );
$spacer = '<!-- wp:spacer {"height":"3rem"} --><div style="height:3rem" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->';

// name => [ input, the output it is repaired to, blocks named in the diagnostic ]
$repairs = array(
    'paragraph and heading closers missing in a group' => array(
        $cover . "\n\n" . $group($p('Boats leave hourly.', false) . $h('Night ferries', false)),
        $cover . "\n\n" . $group($p('Boats leave hourly.') . $h('Night ferries')),
        array( 'core/paragraph', 'core/heading' ),
    ),
    'paragraph closer missing in a group' => array(
        $cover . "\n\n" . $group($p('Boats leave hourly.', false) . $h('Night ferries')),
        $cover . "\n\n" . $group($p('Boats leave hourly.') . $h('Night ferries')),
        array( 'core/paragraph' ),
    ),
    'paragraph closer missing before nested blocks' => array(
        $group($p('Visit the workshop', false) . $h('Come see the wood move.') . $p('Coffee is usually on.')) . "\n\n" . $p('Hartfield, Ontario'),
        $group($p('Visit the workshop') . $h('Come see the wood move.') . $p('Coffee is usually on.')) . "\n\n" . $p('Hartfield, Ontario'),
        array( 'core/paragraph' ),
    ),
    'paragraph closer missing, group closed by a paragraph closer' => array(
        $group($p('Visit the workshop', false) . $h('Come see the wood move.') . $p('Coffee is usually on.'), 'paragraph') . "\n\n" . $spacer,
        $group($p('Visit the workshop') . $h('Come see the wood move.') . $p('Coffee is usually on.')) . "\n\n" . $spacer,
        array( 'core/paragraph', 'core/group' ),
    ),
    'extra group closer after an extra </div>' => array(
        $group($group($p('Workshop') . $p('Our Work'))) . '</div><!-- /wp:group -->' . "\n\n" . $p('Hartfield, Ontario'),
        $group($group($p('Workshop') . $p('Our Work'))) . "\n\n" . $p('Hartfield, Ontario'),
        array( 'core/group' ),
    ),
    'extra group closer with no extra </div>' => array(
        $group($group($p('Workshop') . $p('Our Work'))) . '<!-- /wp:group -->' . "\n\n" . $p('Hartfield, Ontario'),
        $group($group($p('Workshop') . $p('Our Work'))) . "\n\n" . $p('Hartfield, Ontario'),
        array( 'core/group' ),
    ),
    'extra paragraph closer directly in a group' => array(
        $group($p('Workshop') . '<!-- /wp:paragraph -->' . $p('Our Work')),
        $group($p('Workshop') . $p('Our Work')),
        array( 'core/paragraph' ),
    ),
    'paragraph closer missing at the end' => array(
        $cover . "\n\n" . $p('Tail note', false),
        $cover . "\n\n" . $p('Tail note'),
        array( 'core/paragraph' ),
    ),
    'all three shapes together' => array(
        $cover . "\n\n" . $group($p('One', false) . $h('Two'), 'paragraph') . "\n\n" . $group($group($p('Three', false))) . '</div><!-- /wp:group -->',
        $cover . "\n\n" . $group($p('One') . $h('Two')) . "\n\n" . $group($group($p('Three'))),
        array( 'core/paragraph', 'core/group' ),
    ),
);

foreach ( $repairs as $name => [ $input, $expected, $named ] ) {
    $r = $run($input);
    $assert($expected === $r['serialized_blocks'], $name . ': repaired to the markup it was meant to be', $r['serialized_blocks']);
    $assert(array() === array_intersect($codes($r), array( 'block_markup_input_unparsed', 'block_markup_input_stray_delimiters' )), $name . ': not converted as plain HTML', implode(', ', $codes($r)));
    $message = $repairMessage($r);
    $assert(null !== $message, $name . ': reports block_markup_input_repaired_delimiters', implode(', ', $codes($r)));
    foreach ( $named as $block ) {
        $assert(str_contains((string) $message, $block), $name . ': diagnostic names ' . $block, (string) $message);
    }
    $assert('success_with_warnings' === $r['status'], $name . ': status is success_with_warnings', $r['status']);
}

// Well-formed markup and the existing stray-delimiter cases are unchanged.
$wellFormed = array(
    $cover . "\n\n" . $group($p('Boats leave hourly.') . $h('Night ferries')),
    "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->",
    "<!-- a note -->\n\n<!-- wp:group -->\n<div class=\"wp-block-group\">\n  <!-- keep me -->\n  <!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n</div>\n<!-- /wp:group -->\n",
    '<!-- wp:group --><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --><!-- /wp:group -->',
);
foreach ( $wellFormed as $i => $input ) {
    $r = $run($input);
    $assert($input === $r['serialized_blocks'], 'well-formed markup ' . $i . ' comes out byte-identical', $r['serialized_blocks']);
    $assert(null === $repairMessage($r), 'well-formed markup ' . $i . ' reports no repair');
}

$stray = array(
    'delimiters paired by name inside elements' => array( "<!doctype html><html><body><main><h1>Title</h1><div class=\"text\"><p><!-- wp:paragraph --></p>\n<p>First.</p>\n<p><!-- /wp:paragraph --></p></div></main></body></html>", 'block_markup_input_stray_delimiters' ),
    'an unclosed opener inside an element' => array( '<div><p><!-- wp:paragraph --></p><p>Loose</p></div>', 'block_markup_input_unparsed' ),
    'an extra closer inside an element' => array( '<!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph --><div><p>x<!-- /wp:paragraph --></p></div>', null ),
);
foreach ( $stray as $name => [ $input, $code ] ) {
    $r = $run($input);
    $assert(null === $repairMessage($r), $name . ': no delimiters are repaired', (string) $repairMessage($r));
    if ( null !== $code ) {
        $assert(in_array($code, $codes($r), true), $name . ': keeps today\'s ' . $code, implode(', ', $codes($r)));
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, sprintf('%d of %d assertion(s) failed.%s', $failures, $assertions, PHP_EOL));
    exit(1);
}

echo sprintf('block-markup-input-repair: %d assertion(s) passed.%s', $assertions, PHP_EOL);
