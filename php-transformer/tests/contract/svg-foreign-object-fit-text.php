<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * Contract coverage for the foreignObject "fit text" technique.
 *
 * An SVG whose only content is one foreignObject-wrapped text element
 * (`<svg viewBox="0 0 83 16"><foreignObject width="100%" height="100%"><p>SCHWARTZ</p>
 * </foreignObject></svg>`) draws a line of text inside an SVG sizing box: the
 * SVG itself paints nothing, survives sanitization as an empty graphic, and
 * used to fall back as metadata that rendered nothing. It lowers to the native
 * text block the inner element renders as — core/paragraph, or core/heading
 * for h1-h6 — with core's fit-text support (`"fitText":true`, saved with the
 * `has-fit-text` class) and the SVG's own class kept on the text block so the
 * authored box still sizes the line.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$compile = static function (string $svg, string $innerRule): array {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Fit text</title><style>'
        . 'footer{background:#101010;padding:40px}'
        . '.fit{display:block;width:100%;height:auto;overflow:visible}'
        . $innerRule
        . '</style></head><body><footer>' . $svg
        . '<p style="color:#f5f3ee">Footer text</p></footer></body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
};

$pageMarkUp = static function (array $result): string {
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    return (string) (current($plan['pages'] ?? array())['canonical_block_markup'] ?? '');
};

$fitBlocks = static function (string $page): array {
    $found = array();
    $walk = static function (array $blocks) use (&$walk, &$found): void {
        foreach ($blocks as $block) {
            if (true === ($block['attrs']['fitText'] ?? null)) {
                $found[] = $block;
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk((new Runtime())->parseBlocks($page));

    return $found;
};

$paragraphs = static function (string $page): array {
    $found = array();
    $walk = static function (array $blocks) use (&$walk, &$found): void {
        foreach ($blocks as $block) {
            if ('core/paragraph' === ($block['blockName'] ?? null)) {
                $found[] = $block;
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk((new Runtime())->parseBlocks($page));

    return $found;
};

foreach (array(
    'paragraph' => array(
        'svg'       => '<svg class="fit" viewBox="0 0 83 16"><foreignObject width="100%" height="100%"><p>SCHWARTZ</p></foreignObject></svg>',
        'rule'      => '.fit p{margin:0;color:#f5f3ee;font-family:sans-serif;font-weight:500;line-height:1em;white-space:nowrap}',
        'blockName' => 'core/paragraph',
        'saveTag'   => 'p',
    ),
    'heading' => array(
        'svg'       => '<svg class="fit" viewBox="0 0 83 16"><foreignObject width="100%" height="100%"><h2>SCHWARTZ</h2></foreignObject></svg>',
        'rule'      => '.fit h2{margin:0}',
        'blockName' => 'core/heading',
        'level'     => 2,
        'saveTag'   => 'h2',
    ),
) as $label => $case) {
    $result = $compile($case['svg'], $case['rule']);
    $assert(! str_contains((string) json_encode($result), 'html_inline_svg_fallback'), "{$label}: the fit-text SVG is not routed to the inline SVG fallback.");
    $page = $pageMarkUp($result);

    $fit = $fitBlocks($page);
    $assert(1 === count($fit), "{$label}: exactly one block carries fitText.");
    $block = $fit[0];
    $assert($case['blockName'] === ($block['blockName'] ?? ''), "{$label}: the inner text element lowers to its native text block.");
    $assert(true === ($block['attrs']['fitText'] ?? null), "{$label}: the text block declares fitText.");
    if (isset($case['level'])) {
        $assert($case['level'] === ($block['attrs']['level'] ?? null), "{$label}: the heading keeps the inner element's level.");
    }
    $assert(str_contains($page, '"fitText":true'), "{$label}: the serialized comment declares \"fitText\":true.");
    $assert(1 === preg_match('/<' . $case['saveTag'] . '[^>]*class="[^"]*\bfit\b[^"]*\bhas-fit-text\b[^"]*">SCHWARTZ</', $page), "{$label}: the saved markup carries the SVG's class and the has-fit-text class with the lowered text.");

    $siblings = $paragraphs($page);
    $sibling = null;
    foreach ($siblings as $candidate) {
        if (str_contains((string) ($candidate['innerHTML'] ?? ''), 'Footer text')) {
            $sibling = $candidate;
            break;
        }
    }
    $assert(null !== $sibling, "{$label}: the sibling paragraph stays a paragraph.");
    $assert('contrast' === ($sibling['attrs']['textColor'] ?? ''), "{$label}: the sibling paragraph keeps its resolved color.");
    $assert(1 !== preg_match('/(?:^| )(?:fit|has-fit-text)(?:$| )/', (string) ($sibling['attrs']['className'] ?? '')), "{$label}: the sibling paragraph stays unchanged by the lowering.");
}

echo "Foreign-object fit text contract passed.\n";
