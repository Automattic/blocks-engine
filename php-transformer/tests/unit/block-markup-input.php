<?php
declare(strict_types=1);

/**
 * Block markup is first-class transform() input (#3478): blocks already in the
 * input come out exactly as written; only HTML outside any block, or loose HTML
 * inside a container block, is converted and spliced in at its position.
 * Nothing is dropped silently.
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
$run = static fn (string $html, array $options = array()): array => ( new HtmlTransformer() )->transform($html, $options)->toArray();
$names = static function (array $blocks) use (&$names): array {
    $out = array();
    foreach ( $blocks as $block ) {
        if ( ! empty($block['blockName']) ) {
            $out[] = $block['blockName'];
        }
        array_push($out, ...$names($block['innerBlocks'] ?? array()));
    }
    return $out;
};
// innerContent placeholders, innerBlocks and innerHTML agree on every block.
$consistent = static function (array $blocks) use (&$consistent): bool {
    foreach ( $blocks as $block ) {
        $content = $block['innerContent'] ?? array();
        $html = implode('', array_filter($content, 'is_string'));
        if ( count(array_filter($content, 'is_null')) !== count($block['innerBlocks'] ?? array()) || $html !== ( $block['innerHTML'] ?? '' ) ) {
            return false;
        }
        if ( ! $consistent($block['innerBlocks'] ?? array()) ) {
            return false;
        }
    }
    return true;
};
$inOrder = static function (string $haystack, string ...$needles): bool {
    $offset = -1;
    foreach ( $needles as $needle ) {
        $at = strpos($haystack, $needle, $offset + 1);
        if ( false === $at ) {
            return false;
        }
        $offset = $at;
    }
    return true;
};
$diagnosticCodes = static fn (array $result): array => array_column($result['diagnostics'], 'code');

$repro = "<!-- wp:template-part {\"slug\":\"header\"} /-->\n<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\"><!-- wp:post-title /-->\n<div class=\"card\"><h3>Rope toy</h3><p>Braided cotton.</p></div>\n<!-- wp:post-content /--></main>\n<!-- /wp:group -->";
$valid = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";
$heroCss = '.hero{display:flex;gap:2rem;align-items:center}.hero h2{font-size:3rem}';
$hero = '<div class="hero"><h2>Hi</h2><p>There</p></div>';
$assetPaths = static fn (array $result): array => array_map(static fn (array $asset): string => (string) ( $asset['path'] ?? '' ), $result['assets']);

$cases = array(
    'issue repro: dynamic blocks and the main wrapper survive, the card is converted inside the group' => array(
        'input' => $repro,
        'check' => static function (array $r) use ($assert, $names, $inOrder, $consistent): void {
            $s = $r['serialized_blocks'];
            $assert(str_contains($s, '<!-- wp:template-part {"slug":"header"} /-->'), 'template-part is kept', $s);
            $assert(str_contains($s, "<!-- wp:post-title /-->\n"), 'post-title is kept', $s);
            $assert(str_contains($s, "\n<!-- wp:post-content /--></main>\n<!-- /wp:group -->"), 'post-content and the closing wrapper are kept', $s);
            $assert(str_starts_with($s, "<!-- wp:template-part {\"slug\":\"header\"} /-->\n<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\"><!-- wp:post-title /-->"), 'everything before the card is byte-identical', $s);
            $assert($inOrder($s, 'wp:post-title', '<!-- wp:group {"className":"card"}', '<!-- wp:heading {"level":3}', 'Rope toy', '<!-- wp:paragraph', 'Braided cotton.', 'wp:post-content', '</main>'), 'the card becomes group + heading + paragraph between post-title and post-content', $s);
            $assert(! str_contains($s, '<div class="card">'), 'no freeform card HTML is left', $s);
            $assert(array( 'core/template-part', 'core/group', 'core/post-title', 'core/group', 'core/heading', 'core/paragraph', 'core/post-content' ) === $names($r['blocks']), 'block tree order', json_encode($names($r['blocks'])) ?: '');
            $main = $r['blocks'][2] ?? array();
            $assert('core/group' === ( $main['blockName'] ?? null ) && 3 === count($main['innerBlocks'] ?? array()), 'the card is an inner block of the main group', json_encode($main) ?: '');
            $assert($consistent($r['blocks']), 'innerContent, innerBlocks and innerHTML stay consistent');
            $assert('success' === $r['status'], 'status is success when every block survives and the card converts', $r['status']);
        },
    ),
    'top-level stray HTML between two paragraphs is converted in place' => array(
        'input' => "<!-- wp:paragraph -->\n<p>First</p>\n<!-- /wp:paragraph -->\n<section><h2>Middle</h2></section>\n<!-- wp:paragraph -->\n<p>Last</p>\n<!-- /wp:paragraph -->",
        'check' => static function (array $r) use ($assert, $inOrder, $names): void {
            $s = $r['serialized_blocks'];
            $assert(str_starts_with($s, "<!-- wp:paragraph -->\n<p>First</p>\n<!-- /wp:paragraph -->\n"), 'the first paragraph is byte-identical', $s);
            $assert(str_ends_with($s, "\n<!-- wp:paragraph -->\n<p>Last</p>\n<!-- /wp:paragraph -->"), 'the last paragraph is byte-identical', $s);
            $assert($inOrder($s, '<p>First</p>', 'wp:heading', 'Middle', '<p>Last</p>'), 'order is preserved', $s);
            $assert(! str_contains($s, '<section>'), 'the stray section is not left as freeform HTML', $s);
            $assert(in_array('core/heading', $names($r['blocks']), true), 'the stray heading becomes a heading block');
        },
    ),
    'loose HTML inside a core/column next to an existing paragraph block' => array(
        'input' => "<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:paragraph -->\n<p>Kept</p>\n<!-- /wp:paragraph -->\n<ul><li>Loose item</li></ul></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->",
        'check' => static function (array $r) use ($assert, $inOrder, $consistent): void {
            $s = $r['serialized_blocks'];
            $assert(str_contains($s, "<div class=\"wp-block-column\"><!-- wp:paragraph -->\n<p>Kept</p>\n<!-- /wp:paragraph -->\n"), 'the paragraph block is kept as written', $s);
            $assert($inOrder($s, '<p>Kept</p>', '<!-- wp:list', 'Loose item', '</div>' . "\n" . '<!-- /wp:column -->'), 'the loose list becomes a list block inside the column, after the paragraph', $s);
            $column = $r['blocks'][0]['innerBlocks'][0] ?? array();
            $assert(array( 'core/paragraph', 'core/list' ) === array_column($column['innerBlocks'] ?? array(), 'blockName'), 'the list is spliced into the column innerBlocks', json_encode($column) ?: '');
            $assert(array( "\n<div class=\"wp-block-column\">", null, "\n", null, "</div>\n" ) === ( $column['innerContent'] ?? null ), 'the wrapper tags stay where they are with a placeholder per inner block', json_encode($column['innerContent'] ?? null) ?: '');
            $assert($consistent($r['blocks']), 'innerContent, innerBlocks and innerHTML stay consistent');
        },
    ),
    'a fully valid block document comes back byte-identical' => array(
        'input' => $valid,
        'check' => static function (array $r) use ($assert, $valid): void {
            $assert($valid === $r['serialized_blocks'], 'byte-identical output', $r['serialized_blocks']);
            $assert('success' === $r['status'], 'status is success', $r['status']);
            $assert(array() === $r['fallbacks'], 'no fallbacks');
        },
    ),
    'loose HTML in the inner container of a cover with no inner blocks yet' => array(
        'input' => "<!-- wp:cover {\"dimRatio\":50} -->\n<div class=\"wp-block-cover\"><span aria-hidden=\"true\" class=\"wp-block-cover__background has-background-dim\"></span><div class=\"wp-block-cover__inner-container\"><h2>Loose</h2></div></div>\n<!-- /wp:cover -->",
        'check' => static function (array $r) use ($assert, $consistent): void {
            $s = $r['serialized_blocks'];
            $assert(str_contains($s, '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading'), 'the cover background stays and the heading is converted inside the inner container', $s);
            $assert(array( 'core/heading' ) === array_column($r['blocks'][0]['innerBlocks'] ?? array(), 'blockName'), 'the heading is an inner block of the cover');
            $assert($consistent($r['blocks']), 'innerContent, innerBlocks and innerHTML stay consistent');
        },
    ),
    'whitespace and comments around blocks stay as they are' => array(
        'input' => "<!-- a note -->\n\n<!-- wp:group -->\n<div class=\"wp-block-group\">\n  <!-- keep me -->\n  <!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n</div>\n<!-- /wp:group -->\n",
        'check' => static function (array $r) use ($assert): void {
            $assert("<!-- a note -->\n\n<!-- wp:group -->\n<div class=\"wp-block-group\">\n  <!-- keep me -->\n  <!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n</div>\n<!-- /wp:group -->\n" === $r['serialized_blocks'], 'comment-only and whitespace-only strings are untouched', $r['serialized_blocks']);
            $assert('success' === $r['status'], 'status is success', $r['status']);
        },
    ),
    'a list item keeps its own text around a nested list' => array(
        'input' => "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Parent<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Child</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
        'check' => static function (array $r) use ($assert): void {
            $assert(str_contains($r['serialized_blocks'], "<li>Parent<!-- wp:list -->"), 'list item text is the block\'s own content, not loose HTML', $r['serialized_blocks']);
            $assert(! str_contains($r['serialized_blocks'], 'wp:paragraph'), 'nothing is converted inside the list item');
        },
    ),
    'HTML that converts to no blocks is kept and reported' => array(
        'input' => "<!-- wp:paragraph -->\n<p>Lead</p>\n<!-- /wp:paragraph -->\n<object data=\"movie.swf\"></object>",
        'check' => static function (array $r) use ($assert, $diagnosticCodes): void {
            $assert(str_contains($r['serialized_blocks'], '<object data="movie.swf"></object>'), 'the HTML stays in the output instead of being deleted', $r['serialized_blocks']);
            $assert('success' !== $r['status'], 'status is not success', $r['status']);
            $assert(in_array('block_markup_input_html_kept', $diagnosticCodes($r), true), 'a diagnostic names the kept fragment', json_encode($diagnosticCodes($r)) ?: '');
        },
    ),
    'a converted piece that is not a clean success downgrades the status with a diagnostic' => array(
        'input' => "<!-- wp:paragraph -->\n<p>Lead</p>\n<!-- /wp:paragraph -->\n<form><input type=\"text\" name=\"q\"><select><option>a</option></select></form>",
        'options' => array( 'context' => array( 'allow_fallbacks' => false ) ),
        'check' => static function (array $r) use ($assert, $diagnosticCodes): void {
            $assert('success_with_warnings' === $r['status'], 'the worse piece status is reported', $r['status']);
            $assert(in_array('block_markup_input_piece_status', $diagnosticCodes($r), true), 'a diagnostic names the piece', json_encode($diagnosticCodes($r)) ?: '');
            $assert(count($r['fallbacks']) > 0, 'the piece fallbacks are merged');
            $assert(str_starts_with($r['serialized_blocks'], "<!-- wp:paragraph -->\n<p>Lead</p>\n<!-- /wp:paragraph -->"), 'the kept paragraph is unchanged');
        },
    ),
    'block comments stray inside HTML elements take the HTML path and do not report success' => array(
        'input' => "<!doctype html><html><body><main><h1>Title</h1><div class=\"text\"><p><!-- wp:paragraph --></p>\n<p>First.</p>\n<p><!-- /wp:paragraph --></p></div></main></body></html>",
        'check' => static function (array $r) use ($assert, $diagnosticCodes): void {
            $s = $r['serialized_blocks'];
            $assert(str_starts_with($s, '<!-- wp:') && str_contains($s, 'First.') && ! str_contains($s, '<html'), 'the document is converted to blocks', $s);
            $assert('success' !== $r['status'], 'dropping the stray delimiters is not success', $r['status']);
            $assert(in_array('block_markup_input_stray_delimiters', $diagnosticCodes($r), true), 'a diagnostic explains the stray delimiters');
        },
    ),
    'support stylesheets from a converted piece are returned, metrics merge numerically' => array(
        'input' => "<!-- wp:paragraph -->\n<p>Lead</p>\n<!-- /wp:paragraph -->\n" . $hero . "\n<!-- wp:paragraph -->\n<p>Tail</p>\n<!-- /wp:paragraph -->\n" . $hero,
        'options' => array( 'static_css' => $heroCss ),
        'check' => static function (array $r) use ($assert, $run, $hero, $heroCss, $assetPaths): void {
            $alone = $run($hero, array( 'static_css' => $heroCss ));
            $assert(count($alone['assets']) > 0, 'converting the piece alone emits support stylesheets');
            $assert(array() === array_diff($assetPaths($alone), $assetPaths($r)), 'every stylesheet of the piece is in the result', json_encode(array( $assetPaths($alone), $assetPaths($r) )) ?: '');
            $assert(count($assetPaths($r)) === count(array_unique($assetPaths($r))), 'the same stylesheet from two pieces is returned once');
            $assert(2 === ( $r['metrics']['block_markup_converted_fragment_count'] ?? null ), 'both pieces are converted', json_encode($r['metrics']) ?: '');
            $assert(2 * $alone['metrics']['selector_match_cache_misses'] === $r['metrics']['selector_match_cache_misses'], 'numeric metrics add across pieces', json_encode($r['metrics']) ?: '');
            $assert(strlen($r['serialized_blocks']) === $r['metrics']['output_bytes'] && 8 === $r['metrics']['block_count'], 'whole-document metrics describe the merged output', json_encode($r['metrics']) ?: '');
            $assert('blocks-engine/php-transformer/conversion-report/v1' === ( $r['source_reports']['conversion_report']['schema'] ?? null ), 'the result keeps the canonical envelope');
        },
    ),
    'input without block delimiters takes the HTML path unchanged' => array(
        'input' => $hero,
        'options' => array( 'static_css' => $heroCss ),
        'check' => static function (array $r) use ($assert, $diagnosticCodes): void {
            $assert(! in_array('block_markup_input', $diagnosticCodes($r), true), 'no block markup handling for plain HTML');
            $assert(str_starts_with($r['serialized_blocks'], '<!-- wp:group'), 'plain HTML is converted as before', $r['serialized_blocks']);
        },
    ),
);

foreach ( $cases as $name => $case ) {
    $before = $failures;
    $case['check']($run($case['input'], $case['options'] ?? array()));
    if ( $failures > $before ) {
        fwrite(STDERR, '  in case: ' . $name . PHP_EOL);
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, sprintf('%d of %d block markup input assertions failed.', $failures, $assertions) . PHP_EOL);
    exit(1);
}

echo sprintf('block markup input: %d assertions passed.', $assertions) . PHP_EOL;
