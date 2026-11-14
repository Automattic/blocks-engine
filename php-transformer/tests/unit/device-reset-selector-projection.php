<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$selectors = array();
foreach (array(40, 400) as $count) {
    $widths = '';
    $items = '';
    for ($i = 1; $i <= $count; ++$i) {
        $widths .= '#c' . $i . '{width:' . (100 + $i) . 'px}';
        $items .= '<div id="c' . $i . '"><span>Item ' . $i . '</span></div>';
    }
    $css = ':where([data-blocks-engine-capture-device-document="desktop"]) div{margin:0;padding:0}' . $widths;
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>CSS growth repro</title><style>' . $css . '</style></head><body><div data-blocks-engine-capture-device-document="desktop">' . $items . '</div></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
    $projected = '';
    foreach ($result['assets'] as $asset) {
        if (str_starts_with($asset['path'], 'inline/')) $projected .= $asset['content'];
    }
    $reset = array();
    (new CssStylesheetTransformer())->visitStyleRules($projected, static function (string $selector, string $body) use (&$reset): void {
        if (str_contains($body, 'padding:0')) $reset[] = $selector;
    });
    printf("%d elements: %d source CSS bytes, %d projected CSS bytes\n", $count, strlen($css), strlen($projected));
    if (1 !== count($reset) || !str_contains($reset[0], '[data-be-d="desktop"]') || str_contains($reset[0], '#c')) {
        fwrite(STDERR, "Reset must remain one live device-scoped rule\n");
        exit(1);
    }
    $document = new DOMDocument();
    @$document->loadHTML('<html><body data-be-d="desktop">' . (string) $result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] . '</body></html>');
    $cascade = new StaticCssCascade($document, $projected);
    for ($i = 1; $i <= $count; ++$i) {
        $element = $document->getElementById('c' . $i);
        $style = $element instanceof DOMElement ? $cascade->resolve($element, array('margin', 'padding'), array()) : array();
        if ('0' !== ($style['margin'] ?? '') || '0' !== ($style['padding'] ?? '')) {
            fwrite(STDERR, "Reset no longer reaches c{$i}\n");
            exit(1);
        }
    }
    $selectors[] = $reset[0];
}
// Descendant universal subjects and legacy pseudo-elements use the same
// primitive; neither may turn the device gate into an identity list.
foreach (array('*', ':after') as $subject) {
    $shapes = array();
    foreach (array(40, 400) as $count) {
        $css = ':where([data-blocks-engine-capture-device-document="desktop"]) .a .b ' . $subject . '{box-sizing:inherit}';
        $html = '<style>' . $css . '</style><div data-blocks-engine-capture-device-document="desktop"><div class="a"><div class="b">'
            . str_repeat('<div><span>Item</span></div>', $count) . '</div></div></div>';
        $result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
        $rules = array();
        foreach ($result['assets'] as $asset) {
            if (!str_starts_with($asset['path'], 'inline/')) continue;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $selector, string $body) use (&$rules): void {
                if (str_contains($body, 'box-sizing:inherit')) $rules[] = $selector;
            });
        }
        if (1 !== count($rules) || !str_contains($rules[0], '.a .b ' . (':after' === $subject ? '*:after' : $subject))) {
            fwrite(STDERR, "Universal reset must retain its descendant shape: " . json_encode($rules) . "\n");
            exit(1);
        }
        $shapes[] = $rules;
    }
    if ($shapes[0] !== $shapes[1]) {
        fwrite(STDERR, "Universal reset selector must not grow with element count\n");
        exit(1);
    }
}
if ($selectors[0] !== $selectors[1]) {
    fwrite(STDERR, "Reset selector must not grow with element count\n");
    exit(1);
}
