<?php
declare(strict_types=1);

/**
 * A <button> (or button-signaled <a>) whose subtree is branching flow content
 * is not a core/button candidate. Flattening it into RichText destroys headings
 * and concatenates distinct blocks into one uneditable text run.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$blockNames = static function (array $blocks) use (&$blockNames): array {
    $names = array();
    foreach ( $blocks as $block ) {
        if ( ! empty($block['blockName']) ) {
            $names[] = $block['blockName'];
        }
        if ( ! empty($block['innerBlocks']) ) {
            $names = array_merge($names, $blockNames($block['innerBlocks']));
        }
    }

    return $names;
};

$flattenButtonTexts = static function (array $blocks) use (&$flattenButtonTexts): array {
    $texts = array();
    foreach ( $blocks as $block ) {
        if ( 'core/button' === ( $block['blockName'] ?? '' ) ) {
            $texts[] = (string) ( $block['attrs']['text'] ?? '' );
        }
        if ( ! empty($block['innerBlocks']) ) {
            $texts = array_merge($texts, $flattenButtonTexts($block['innerBlocks']));
        }
    }

    return $texts;
};

$cardButton = ( new HtmlTransformer() )->transform(
    '<main><button type="button">'
    . '<div><h3>Orangutan TT\'s</h3><p>70% Indica / 30% Sativa</p></div>'
    . '<div><p>Offers significant analgesic and anxiolytic effects.</p></div>'
    . '</button></main>'
)->toArray();
$cardNames = $blockNames($cardButton['blocks'] ?? array());
$cardMarkup = (string) ( $cardButton['serialized_blocks'] ?? '' );
$cardButtonTexts = $flattenButtonTexts($cardButton['blocks'] ?? array());

$assert(
    in_array('core/heading', $cardNames, true) && in_array('core/paragraph', $cardNames, true),
    'a button wrapping a heading plus flow content keeps core/heading and core/paragraph',
    $cardMarkup
);
$assert(
    ! in_array('core/button', $cardNames, true),
    'a button wrapping a heading plus flow content does not become core/button',
    $cardMarkup
);
$assert(
    str_contains($cardMarkup, 'Orangutan TT') && str_contains($cardMarkup, 'Offers significant analgesic'),
    'the heading title and description remain distinct visible content',
    $cardMarkup
);
foreach ( $cardButtonTexts as $text ) {
    $assert(
        ! str_contains($text, 'Orangutan TT') || ! str_contains($text, 'Offers significant analgesic'),
        'heading and description are not concatenated into one button text attribute',
        $text
    );
}

$direct = ( new HtmlTransformer() )->transform(
    '<main><button type="button"><h3>Cultivar name</h3><p>Lineage text</p></button></main>'
)->toArray();
$directNames = $blockNames($direct['blocks'] ?? array());
$directMarkup = (string) ( $direct['serialized_blocks'] ?? '' );
$assert(
    in_array('core/heading', $directNames, true) && in_array('core/paragraph', $directNames, true) && ! in_array('core/button', $directNames, true),
    'a button whose direct children are a heading and a paragraph keeps those as inner blocks',
    $directMarkup
);

$cardAnchor = ( new HtmlTransformer() )->transform(
    '<main><a href="/strain" style="padding:16px;background:#fff;border-radius:4px;display:flex">'
    . '<h3>Cultivar name</h3><p>Lineage text</p></a></main>'
)->toArray();
$anchorNames = $blockNames($cardAnchor['blocks'] ?? array());
$anchorMarkup = (string) ( $cardAnchor['serialized_blocks'] ?? '' );
$assert(
    in_array('core/heading', $anchorNames, true) && in_array('core/paragraph', $anchorNames, true),
    'a button-signaled anchor wrapping a heading plus a paragraph keeps those as inner blocks',
    $anchorMarkup
);
$assert(
    ! in_array('core/button', $anchorNames, true),
    'a button-signaled card anchor does not flatten into core/button RichText',
    $anchorMarkup
);

$ctaLabel = ( new HtmlTransformer() )->transform(
    '<main><a class="primary-button" href="/book" style="padding:10px 16px;background:#135e96"><h3>Reserve now</h3><span aria-hidden="true"></span></a></main>'
)->toArray();
$ctaNames = $blockNames($ctaLabel['blocks'] ?? array());
$ctaMarkup = (string) ( $ctaLabel['serialized_blocks'] ?? '' );
$assert(
    in_array('core/button', $ctaNames, true) && ! in_array('core/heading', $ctaNames, true),
    'a heading used as the sole visible CTA label still lowers to core/button',
    $ctaMarkup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "block-content-button-not-richtext tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo 'block-content-button-not-richtext tests: ' . $passes . " passed\n";
