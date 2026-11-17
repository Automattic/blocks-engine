<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A builder can split one control's chrome by viewport: the unconditional
// stylesheet only sets `border-style`, while the fill (and border width) is
// authored once per width range. Every viewport renders a filled button, so
// outline classification must read the fill that paints at the reference
// viewport, not just the unconditional rules. Otherwise the button is classed
// as an outline and a forced transparent native background hides the author
// fill that the projected conditional rules would have painted.
$conditional = static fn (string $media, string $paint): string => '@media ' . $media . '{.page .cta{' . $paint . '}}';
foreach (array(
    'media rules' => array(
        '.cta{border-style:solid;padding:10px;display:inline-block}'
        . $conditional('(min-width:768px)', 'background-color:var(--cta-bg);border-width:var(--cta-border-width);border-color:var(--cta-bg)')
        . $conditional('(max-width:767px)', 'background-color:var(--cta-bg);border-width:var(--cta-border-width);border-color:var(--cta-bg)'),
        false,
    ),
    'conditional transparent fill' => array(
        '.cta{border-style:solid;padding:10px;display:inline-block}'
        . $conditional('(min-width:768px)', 'background-color:transparent;border-width:1px;border-color:#123')
        . $conditional('(max-width:767px)', 'background-color:transparent;border-width:1px;border-color:#123'),
        true,
    ),
) as $label => [$css, $outline]) {
    $html = '<!doctype html><html><head><title>T</title><style>:root{--cta-bg:rgba(29,126,25,1);--cta-border-width:0}</style>'
        . '<link rel="stylesheet" href="/s.css"></head><body><main class="page"><p>Intro</p>'
        . '<a class="cta" href="/next/"><span class="label">learn more</span></a></main></body></html>';
    $markup = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 's.css' => $css)))
        ->toArray()['serialized_blocks'];
    if (1 !== preg_match('/<!-- wp:button (\{.*?\}) -->/', $markup, $matches)) {
        throw new RuntimeException($label . ': the control must lower to core/button.');
    }
    $attrs = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    if ($outline !== str_contains((string) ($attrs['className'] ?? ''), 'is-style-outline')) {
        throw new RuntimeException($label . ': outline classification must follow the fill painting at the reference viewport.');
    }
    if ($outline !== ('transparent' === ($attrs['style']['color']['background'] ?? null))) {
        throw new RuntimeException($label . ': only an outline may acquire forced transparent native paint.');
    }
}
echo "Button outline conditional fill contract passed.\n";
