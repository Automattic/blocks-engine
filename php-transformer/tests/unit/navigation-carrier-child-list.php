<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};
foreach (array('carrier' => 'nav', 'list' => 'div') as $owner => $tag) {
    $result = (new HtmlTransformer())->transform(
        '<style>.mainnav_menu > ul{display:flex;list-style:none}'
        . '.mainnav_menu > ul > li:not(:last-child){margin:0 20px}'
        . '.mainnav_menu > ul > li:last-child{margin-left:20px}</style>'
        . '<' . $tag . ' class="mainnav_menu">' . ('list' === $owner ? '<img src="/logo.png" alt="Logo" width="243" height="56">' : '') . '<ul id="menu"><li><a href="/one">One</a></li>'
        . '<li><a href="/two">Two</a></li><li><a href="/three">Three</a></li></ul></' . $tag . '>'
    )->toArray();
    $assert(str_contains($result['serialized_blocks'], '<!-- wp:navigation '), $owner . ': source compiles to navigation');
    $assert(3 === substr_count($result['serialized_blocks'], '<!-- wp:navigation-link '), $owner . ': all links compile to navigation items');
    $navigation = null;
    $findNavigation = static function (array $blocks) use (&$findNavigation, &$navigation): void {
        foreach ($blocks as $block) {
            if ('core/navigation' === ($block['blockName'] ?? '')) $navigation = $block;
            $findNavigation($block['innerBlocks'] ?? array());
        }
    };
    $findNavigation($result['blocks']);
    $assert(null !== $navigation, $owner . ': navigation block is available');

    // Model Core's responsive wrappers and semantic identity for either owner.
    // A direct navigation > container hop cannot reach the root item list.
    $dom = new DOMDocument();
    $dom->loadHTML('<!DOCTYPE html><html><body><' . $tag . ' class="mainnav_menu">'
        . '<nav class="wp-block-navigation ' . htmlspecialchars($navigation['attrs']['className'] ?? '') . '">'
        . '<div class="wp-block-navigation__responsive-container">'
        . '<div class="wp-block-navigation__responsive-close"><div class="wp-block-navigation__responsive-dialog">'
        . '<div class="wp-block-navigation__responsive-container-content"><ul class="wp-block-navigation__container">'
        . '<li class="wp-block-navigation-item">One</li><li class="wp-block-navigation-item">Two</li>'
        . '<li class="wp-block-navigation-item">Three<ul class="wp-block-navigation__submenu-container">'
        . '<li class="wp-block-navigation-item">Sub one</li><li class="wp-block-navigation-item">Sub two</li>'
        . '</ul></li></ul></div></div></div></div></nav></' . $tag . '></body></html>');
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
    $assert(array(0, 1) === array_keys($reached['not-last']), $owner . ': only root non-last items retain their margins: ' . json_encode($projected));
    $assert(array(2) === array_keys($reached['last']), $owner . ': only the root last item gets the last-child margin');
    foreach ($projected as $selector) {
        $list = 'carrier' === $owner ? ':where(.wp-block-navigation__container):not(be-t-0)' : ':where(.wp-block-navigation__container)';
        $assert(str_contains($selector, $list), $owner . ': the container mapping adds no class specificity');
        $assert(str_contains($selector, ':not(be-t-0)'), $owner . ': source type specificity is retained');
        $assert((bool) preg_match('/navigation__container\)(?::not\(be-t-0\))?\s*>\s*\.wp-block-navigation-item/', $selector), $owner . ': items remain direct children of the container');
    }
}
echo "navigation-carrier-child-list: passed\n";
