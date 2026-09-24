<?php
declare(strict_types=1);

/**
 * Unit tests for accordion trigger labels that carry a nested icon wrapper.
 *
 * Plain-PHP test script — no PHPUnit. core/accordion-heading stores its title
 * as phrasing RichText inside `.wp-block-accordion-heading__toggle-title` and
 * renders its own toggle icon. A source trigger whose decorative icon is a
 * nested div wrapper (`div > div > div + div`) must contribute only its label:
 * a partially stripped wrapper leaves closing `</div>`s in the title, which end
 * the accordion's own wrappers early and spill every panel out of the list.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$transform = static fn (string $html): string => (string) ( ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'] ?? '' );

/** @return list<string> */
$headingTitles = static function (string $blocks): array {
    // Read the saved title span verbatim; a DOM parse would repair the very
    // imbalance under test.
    preg_match_all('/<span class="wp-block-accordion-heading__toggle-title">(.*?)<\/span><span class="wp-block-accordion-heading__toggle-icon"/s', $blocks, $matches);
    return $matches[1];
};

$isBalanced = static function (string $html): bool {
    $void = array( 'area', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
    $stack = array();
    preg_match_all('/<(\/?)([a-z][a-z0-9-]*)\b[^>]*?(\/?)>/i', $html, $tags, PREG_SET_ORDER);
    foreach ( $tags as $tag ) {
        $name = strtolower($tag[2]);
        if ( in_array($name, $void, true) || '/' === $tag[3] ) {
            continue;
        }
        if ( '' === $tag[1] ) {
            $stack[] = $name;
            continue;
        }
        if ( array_pop($stack) !== $name ) {
            return false;
        }
    }

    return array() === $stack;
};

$icon = static fn (string $iconAttrs): string =>
    '<div class="icon-container"' . $iconAttrs . '><div class="plus"><div class="plus__horizontal"></div><div class="plus__vertical"></div></div></div>';

$trigger = static fn (string $n, string $iconAttrs): string =>
    '<button type="button" class="item__trigger" aria-expanded="false" aria-controls="panel-' . $n . '">'
    . '<span class="item__title">Question ' . $n . ' &amp; more</span> ' . $icon($iconAttrs) . '</button>';

foreach ( array( 'plain icon wrapper' => '', 'aria-hidden icon wrapper' => ' aria-hidden="true"' ) as $case => $iconAttrs ) {
    $item = static fn (string $n): string =>
        '<li class="item"><h3 class="item__heading">' . $trigger($n, $iconAttrs) . '</h3>'
        . '<div class="item__panel" id="panel-' . $n . '" role="region"><div class="item__body"><p>Answer ' . $n . ' <a href="/answer-' . $n . '">read more</a></p></div></div></li>';

    $blocks = $transform('<main><ul class="accordion">' . $item('1') . $item('2') . $item('3') . '</ul></main>');
    $titles = $headingTitles($blocks);

    $assert(3 === count($titles), $case . ': every trigger converts to core/accordion-heading', $blocks);
    foreach ( $titles as $index => $title ) {
        $n = (string) ( $index + 1 );
        $assert($isBalanced($title), $case . ': heading ' . $n . ' title HTML is balanced', $title);
        $assert(! str_contains($title, '<div') && ! str_contains($title, '</div'), $case . ': heading ' . $n . ' title carries no div from the icon wrapper', $title);
        $assert(str_contains($title, 'Question ' . $n . ' &amp; more'), $case . ': heading ' . $n . ' title keeps the label text', $title);
    }

    // The panel content that follows the trigger stays inside its own item.
    $assert(
        1 === preg_match('/<!-- wp:accordion-item\b(?:(?!<!-- \/wp:accordion-item -->).)*Question 2 &amp; more(?:(?!<!-- \/wp:accordion-item -->).)*<!-- wp:accordion-panel\b(?:(?!<!-- \/wp:accordion-item -->).)*Answer 2 (?:(?!<!-- \/wp:accordion-item -->).)*read more/s', $blocks),
        $case . ': the following panel content converts into the same accordion item',
        $blocks
    );
    $assert(
        3 === substr_count($blocks, '<!-- wp:accordion-panel') && 3 === substr_count($blocks, '<!-- /wp:accordion-item -->'),
        $case . ': each item keeps one panel',
        $blocks
    );
    $assert($isBalanced(preg_replace('/<!--.*?-->/s', '', $blocks) ?? ''), $case . ': the serialized accordion markup is balanced', $blocks);
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Accordion heading icon wrapper: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Accordion heading icon wrapper passed: {$passes} assertions\n");
