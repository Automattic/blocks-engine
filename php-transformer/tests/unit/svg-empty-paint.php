<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'zero attribute' => array('stroke-width="0"', '', '', true),
    'zero inline px' => array('style="stroke-width:0px"', '', '', true),
    'visible stroke' => array('stroke-width="4"', '', '', false),
    'unmasked path' => array('stroke-width="0"', '<path d="M0 0H20V20Z"/>', '', false),
    'variable paint' => array('stroke-width="var(--width)"', '', '', false),
    'author zero' => array('class="mask-paint"', '', '.mask-paint{stroke-width:0px}', true),
    'author visible overrides attribute' => array('class="mask-paint" stroke-width="0"', '', '.mask-paint{stroke-width:4}', false),
    'transition' => array('style="stroke-width:0;transition:stroke-width 1s"', '', '', false),
    'animation' => array('class="mask-paint" stroke-width="0"', '', '.mask-paint{animation:reveal 1s}@keyframes reveal{to{stroke-width:4}}', false),
    'filter' => array('stroke-width="0" filter="url(#effect)"', '', '', false),
    'author filter' => array('class="mask-paint" stroke-width="0"', '', '.mask-paint{filter:blur(2px)}', false),
    'author transition' => array('class="mask-paint" stroke-width="0"', '', '.mask-paint{transition:stroke-width 1s}', false),
    'text' => array('stroke-width="0"', '<text>Label</text>', '', false),
    'foreign object' => array('stroke-width="0"', '<foreignObject><div>Label</div></foreignObject>', '', false),
    'external use' => array('stroke-width="0"', '<use href="/media/icon.svg#shape"/>', '', false),
    'transparent fill' => array('style="fill:transparent;stroke:none"', '', '', true),
    'zero opacity' => array('stroke-width="4" opacity="0"', '', '', true),
    'in flow' => array('stroke-width="0"', '', '', false, ''),
    'author positioned' => array('stroke-width="0"', '', '.overlay{position:absolute}', true, 'class="overlay"'),
);
foreach ($cases as $label => $case) {
    [$paint, $extra, $css, $omitted] = $case;
    $box = $case[4] ?? 'style="position:absolute;inset:0;pointer-events:none"';
    $svg = '<svg viewBox="0 0 20 20" ' . $box . '><defs><mask id="Mask"><path d="M0 0H20V20Z" fill="none" stroke="#fff" ' . $paint . '/></mask></defs><image href="/media/x.jpg" width="20" height="20" mask="url(#Mask)"/>' . $extra . '</svg>';
    $result = (new HtmlTransformer())->transform('<html><head><style>' . $css . '</style></head><body><main><h1>Gallery</h1>' . $svg . '</main></body></html>')->toArray();
    $diagnostics = array_values(array_filter($result['diagnostics'] ?? array(), static fn(array $row): bool => 'html_empty_svg_decoration_omitted' === ($row['code'] ?? '')));
    $markup = $result['serialized_blocks'] ?? '';
    $check = count($diagnostics) === ($omitted ? 1 : 0);
    if ($omitted) {
        $check = $check && !str_contains($markup, 'wp:html') && !str_contains($markup, '<svg') && empty($result['fallbacks'])
            && 'info' === $diagnostics[0]['severity'] && 'native_conversion' === $diagnostics[0]['loss_class'];
    } else {
        $check = $check && str_contains($markup, '/media/x.jpg');
    }
    if (!$check) {
        fwrite(STDERR, "FAIL: $label\n" . json_encode($result['diagnostics']) . "\n" . $markup . "\n");
        exit(1);
    }
}
echo "SVG empty paint cases passed.\n";
