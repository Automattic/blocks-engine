<?php
declare(strict_types=1);

/**
 * An element that defines custom properties inline scopes them to its own
 * subtree: a descendant rule's `var()` resolves the inline value, not the
 * `:root` default. Every converted container has to keep that scope. The
 * ordinary group path carries the definitions in a generated carrier class;
 * the scroll-state companion block used to rebuild its wrapper from the id,
 * class and config alone, so the inline definitions disappeared and the
 * descendants fell back to `:root` (a solid header background turned white).
 *
 * Custom property names are case-sensitive. The carrier used to declare the
 * case-folded name (`--panelbg`), which no `var(--panelBg)` reader sees.
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

$transform = static function (string $html): array {
    $out = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return array(
        'block'    => $out['blocks'][0] ?? array(),
        'blocks'   => (string) ( $out['serialized_blocks'] ?? '' ),
        'css'      => $css,
        'validity' => $out['source_reports']['wp_block_validity']['status'] ?? null,
    );
};

/** The generated carrier class on `$markup`'s outermost `<header>` that declares `$property: $value`. */
$headerCarrierDeclaring = static function (array $result, string $declaration): string {
    if ( ! preg_match('/<header\b[^>]*\bclass="([^"]*)"/', $result['blocks'], $match) ) {
        return '';
    }
    foreach ( preg_split('/\s+/', $match[1]) ?: array() as $class ) {
        if ( str_starts_with($class, 'be-inline-geometry-')
            && preg_match('/\.' . preg_quote($class, '/') . '\{[^}]*' . preg_quote($declaration, '/') . '/', $result['css'])
        ) {
            return $class;
        }
    }

    return '';
};

$styles = '<style>'
    . ':root{--panelBg:#ffffff}'
    . '.bar .bar__fill{position:absolute;inset:0;background-color:var(--panelBg)}'
    . '</style>';
$inner = '<div class="bar__fill"></div><a href="/">Home</a>';
$config = htmlspecialchars('{"thresholdPx":20,"addClasses":["shrink"]}', ENT_QUOTES, 'UTF-8');

// 1. Ordinary container path: the inline definition is carried on the header.
$group = $transform($styles . '<header id="top" class="bar" style="--panelBg:#1e4a47">' . $inner . '</header>');
$assert(
    '' !== $headerCarrierDeclaring($group, '--panelBg:#1e4a47'),
    '1: a plain header keeps its inline custom property on a carrier its descendants inherit',
    $group['blocks'] . "\n" . $group['css']
);

// 2. Scroll-state companion path: the same header, marked with a captured
//    scroll toggle, must keep the same scope.
$scroll = $transform(
    $styles
    . '<header id="top" class="bar" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . $config . '" style="--panelBg:#1e4a47">'
    . $inner . '</header>'
);
$carrier = $headerCarrierDeclaring($scroll, '--panelBg:#1e4a47');
$assert(str_ends_with((string) ( $scroll['block']['blockName'] ?? '' ), '/scroll-state'), '2: the marked header still lowers to the scroll-state block');
$assert('' !== $carrier, '3: the scroll-state header keeps its inline custom property on a carrier', $scroll['blocks'] . "\n" . $scroll['css']);
$assert(
    '' !== $carrier && in_array($carrier, preg_split('/\s+/', (string) ( $scroll['block']['attrs']['className'] ?? '' )) ?: array(), true),
    '4: the carrier rides the block className, so the editor wrapper renders it too',
    (string) ( $scroll['block']['attrs']['className'] ?? '' )
);
$assert(str_contains($scroll['css'], ':root{--panelBg:#ffffff}'), '5: the :root default is still emitted for everything outside the header', $scroll['css']);
$assert(str_contains($scroll['blocks'], 'data-blocks-engine-scroll-state-config='), '6: the captured scroll evidence still rides the saved markup');
$assert('pass' === $scroll['validity'], '7: the carried scroll-state header is editor-valid', (string) json_encode($scroll['validity']));

// 3. An inline token that reads another inline token keeps both authored names.
$chained = $transform($styles . '<header id="top" class="bar" style="--panelBg:var(--brandTone);--brandTone:#1e4a47">' . $inner . '</header>');
$assert(
    '' !== $headerCarrierDeclaring($chained, '--brandTone:#1e4a47') && '' !== $headerCarrierDeclaring($chained, '--panelBg:var(--brandTone)'),
    '8: a token the carried definition depends on is carried under its authored name too',
    $chained['css']
);

// 4. A property the runtime toggles on the wrapper itself is left to the
//    runtime: an !important carrier would pin it against the scroll toggle.
$selfToggle = htmlspecialchars('{"thresholdPx":20,"styleTargets":[{"selector":":scope","properties":{"max-height":{"rest":"120px","scrolled":"60px"}}}]}', ENT_QUOTES, 'UTF-8');
$toggling = $transform(
    $styles
    . '<header id="top" class="bar" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . $selfToggle . '" style="--panelBg:#1e4a47;max-height:120px;width:640px">'
    . $inner . '</header>'
);
$toggleCarrier = $headerCarrierDeclaring($toggling, '--panelBg:#1e4a47');
preg_match('/\.' . preg_quote($toggleCarrier, '/') . '\{[^}]*\}/', $toggling['css'], $toggleRule);
$toggleRule = $toggleRule[0] ?? '';
$assert('' !== $toggleCarrier && str_contains($toggleRule, 'width:640px'), '9: untoggled inline geometry still rides the wrapper carrier', $toggling['css']);
$assert('' !== $toggleRule && ! str_contains($toggleRule, 'max-height'), '10: the runtime-toggled property is not pinned by the carrier', $toggleRule);

// 5. A scroll-state header without inline declarations gets no carrier.
$bare = $transform($styles . '<header id="top" class="bar" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . $config . '">' . $inner . '</header>');
$assert('bar' === ( $bare['block']['attrs']['className'] ?? null ), '11: a scroll-state header with no inline style keeps its source class list unchanged', (string) json_encode($bare['block']['attrs'] ?? null));

if ( 0 !== $failures ) {
    fwrite(STDERR, sprintf('inline-custom-property-scroll-state-carry: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

echo sprintf('inline-custom-property-scroll-state-carry: %d assertions passed%s', $passes, PHP_EOL);
