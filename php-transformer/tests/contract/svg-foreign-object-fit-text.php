<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
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
 * for h1-h6 — held by a core/group that carries the SVG's own class and
 * presentation. Author rules target the text as a descendant of that class
 * (`.fit p`), so the class must stay on the container: merging it onto the
 * text block itself would sever every one of them and the wordmark would
 * render unstyled. Core's fit-text support (`"fitText":true`) re-fits the
 * line to that container, and its canonical serialization splits the
 * `has-fit-text` marker across two places: the `className` attribute stays
 * clean while the saved element's class list carries `has-fit-text` — core
 * derives it in `save()` (and server render) whenever `fitText` is true.
 * A saved markup without the class fails the editor's save() validation and
 * a paragraph deprecation silently migrates the block, dropping `fitText`;
 * baking the class into the attribute diverges just the same.
 */

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 3000)));
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

/** Every block carrying fitText, paired with its immediate container block. */
$fitBlocks = static function (string $page): array {
    $found = array();
    $walk = static function (array $blocks, ?array $container) use (&$walk, &$found): void {
        foreach ($blocks as $block) {
            if (true === ($block['attrs']['fitText'] ?? null)) {
                $found[] = array($block, $container);
            }
            $walk($block['innerBlocks'] ?? array(), $block);
        }
    };
    $walk((new Runtime())->parseBlocks($page), null);

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

/** The emitted page markup parsed for selector matching. */
$loadMarkup = static function (string $markup): DOMXPath {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><!doctype html><html><body>' . $markup . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
};

/** The nearest ancestor carrying a class, mirroring the source `.fit` box. */
$bearerClassAncestor = static function (DOMElement $element, string $class): ?DOMElement {
    for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
        $classes = ' ' . preg_replace('/\s+/', ' ', trim($node->getAttribute('class'))) . ' ';
        if (str_contains($classes, ' ' . $class . ' ')) {
            return $node;
        }
    }

    return null;
};

/** The front-end stylesheets the page loads, in document order. */
$frontendCss = static function (array $result): string {
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $frontend = '';
    foreach ($plan['assets'] ?? array() as $asset) {
        if ('css' === ($asset['kind'] ?? null) && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
            $frontend .= (string) ($asset['content'] ?? '') . "\n";
        }
    }

    return $frontend;
};

/** Every non-editor front-end rule that reaches $element, as declaration bodies. */
$bodies = static function (DOMElement $element, string $frontend): array {
    $matched = array();
    (new CssStylesheetTransformer())->transformStyleRules($frontend, static function (string $prelude, string $body) use ($element, &$matched): string {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $selector = trim($selector);
            if (str_contains($selector, '.editor-styles-wrapper')) {
                continue;
            }
            $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector));
            if ($match['supported'] && $match['matches']) {
                $matched[] = $body;
                break;
            }
        }
        return $prelude . '{' . $body . '}';
    });

    return $matched;
};

/** Whether one of the matched bodies declares the property (with var() fallbacks). */
$declares = static function (array $bodies, string $property, string $value): bool {
    $pattern = '/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*(?:' . preg_quote($value, '/') . '|var\([^;{}]*,\s*' . preg_quote($value, '/') . '\s*\))\s*(?:!important)?\s*(?:;|$)/i';
    foreach ($bodies as $body) {
        if (1 === preg_match($pattern, $body)) {
            return true;
        }
    }

    return false;
};

foreach (array(
    'paragraph' => array(
        'svg'        => '<svg class="fit" viewBox="0 0 83 16"><foreignObject width="100%" height="100%"><p>SCHWARTZ</p></foreignObject></svg>',
        'rule'       => '.fit p{margin:0;color:#f5f3ee;font-family:sans-serif;font-weight:500;line-height:1em;white-space:nowrap}',
        'blockName'  => 'core/paragraph',
        'query'      => '//footer//p[contains(., "SCHWARTZ")]',
        'boundStyle' => array('color', '#f5f3ee'),
        'alsoBound'  => array('font-family', 'sans-serif'),
    ),
    'heading' => array(
        'svg'        => '<svg class="fit" viewBox="0 0 83 16"><foreignObject width="100%" height="100%"><h2>SCHWARTZ</h2></foreignObject></svg>',
        'rule'       => '.fit h2{margin:0}',
        'blockName'  => 'core/heading',
        'level'      => 2,
        'query'      => '//footer//h2[contains(., "SCHWARTZ")]',
        'boundStyle' => array('margin', '0'),
    ),
) as $label => $case) {
    $result = $compile($case['svg'], $case['rule']);
    $assert(! str_contains((string) json_encode($result), 'html_inline_svg_fallback'), "{$label}: the fit-text SVG is not routed to the inline SVG fallback.");
    $page = $pageMarkUp($result);

    $fit = $fitBlocks($page);
    $assert(1 === count($fit), "{$label}: exactly one block carries fitText.");
    list($block, $container) = $fit[0];
    $assert($case['blockName'] === ($block['blockName'] ?? ''), "{$label}: the inner text element lowers to its native text block.");
    $assert(true === ($block['attrs']['fitText'] ?? null), "{$label}: the text block declares fitText.");
    $assert(! preg_match('/(?:^| )(?:fit|has-fit-text)(?:$| )/', (string) ($block['attrs']['className'] ?? '')), "{$label}: the text block keeps only its own classes; the SVG's class stays on the container.");
    if (isset($case['level'])) {
        $assert($case['level'] === ($block['attrs']['level'] ?? null), "{$label}: the heading keeps the inner element's level.");
    }

    $assert('core/group' === ($container['blockName'] ?? ''), "{$label}: the text block sits inside a core/group container.", $page);
    $assert(str_contains(' ' . (string) ($container['attrs']['className'] ?? '') . ' ', ' fit '), "{$label}: the container carries the SVG's own class.", $page);
    $assert(str_contains($page, '"fitText":true'), "{$label}: the serialized comment declares \"fitText\":true.");

    $textElement = $loadMarkup($page)->query($case['query'])->item(0);
    $assert($textElement instanceof DOMElement, "{$label}: the lowered text is present in the saved markup.", $page);
    $bearer = $bearerClassAncestor($textElement, 'fit');
    $assert($bearer instanceof DOMElement && 'div' === $bearer->tagName, "{$label}: the saved text element sits inside the container that carries the SVG class.", $page);
    // Core's canonical serialization splits the marker: the className attribute
    // stays clean (asserted above) while the saved element's class list carries
    // has-fit-text. Missing either half breaks the editor's save() validation.
    $assert(1 === preg_match('/(?:^| )has-fit-text(?:$| )/', $textElement->getAttribute('class')), "{$label}: the saved text element's class list carries has-fit-text.", $page);

    // The author rule still binds: the compiled descendant selector matches the
    // text element's position inside the emitted container.
    $frontend = $frontendCss($result);
    $assert('' !== trim($frontend), "{$label}: the page loads front-end styles.", $frontend);
    $matchedBodies = $bodies($textElement, $frontend);
    $assert($declares($matchedBodies, $case['boundStyle'][0], $case['boundStyle'][1]), "{$label}: the authored .fit descendant rule still reaches the lowered text.", $frontend);
    if (isset($case['alsoBound'])) {
        $assert($declares($matchedBodies, $case['alsoBound'][0], $case['alsoBound'][1]), "{$label}: the authored .fit descendant rule's styling survives the lowering.", $frontend);
    }
    $assert($declares($bodies($bearer, $frontend), 'display', 'block'), "{$label}: the authored .fit box rule still reaches the container.", $frontend);

    $sibling = null;
    foreach ($paragraphs($page) as $candidate) {
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
