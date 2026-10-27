<?php
declare(strict_types=1);

/**
 * A materialized SVG keeps the author declarations that matched it. The custom
 * properties those declarations read were declared on the wrappers the
 * materialization collapses, so they have to be re-rooted on the image or the
 * carried `var()` is invalid at computed-value time and the artwork falls back
 * to its intrinsic viewBox geometry.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

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
    $svg = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( ! is_array($asset) ) {
            continue;
        }
        if ( 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
        if ( 'inline-svg' === ( $asset['source'] ?? '' ) ) {
            $svg .= (string) ( $asset['content'] ?? '' );
        }
    }

    return array( 'blocks' => (string) ( $out['serialized_blocks'] ?? '' ), 'css' => $css, 'svg' => $svg );
};

// An icon inside a converted button: the wrapper that declares --size is gone.
$button = $transform(
    '<style>.icon{--size:24px}.icon svg{width:var(--size);height:var(--size)}</style>'
    . '<div><a href="tel:123" class="cta">Call us'
    . '<span class="icon"><svg viewBox="0 0 200 200" width="200" height="200"><path d="M10 10H90V90H10z"></path></svg></span>'
    . '</a></div>'
);
$image = preg_match('/<img[^>]*>/', $button['blocks'], $match) ? $match[0] : '';

$assert('' !== $image, '1: the button icon materializes as an image', $button['blocks']);
$assert(
    str_contains($image, 'width:var(--size)') && str_contains($image, 'height:var(--size)'),
    '2: the author declaration is still carried onto the image',
    $image
);
$assert(
    str_contains($image, '--size:24px'),
    '3: the custom property the carried declaration reads is re-rooted on the image',
    $image
);

// The same leak through the generated geometry carrier rule.
$standalone = $transform(
    '<style>.wrap{--size:32px}.wrap svg{display:inline-block;width:var(--size);height:var(--size)}</style>'
    . '<div class="wrap"><svg viewBox="0 0 200 200" width="200" height="200"><path d="M10 10H90V90H10z"></path></svg></div>'
);
$carrier = '';
if ( preg_match('/[^{}]*\{[^{}]*width:var\(--size\)[^{}]*\}/', $standalone['css'], $match) ) {
    $carrier = $match[0];
}

$assert('' !== $carrier, '4: the standalone SVG carries its author media box into a generated rule', $standalone['css']);
$assert(
    str_contains($carrier, '--size:32px'),
    '5: the generated rule re-roots the custom property it reads',
    $carrier
);

// A quoted font stack resolved from a root-style custom property must remain a
// well-formed HTML attribute. Unescaped `"` would terminate `style="` early.
$quotedFont = $transform(
    '<style>:root{--font-mono:"JetBrains Mono", ui-monospace, monospace}.plan{width:100%;height:auto;display:block}</style>'
    . '<main><svg class="plan" viewBox="0 0 100 84" style="font-family: var(--font-mono);">'
    . '<rect width="100" height="84" fill="#E2E8F0"></rect>'
    . '<text x="50" y="42" text-anchor="middle">FR1</text>'
    . '</svg></main>'
);
$quotedFontImage = preg_match('/<img[^>]*>/', $quotedFont['blocks'], $quotedFontMatch) ? $quotedFontMatch[0] : '';
$assert(
    str_contains($quotedFont['blocks'], '<!-- wp:image') && ! str_contains($quotedFont['blocks'], '<!-- wp:html') && '' !== $quotedFontImage,
    '6: a resolved quoted font-family custom property stays on the native image path',
    $quotedFont['blocks']
);
$assert(
    str_contains($quotedFont['svg'], 'font-family:&quot;JetBrains Mono&quot;, ui-monospace, monospace')
        && preg_match('/<svg\b[^>]*\sstyle\s*=\s*"([^"]*)"/i', $quotedFont['svg'], $quotedFontStyle) === 1
        && str_contains($quotedFontStyle[1], 'JetBrains Mono')
        && ! str_contains($quotedFontStyle[1], '"')
        && ! str_contains($quotedFont['svg'], 'style="font-family:"'),
    '7: the materialized SVG style attribute remains well-formed around a quoted font family',
    $quotedFont['svg']
);

// Image-only author subjects must not acquire materialized SVGs as new
// matches. Exercise the public compiler with neutral button, RichText and
// standalone image shapes, including a real photo sharing the same wrapper.
foreach ( array(
    'button label' => array(
        '<div class="reading"><a class="action" href="/contact">Contact %s</a><img src="photo.webp"></div>',
        '.action{display:inline-flex;background:#123456;color:white;padding:16px;border-radius:4px}',
        '18', 'core/button',
    ),
    'RichText' => array(
        '<div class="reading"><p>Read %s more</p><img src="photo.webp"></div>',
        '', '18', 'core/paragraph',
    ),
    'photo and standalone icon' => array(
        '<div class="reading media"><img src="photo.webp"><div class="icon-wrap">%s</div></div>',
        '', '56', 'core/image',
    ),
) as $label => list($template, $extraCss, $size, $blockName) ) {
    $css = '.reading :where(img):not(:where([class~=unformatted] *)){margin-top:2em;margin-bottom:2em}'
        . '.media img{position:absolute!important;inset:0!important;width:100%!important;height:100%!important;object-fit:cover}'
        . '.source-glyph:hover{transform:scale(1.1)}' . $extraCss;
    $svg = '<svg class="source-glyph second-token" width="' . $size . '" height="' . $size . '"><path d="M0 0L10 10"/></svg>';
    $source = '<style>' . $css . '</style><main>' . sprintf($template, $svg) . '</main>';
    foreach ( array('HtmlTransformer', 'ArtifactCompiler') as $interface ) {
        $out = 'HtmlTransformer' === $interface
            ? (new HtmlTransformer())->transform($source)->toArray()
            : (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
        $context = $label . ' / ' . $interface;
        $assert(str_contains($out['serialized_blocks'], '<!-- wp:' . substr($blockName, 5)), $context . ': canonical block shape');
        $dom = new DOMDocument();
        @$dom->loadHTML($out['serialized_blocks']);
        $xpath = new DOMXPath($dom);
        $icon = $xpath->query('//img[contains(@src,"materialized-svg")]')->item(0);
        $photo = $xpath->query('//img[@src="photo.webp"]')->item(0);
        $assert($icon instanceof DOMElement && $photo instanceof DOMElement, $context . ': both images survive');
        if ( ! $icon instanceof DOMElement || ! $photo instanceof DOMElement ) continue;
        // Core/image's canonical save places source classes on its figure;
        // RichText places them directly on the image object.
        $carrier = str_contains($icon->getAttribute('class'), 'source-glyph') ? $icon : $icon->parentNode;
        $assert($carrier instanceof DOMElement && str_contains($carrier->getAttribute('class'), 'source-glyph') && str_contains($carrier->getAttribute('class'), 'second-token'), $context . ': source class list survives');
        $assert(str_contains($icon->getAttribute('style'), 'width:' . $size . 'px') && str_contains($icon->getAttribute('style'), 'height:' . $size . 'px'), $context . ': source dimensions survive');
        $matches = array('icon-margin' => false, 'icon-fill' => false, 'photo-margin' => false, 'photo-fill' => false, 'class-paint' => false);
        foreach ( $out['assets'] ?? array() as $asset ) {
            if ( 'css' !== ($asset['kind'] ?? '') ) continue;
            (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $selector, string $body) use (&$matches, $icon, $photo, $carrier): string {
                foreach ( CssValueSplitter::splitTopLevel($selector, array(',')) as $arm ) {
                    $parsed = CssSelectorMatcher::parse($arm);
                    foreach ( array('icon' => $icon, 'photo' => $photo) as $name => $element ) {
                        if ( ! CssSelectorMatcher::matches($element, $parsed, true)['matches'] ) continue;
                        if ( str_contains($body, 'margin-top:2em') ) $matches[$name . '-margin'] = true;
                        if ( str_contains($body, 'position:absolute!important') && str_contains($body, 'width:100%!important') ) $matches[$name . '-fill'] = true;
                    }
                    if ( $carrier instanceof DOMElement && str_contains($body, 'transform:scale(1.1)') && CssSelectorMatcher::matches($carrier, $parsed, true)['matches'] ) $matches['class-paint'] = true;
                }
                return $selector . '{' . $body . '}';
            });
        }
        $assert(! $matches['icon-margin'] && ! $matches['icon-fill'], $context . ': image-only margins and important fill exclude the SVG');
        $assert($matches['photo-margin'] && ('photo and standalone icon' !== $label || $matches['photo-fill']), $context . ': real photo keeps author image rules');
        $assert($matches['class-paint'], $context . ': class-subject hover rule still reaches the icon');
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "materialized svg custom property carry: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "materialized svg custom property carry: {$passes} passed" . PHP_EOL);
