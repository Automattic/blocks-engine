<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array('request-identity', 'width') as $selection) {
    $selected = 'data-blocks-engine-capture-selected-document';
    $visibility = 'request-identity' === $selection
        ? '[data-blocks-engine-capture-device-document="mobile"]{display:none}html[' . $selected . '="mobile"] [data-blocks-engine-capture-device-document="mobile"]{display:block}html[' . $selected . '="mobile"] [data-blocks-engine-capture-device-hide~="mobile"]{display:none!important}'
        : '@media(max-width:480px){[data-blocks-engine-capture-device-hide~="mobile"]{display:none!important}}';
    $html = '<!doctype html><html><head><title>Device menus</title><style data-blocks-engine-capture-device-visibility="">' . $visibility . '</style>'
        . ('request-identity' === $selection ? '<script data-blocks-engine-capture-device-selection="">document.documentElement.setAttribute("' . $selected . '", /Mobile/.test(navigator.userAgent) ? "mobile" : "desktop");</script>' : '')
        . '</head><body><div data-blocks-engine-capture-document-scope="" data-blocks-engine-capture-device-document="desktop"><nav data-blocks-engine-capture-device-hide="mobile"><a href="/one/">One</a><a href="/two/">Two</a></nav><h1>Desktop</h1></div>'
        . '<div data-blocks-engine-capture-document-scope="" data-blocks-engine-capture-device-document="mobile"><h1>Phone</h1></div></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) $result['serialized_blocks'];
    $assert(1 === preg_match('/<!-- wp:navigation (\{.*?\}) (?:\/)?-->/s', $markup, $match), $selection . ': menu lowers to navigation');
    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    $viewport = $attrs['metadata']['blockVisibility']['viewport'] ?? null;
    if ('width' === $selection) {
        $assert(array('mobile' => false) === $viewport, 'Width selection retains core viewport visibility');
    } else {
        $assert(null === $viewport, 'Request-identity selection must not acquire width-based blockVisibility.viewport');
        $class = 'blocks-engine-device-hide-mobile';
        $assert(str_contains($attrs['className'] ?? '', $class), 'Navigation carries the selected-document class');
        $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $selectors = array();
        foreach ($rules as $rule) {
            if (!str_contains($rule[1], '.' . $class)) continue;
            $assert(str_contains($rule[2], 'display:none!important'), 'Selected-document rule hides the menu');
            foreach (explode(',', $rule[1]) as $selector) {
                if (str_contains($selector, '.' . $class)) $selectors[] = trim($selector);
            }
        }
        $assert(array('html[' . $selected . '="mobile"] .' . $class) === array_values(array_unique($selectors)), 'Only the mobile document selection hides the menu, never an unselected 390px viewport');
    }
    echo $selection . ': navigation visibility contract passed' . PHP_EOL;
}
