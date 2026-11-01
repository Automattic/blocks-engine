<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform((string) file_get_contents(dirname(__DIR__) . '/fixtures/button-custom-property-chain.html'))->toArray();
$buttons = array();
$walk = static function (array $blocks) use (&$walk, &$buttons): void {
    foreach ($blocks as $block) {
        if ('core/button' === $block['blockName']) $buttons[] = $block;
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk($result['blocks']);
$style = $buttons[0]['attrs']['style'] ?? array();
echo json_encode($style, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (1 !== count($buttons)
    || '#cacaca' !== ($style['color']['background'] ?? null)
    || '#5b5b5b' !== ($style['color']['text'] ?? null)
    || '21px' !== ($style['border']['radius'] ?? null)
    || '7.5px' !== ($style['spacing']['padding']['top'] ?? null)
) {
    fwrite(STDERR, "FAIL: reduced button must retain its source paint and spacing\n");
    exit(1);
}
// An unknown token must not poison otherwise resolvable button paint.
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-custom-property-chain.html');
$unknown = (new HtmlTransformer())->transform(str_replace('border-radius: 21px', 'border-radius: var(--missing)', $html))->toArray();
$buttons = array();
$walk($unknown['blocks']);
$unknownStyle = $buttons[0]['attrs']['style'] ?? array();
if (isset($unknownStyle['border']['radius']) || '#cacaca' !== ($unknownStyle['color']['background'] ?? null)
    || !str_contains(json_encode($unknown), 'html_unresolved_native_style')) {
    fwrite(STDERR, "FAIL: unresolved radius must stay CSS-owned with a refusal, without losing resolved paint\n");
    exit(1);
}

// Absolute calc() padding cannot acquire a dependency on the source root.
$root = (new HtmlTransformer())->transform(str_replace(
    array('.button {', 'padding: 7.5px'),
    array('html { font-size: 62.5%; } .button {', 'padding: calc(2.5px * 3)'),
    $html
))->toArray();
$buttons = array();
$walk($root['blocks']);
if ('7.5px' !== ($buttons[0]['attrs']['style']['spacing']['padding']['top'] ?? null)) {
    fwrite(STDERR, "FAIL: absolute padding must retain 7.5px at a 10px root\n");
    exit(1);
}
echo "PASS: reduced button paint, unresolved refusal, and source-root-independent spacing\n";
