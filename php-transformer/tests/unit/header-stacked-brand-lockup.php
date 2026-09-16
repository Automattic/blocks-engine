<?php
declare(strict_types=1);

/**
 * A header brand lockup that is a link around stacked text spans stays a link
 * with its two-line structure.
 *
 * The Eloisa Calvinato header authors `<a href="#inicio">` around a serif name
 * span and a tracked uppercase role span, stacked by a column flex utility. The
 * import flattened the lockup into one synthetic paragraph carrying both runs:
 * the lines merged, the per-line typography was lost to the paragraph's own, and
 * the site title stopped being a link. The lockup is a link wrapper, so it must
 * convert like one — a group carrying the anchor's box, holding one linked text
 * block per authored line.
 *
 * The utility classes only stack because the captured page ships the stylesheet
 * that defines them, so the fixtures include the equivalent declarations.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

$blockHtml = static fn (array $block): string => (string) ($block['innerHTML'] ?? '');

/** @param array<int, array<string, mixed>> $blocks */
$findBlocks = static function (array $blocks, string $name) use (&$findBlocks): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $findBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name));
    }

    return $found;
};

/** @param array<int, array<string, mixed>> $blocks @return array<int, array<string, mixed>> */
$syntheticParagraphsOf = static fn (array $blocks): array => array_values(array_filter(
    $findBlocks($blocks, 'core/paragraph'),
    static fn (array $block): bool => str_contains((string) ($block['attrs']['className'] ?? ''), 'blocks-engine-synthetic-paragraph')
));

$fixtureCss = '.flex{display:flex}.flex-col{flex-direction:column}.font-serif{font-family:serif;font-size:1.5rem}'
    . '.uppercase{text-transform:uppercase;font-size:.65rem;letter-spacing:.2em}'
    . 'header{padding:20px;display:flex;align-items:center;justify-content:space-between}nav{display:flex;gap:20px}';

// The reported shape: a linked brand lockup stacking name and role spans.
$lockup = $transform(
    '<style>' . $fixtureCss . '</style>'
    . '<header>'
    . '<a class="flex flex-col" href="#home"><span class="font-serif">Studio Name</span><span class="uppercase">ROLE</span></a>'
    . '<nav aria-label="Primary"><a href="#work">Work</a><a href="#contact">Contact</a></nav>'
    . '</header>'
);
$markup = (string) ($lockup['serialized_blocks'] ?? '');
$blocks = is_array($lockup['blocks'] ?? null) ? $lockup['blocks'] : array();
$syntheticParagraphs = $syntheticParagraphsOf($blocks);

$assert(
    array() === array_values(array_filter(
        $syntheticParagraphs,
        static fn (array $block): bool => str_contains($blockHtml($block), 'Studio Name')
            && str_contains($blockHtml($block), 'ROLE')
    )),
    'no single synthetic paragraph carries both text runs',
    $markup
);
$assert(
    2 === substr_count($markup, '<a href="#home">'),
    'the lockup link survives on both saved lines',
    $markup
);
$assert(
    2 === preg_match_all('/<a href="#home">[^<]*<span[^>]*>(?:Studio Name|ROLE)<\/span><\/a>/', $markup),
    'each authored line keeps its own text run inside the link',
    $markup
);
$assert(
    1 === count(array_filter($findBlocks($blocks, 'core/group'), static fn (array $block): bool => 'header' === strtolower((string) ($block['attrs']['tagName'] ?? '')))),
    'the header landmark still carries the lockup and the menu',
    $markup
);
$assert(
    2 === count(array_filter($findBlocks($blocks, 'core/paragraph'), static fn (array $block): bool => str_contains($blockHtml($block), 'href="#home"'))),
    'both lines are separate text blocks that each carry the link',
    $markup
);

// Block-level spans stack in plain flow and get the same treatment.
$blockSpans = $transform(
    '<style>.lockup{display:block}.brand-line{display:block;font-family:serif}.brand-line+.brand-line{font-size:.65rem;text-transform:uppercase}</style>'
    . '<header><a class="lockup" href="/"><span class="brand-line">Studio Name</span><span class="brand-line">ROLE</span></a></header>'
);
$blockSpanMarkup = (string) ($blockSpans['serialized_blocks'] ?? '');
$blockSpanBlocks = is_array($blockSpans['blocks'] ?? null) ? $blockSpans['blocks'] : array();
$assert(
    2 === substr_count($blockSpanMarkup, '<a href="/">'),
    'block-level span lines each keep the lockup link',
    $blockSpanMarkup
);
$assert(
    array() === array_values(array_filter(
        $syntheticParagraphsOf($blockSpanBlocks),
        static fn (array $block): bool => str_contains($blockHtml($block), 'Studio Name')
            && str_contains($blockHtml($block), 'ROLE')
    )),
    'block-level span lockups are not flattened into one synthetic paragraph',
    $blockSpanMarkup
);

// Control: the same classes without their stylesheet leave both spans on one
// text line, where today's single paragraph is faithful.
$unstyled = $transform(
    '<header><a class="flex flex-col" href="#home"><span>Studio Name</span><span>ROLE</span></a></header>'
);
$unstyledMarkup = (string) ($unstyled['serialized_blocks'] ?? '');
$unstyledSynthetic = $syntheticParagraphsOf(is_array($unstyled['blocks'] ?? null) ? $unstyled['blocks'] : array());
$assert(
    array() !== $unstyledSynthetic && 1 === substr_count($unstyledMarkup, 'Studio Name') && str_contains($unstyledMarkup, 'href="#home"'),
    'without stacking styles the inline lockup stays a single paragraph host',
    $unstyledMarkup
);

// Control: a row flex link lays its spans out on one line, where flattening is
// faithful and the paragraph host is kept.
$rowCss = '.flex{display:flex;align-items:center;gap:8px}header{padding:20px}';
$row = $transform(
    '<style>' . $rowCss . '</style>'
    . '<header><a class="flex" href="/"><span>Icon</span><span>Label</span></a></header>'
);
$rowMarkup = (string) ($row['serialized_blocks'] ?? '');
$rowSynthetic = $syntheticParagraphsOf(is_array($row['blocks'] ?? null) ? $row['blocks'] : array());
$assert(
    array() !== $rowSynthetic && str_contains($rowMarkup, 'Label'),
    'a row flex link stays a single paragraph host',
    $rowMarkup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Header stacked brand lockup contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Header stacked brand lockup contract passed: {$passes} assertions\n");
