<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Reduction of the declared source: a link and a separate glyph wrapper,
// with the submenu beside them inside a label. The source's 25px intrinsic
// box is 10px of glyph + 13px of glyph margins + 2px of container border.
$page = static function (bool $explicit): string {
    return '<!doctype html><html><head><style>'
        . '.menu{display:flex;justify-content:flex-end;gap:32px}.trigger{display:flex;align-items:center}'
        . '.glyph-box{margin-left:8px;border:1px solid transparent;display:flex}'
        . ($explicit ? '.glyph-box{width:25px;height:13px;box-sizing:border-box}' : '')
        . '.glyph{margin:2px 6.5px}.panel{display:none}'
        . '</style></head><body><header><nav><ul class="menu"><li><label>'
        . '<input type="checkbox"><div class="trigger"><a href="/services">Services</a>'
        . '<div class="glyph-wrapper"><span class="glyph-box"><svg class="glyph" width="10" height="7" viewBox="0 0 10 7"><path d="M0 0L5 7L10 0"/></svg></span></div></div>'
        . '<div class="panel"><ul><li><a href="/services/one">First service</a></li></ul></div>'
        . '</label></li><li><a href="/about">About</a></li></ul></nav></header></body></html>';
};
foreach (array(false, true) as $explicit) {
    $result = (new HtmlTransformer())->transform($page($explicit))->toArray();
    $markup = $result['serialized_blocks'] ?? '';
    $css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
    $assert(str_contains($markup, 'wp:navigation-submenu'), 'The dropdown remains a native submenu.');
    $assert(1 === preg_match('/"className":"[^"]*(blocks-engine-submenu-icon-[a-f0-9]+)/', $markup, $match), 'The submenu carries a source-geometry marker.');
    $marker = $match[1];
    $assert(1 === preg_match('/([^{}]*' . $marker . '[^{}]*>\\.wp-block-navigation__submenu-icon)\\{([^}]+)\\}/', $css, $toggle), 'Scoped CSS reaches core\'s toggle.');
    $assert(str_contains($toggle[1], '.wp-block-navigation ') && str_contains($toggle[1], ':not(.is-menu-open *)'), 'Geometry is navigation-scoped and excludes the mobile overlay.');
    $assert(str_contains($toggle[2], 'margin-left:8px;'), 'The source label-to-trigger gap replaces core\'s .25em margin.');
    $assert(str_contains($toggle[2], 'width:' . ($explicit ? '25px' : 'auto') . ';'), 'The toggle retains the explicit or intrinsic source box.');
    $assert(str_contains($toggle[2], 'border:1px solid transparent;'), 'The intrinsic box includes source borders.');
    $assert(1 === preg_match('/' . $marker . '[^{}]*>\\.wp-block-navigation__submenu-icon>svg\\{([^}]+)\\}/', $css, $glyph), 'The glyph is styled separately from its box.');
    foreach (array('width:10px;', 'height:7px;', 'margin:2px 6.5px;') as $declaration) {
        $assert(str_contains($glyph[1], $declaration), 'Source glyph geometry survives: ' . $declaration);
    }
    $assert(!str_contains($markup, '<svg') && !str_contains($markup, 'glyph-box'), 'No source icon markup is added to core navigation.');
}

echo "navigation submenu icon geometry contract passed\n";
