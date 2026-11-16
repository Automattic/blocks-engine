<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>.mainnav_menu > ul > li:not(:last-child){margin:0 20px}'
    . '.mainnav_menu > ul > li:last-child{margin-left:20px}</style>'
    . '<nav class="mainnav_menu"><ul><li><a href="/one">One</a></li>'
    . '<li><a href="/two">Two</a></li><li><a href="/three">Three</a></li></ul></nav>'
)->toArray();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};
$assert(str_contains($result['serialized_blocks'], '<!-- wp:navigation '), 'the carrier compiles to navigation');
$assert(3 === substr_count($result['serialized_blocks'], '<!-- wp:navigation-link '), 'all links compile to navigation items');

// Model Core's rendered responsive wrappers, as the structural-pseudo tests do.
// A literal carrier > ul hop cannot reach this container.
$dom = new DOMDocument();
$dom->loadHTML('<!DOCTYPE html><html><body><nav class="mainnav_menu">'
    . '<nav class="wp-block-navigation"><div class="wp-block-navigation__responsive-container">'
    . '<div class="wp-block-navigation__responsive-close"><div class="wp-block-navigation__responsive-dialog">'
    . '<div class="wp-block-navigation__responsive-container-content"><ul class="wp-block-navigation__container">'
    . '<li class="wp-block-navigation-item">One</li><li class="wp-block-navigation-item">Two</li>'
    . '<li class="wp-block-navigation-item">Three</li></ul></div></div></div></div></nav></nav></body></html>');
$reached = array('not-last' => array(), 'last' => array());
$projected = array();
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '') || str_contains($asset['path'] ?? '', 'engine-support')) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'] ?? '', static function (string $selector, string $body) use (&$reached, &$projected, $dom, $assert): void {
        if (!str_contains($selector, '.wp-block-navigation-item') || !str_contains($body, '20px')) return;
        foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array($selector) as $single) {
            $key = str_contains($single, ':not(:last-child)') ? 'not-last' : 'last';
            $parsed = CssSelectorMatcher::parse($single);
            $assert($parsed['supported'], 'projected selector is supported: ' . $single);
            $projected[$key] = $single;
            foreach ($dom->getElementsByTagName('li') as $index => $item) {
                if (CssSelectorMatcher::matches($item, $parsed, true)['matches']) $reached[$key][$index] = true;
            }
        }
    });
}
$assert(array(0, 1) === array_keys($reached['not-last']), 'non-last items retain their margins: ' . json_encode($projected));
$assert(array(2) === array_keys($reached['last']), 'only the last item gets the last-child margin');
foreach ($projected as $selector) {
    $assert(str_contains($selector, ':where(.wp-block-navigation__container):not(be-t-0)'), 'the list mapping adds no class specificity and retains type specificity');
    $assert((bool) preg_match('/navigation__container\):not\(be-t-0\)\s*>\s*\.wp-block-navigation-item/', $selector), 'items remain direct children of the container');
}
echo "navigation-carrier-child-list: passed\n";
