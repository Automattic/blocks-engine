<?php
declare(strict_types=1);

/**
 * Author CSS can style text entirely through custom properties: a rule reads
 * `font-family: var(--text-font-family, …)` and the element's inline style
 * defines `--text-font-family`. When lowering replaces that inline style with
 * a carrier, the definitions something still reads must travel with it:
 *
 * - a styled span lowered to a RichText carrier keeps the custom properties
 *   the author rules matching it read;
 * - a wrapper keeps the custom properties its descendants' inline
 *   declarations read through inheritance (`--text-color: var(--accent)`).
 *
 * Definitions nothing reads stay dropped.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CanonicalSaveShapeValidator;

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

$css = '.text{font-family:var(--text-font-family, Inter, sans-serif);color:var(--text-color, #000);text-transform:var(--text-transform, none);font-size:var(--text-font-size, 16px);font-weight:var(--text-font-weight, 400)}.box{position:relative}';

/**
 * @return array{blocks: array<int, array<string, mixed>>, serialized: string, css: string}
 */
$transform = static function (string $html) use ($css): array {
    $result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
    $styles = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        $styles .= (string) ($asset['content'] ?? '') . "\n";
    }

    return array( 'blocks' => $result['blocks'] ?? array(), 'serialized' => (string) ($result['serialized_blocks'] ?? ''), 'css' => $styles );
};

/** The declarations of the generated rule for the first carrier class on an element whose class starts with `$class`. */
$carrierRule = static function (array $out, string $class): string {
    if ( ! preg_match('/class="' . preg_quote($class, '/') . '\b[^"]*\b(be-inline-geometry-[a-f0-9-]+)/', $out['serialized'], $match) ) {
        return '';
    }

    return preg_match('/\.' . preg_quote($match[1], '/') . '\{([^}]*)\}/', $out['css'], $rule) ? $rule[1] : '';
};

// A span whose author rule reads its inline custom properties.
$span = $transform('<main><div class="box"><div class="rt" style="--accent: rgb(255, 255, 255); --rt-unused: 1px;"><p class="text" style="--text-font-size: 40px;"><span class="text" style="--text-font-family: &quot;Lustria&quot;; --text-color: var(--accent); --text-transform: uppercase; --span-unused: 2px;">Celebrate with us</span></p></div></div></main>');
$assert(1 === preg_match('/<mark class="text(?: blocks-engine-[a-z0-9-]+)*"[^>]*>/', $span['serialized'], $mark), 'span: lowered to a RichText mark carrier', substr($span['serialized'], 0, 600));
$markStyle = html_entity_decode($mark[0] ?? '', ENT_QUOTES);
$assert(str_contains($markStyle, 'font-family:var(--text-font-family'), 'span: carrier keeps the author rule reading the font family', $markStyle);
$assert(str_contains($markStyle, '--text-font-family:"Lustria"'), 'span: carrier defines the font family the rule reads', $markStyle);
$assert(str_contains($markStyle, '--text-transform:uppercase'), 'span: carrier defines the text transform the rule reads', $markStyle);
$assert(str_contains($markStyle, '--text-color:var(--accent)'), 'span: carrier defines the color the rule reads', $markStyle);
$assert(! str_contains($markStyle, '--span-unused'), 'span: a definition nothing reads is not carried', $markStyle);
$rtRule = $carrierRule($span, 'wp-block-group rt');
$assert(str_contains($rtRule, '--accent:rgb(255, 255, 255)'), 'span: the wrapper carries the definition its descendant reads', $rtRule . ' / ' . substr($span['serialized'], 0, 400));
$assert(! str_contains($rtRule, '--rt-unused'), 'span: the wrapper drops a definition nothing reads', $rtRule);
$assert(array() === ( new CanonicalSaveShapeValidator() )->findings($span['blocks']), 'span: canonical save shape holds');

// A wrapper whose custom properties a descendant paragraph's inline style reads.
$wrapper = $transform('<main><div class="box"><a class="cta" href="#rsvp" style="height:100%;border-radius:10px"><div class="bg" style="background-color: rgb(64, 79, 51); border-radius: 10px;"></div><div class="label" style="--label-color: var(--token, rgb(255, 255, 255)); --label-size: 14; --label-unused: 3px;"><p class="text" style="--text-font-size: calc(var(--label-size) * 1px); --text-font-weight: 500; --text-color: var(--label-color);">Submit RSVP</p></div></a></div></main>');
$labelRule = $carrierRule($wrapper, 'wp-block-group label');
$assert(str_contains($labelRule, '--label-color:var(--token, rgb(255, 255, 255))'), 'wrapper: carries the color its descendant reads', $labelRule . ' / ' . substr($wrapper['serialized'], 0, 900));
$assert(str_contains($labelRule, '--label-size:14'), 'wrapper: carries the size its descendant reads', $labelRule);
$assert(! str_contains($labelRule, '--label-unused'), 'wrapper: drops a definition nothing reads', $labelRule);
$textRule = $carrierRule($wrapper, 'text');
$assert(str_contains($textRule, '--text-font-size:calc(var(--label-size) * 1px)'), 'wrapper: the descendant keeps its own reading declarations', $textRule);
$assert(str_contains($wrapper['serialized'], 'background-color:rgb(64, 79, 51)'), 'wrapper: the sibling background shape survives');
$assert(array() === ( new CanonicalSaveShapeValidator() )->findings($wrapper['blocks']), 'wrapper: canonical save shape holds');

if ( 0 < $failures ) {
    fwrite(STDERR, "Consumed inline custom properties contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Consumed inline custom properties contract passed: {$passes} assertions\n");
