<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$transform = static fn (string $html): array => (new HtmlTransformer())->transform('<style>:root{--ink:rgb(250,240,230)}</style><main>' . $html . '</main>')->toArray();
$paragraphs = static function (array $result): array {
    $found = array();
    $walk = static function (array $blocks) use (&$walk, &$found): void {
        foreach ( $blocks as $block ) {
            if ( 'core/paragraph' === ($block['blockName'] ?? null) ) {
                $found[] = $block['attrs'] ?? array();
            }
            $walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
        }
    };
    $walk(is_array($result['blocks'] ?? null) ? $result['blocks'] : array());

    return $found;
};

// Lettering drawn as one SVG text run, captured mid reveal: the rectangle mask
// is still zero wide, an invisible pen-tip marker sits at the end of the line.
$lettering = static fn (string $maskWidth): string => '<div tabindex="0" role="img" aria-label="Ada &amp; Lin" style="display:inline-block">'
    . '<svg aria-hidden="true" width="100%" height="149.5" viewBox="0 0 328.4 149.5" preserveAspectRatio="xMinYMid meet" style="overflow:visible;display:block">'
    . '<defs><mask id=":r1:-0"><g><rect x="-20" y="-30" height="299" fill="#FFFFFF" width="' . $maskWidth . '"></rect></g></mask></defs>'
    . '<text x="0" y="84.5" fill="var(--ink, rgb(255,255,255))" mask="url(#:r1:-0)" style="font-size:65px;font-family:&quot;Script Face&quot;, sans-serif;font-weight:400;font-style:normal;letter-spacing:1px">Ada &amp; Lin</text>'
    . '<circle cy="68.25" r="1.6" fill="#000" cx="328.4" opacity="0"></circle>'
    . '</svg></div>';

foreach ( array( '0px', '368.4px' ) as $maskWidth ) {
    $result = $transform($lettering($maskWidth));
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(! str_contains($markup, '<!-- wp:html') && ! str_contains($markup, '<svg'), "A single SVG text run is not kept as raw SVG markup (mask {$maskWidth}).");
    $assert(array() === array_filter($result['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_inline_svg_fallback' === ($fallback['diagnostic_code'] ?? null)), "No inline SVG fallback is reported (mask {$maskWidth}).");

    $text = $paragraphs($result);
    $assert(1 === count($text) && 'Ada &amp; Lin' === ($text[0]['content'] ?? null), "The text run lowers to one editable paragraph (mask {$maskWidth}).");
    $typography = $text[0]['style']['typography'] ?? array();
    $assert('65px' === ($typography['fontSize'] ?? null) && '1px' === ($typography['letterSpacing'] ?? null) && '"Script Face", sans-serif' === ($typography['fontFamily'] ?? null) && '400' === ($typography['fontWeight'] ?? null), "The paragraph carries the run's typography (mask {$maskWidth}).");
    $assert('149.5px' === ($typography['lineHeight'] ?? null), "The paragraph line box keeps the SVG's rendered height (mask {$maskWidth}).");
    $assert('rgb(250,240,230)' === str_replace(' ', '', (string) ($text[0]['style']['color']['text'] ?? '')), "The run's fill resolves through custom properties into the text color (mask {$maskWidth}).");
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), "The lowered paragraph is Gutenberg-valid (mask {$maskWidth}).");

    $frozen = $result['source_reports']['html']['frozen_hidden_state'] ?? array();
    $assert(1 === count($frozen) && 'url(#:r1:-0)' === ($frozen[0]['declarations']['mask'] ?? null), "The dropped reveal mask is recorded as frozen hidden state (mask {$maskWidth}).");
    $provenance = array_values(array_filter($result['source_reports']['html']['source_provenance'] ?? array(), static fn (array $entry): bool => 'text' === ($entry['tag'] ?? null)));
    $assert(1 === count($provenance) && 'editable_approximation' === ($provenance[0]['conversion_classification'] ?? null) && 'svg_text_run_as_native_text' === ($provenance[0]['preservation_strategy'] ?? null), "The lowering is classified as an editable approximation (mask {$maskWidth}).");
}

// A root with absolute size scales user units into CSS pixels.
$scaled = $paragraphs($transform('<div><svg width="200" height="50" viewBox="0 0 400 100"><text y="70" fill="#123456" style="font-size:40px;letter-spacing:2px">Scaled</text></svg></div>'));
$assert(1 === count($scaled) && '20px' === ($scaled[0]['style']['typography']['fontSize'] ?? null) && '1px' === ($scaled[0]['style']['typography']['letterSpacing'] ?? null) && '50px' === ($scaled[0]['style']['typography']['lineHeight'] ?? null), 'Viewbox scale applies to the lowered font size, tracking and line box.');

// Anything a paragraph line cannot reproduce keeps the SVG representation.
$kept = array(
    'visible geometry beside the text' => '<svg width="120" height="40" viewBox="0 0 120 40"><circle cx="5" cy="5" r="4" fill="#000"></circle><text y="30" fill="#000" font-size="20">Kept</text></svg>',
    'nested text runs'                 => '<svg width="120" height="40" viewBox="0 0 120 40"><text y="30" fill="#000" font-size="20">Ke<tspan fill="red">pt</tspan></text></svg>',
    'transformed text'                 => '<svg width="120" height="40" viewBox="0 0 120 40"><text y="30" fill="#000" font-size="20" transform="rotate(10)">Kept</text></svg>',
    'offset text'                      => '<svg width="120" height="40" viewBox="0 0 120 40"><text x="12" y="30" fill="#000" font-size="20">Kept</text></svg>',
    'two text runs'                    => '<svg width="120" height="40" viewBox="0 0 120 40"><text y="20" fill="#000" font-size="20">Ke</text><text y="40" fill="#000" font-size="20">pt</text></svg>',
    'gradient paint'                   => '<svg width="120" height="40" viewBox="0 0 120 40"><defs><linearGradient id="g"><stop offset="0" stop-color="#000"></stop></linearGradient></defs><text y="30" fill="url(#g)" font-size="20">Kept</text></svg>',
    'shaped mask'                      => '<svg width="120" height="40" viewBox="0 0 120 40"><defs><mask id="m"><circle cx="20" cy="20" r="20" fill="#fff"></circle></mask></defs><text y="30" fill="#000" font-size="20" mask="url(#m)">Kept</text></svg>',
    'container-sized root'             => '<svg viewBox="0 0 120 40"><text y="30" fill="#000" font-size="20">Kept</text></svg>',
);
foreach ( $kept as $case => $svg ) {
    $lowered = array_filter($paragraphs($transform('<div>' . $svg . '</div>')), static fn (array $attrs): bool => str_contains((string) ($attrs['content'] ?? ''), 'Ke'));
    $assert(array() === $lowered, "SVG with {$case} is not lowered to a text paragraph.");
}

echo "svg-text-run-native-text: {$assertions} assertions passed" . PHP_EOL;
