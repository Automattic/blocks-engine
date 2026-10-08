<?php
declare(strict_types=1);

/**
 * Presentation whose at-rest box is collapsed inside an inline control must not
 * become visible content when the control lowers to core/button. A hover reveal
 * is not the resting state.
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

$svg = '<svg viewBox="0 0 200 200" width="200" height="200"><path d="M0 0h1v1"/></svg>';

$transform = static function (string $style, string $icon) use ($svg): array {
    return ( new HtmlTransformer() )->transform(
        '<style>.button{display:inline-flex;align-items:center;padding:10px 18px;background:#173b64;color:#fff}' . $style . '</style>'
        . '<a href="#contact" class="button"><span class="label">Pre-order now</span>' . $icon . '</a>'
    )->toArray();
};

$assertCollapsed = static function (string $name, array $result) use ($assert): void {
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $button = $result['blocks'][0]['innerBlocks'][0] ?? array();
    $assert('core/button' === ($button['blockName'] ?? null), $name . ': the link lowers to core/button', $markup);
    $assert(str_contains($markup, 'Pre-order now'), $name . ': the at-rest label remains', $markup);
    $assert(! str_contains($markup, '<img'), $name . ': collapsed presentation is not emitted as a visible image', $markup);
    $assert(! str_contains($markup, '<!-- wp:html'), $name . ': the control does not fall back to HTML', $markup);
};

$assertCollapsed(
    'zero-width wrapper',
    $transform(
        '.reveal{width:0;overflow:hidden}.button:hover .reveal{width:16px}.reveal svg{width:16px;height:16px}',
        '<div class="reveal"><div>' . $svg . '</div></div>'
    )
);

$assertCollapsed(
    'zero-height wrapper',
    $transform(
        '.reveal{height:0;overflow:hidden}.button:hover .reveal{height:16px}.reveal svg{width:16px;height:16px}',
        '<div class="reveal"><div>' . $svg . '</div></div>'
    )
);

$assertCollapsed(
    'captured zero-width box',
    $transform(
        '.reveal svg{width:16px;height:16px}',
        '<div class="reveal" data-source-visual-width="0" data-source-visual-height="16"><div>' . $svg . '</div></div>'
    )
);

$assertCollapsed(
    'display none',
    $transform(
        '.reveal{display:none}.button:hover .reveal{display:inline-block}',
        '<div class="reveal">' . $svg . '</div>'
    )
);

$assertCollapsed(
    'visibility hidden',
    $transform(
        '.reveal{visibility:hidden}.button:hover .reveal{visibility:visible}',
        '<div class="reveal">' . $svg . '</div>'
    )
);

$visible = $transform(
    '.reveal{width:16px;height:16px}.reveal svg{width:16px;height:16px}',
    '<div class="reveal">' . $svg . '</div>'
);
$visibleMarkup = (string) ($visible['serialized_blocks'] ?? '');
$assert(
    str_contains($visibleMarkup, '<img') && str_contains($visibleMarkup, 'Pre-order now'),
    'a resting icon with a positive box stays in the button',
    $visibleMarkup
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "collapsed control presentation: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "collapsed control presentation: {$passes} passed" . PHP_EOL);
