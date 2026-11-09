<?php
declare(strict_types=1);

/**
 * A breakpoint rule that hides one direct navigation link hides its rendered
 * navigation item (#5255).
 *
 * In the source the link is the `<nav>` flex item. core/navigation renders it
 * inside `li.wp-block-navigation-item`, the flex item in WordPress, and Core's
 * `.wp-block-navigation .wp-block-navigation-item{display:flex}` and
 * `.wp-block-navigation .wp-block-navigation-item__content{display:block}`
 * (0,2,0) outranked the projected anchor rule (0,1,1): the link stayed
 * visible, and hiding only the anchor would still leave an empty item taking
 * the row gap. The rule's box generation has to land on the item, above
 * Core's item defaults, under the same breakpoint.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

// The reduced page from the issue.
$result = ( new HtmlTransformer() )->transform(
    '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nav hide item</title><style>'
    . 'header{display:flex;justify-content:space-between;align-items:center;padding:20px}'
    . 'nav{display:flex;gap:13px}'
    . '@media(max-width:600px){nav a:nth-child(2){display:none}}'
    . '</style></head><body><header><a href="#">Brand</a><nav><a href="#one">One</a><a href="#two">Two</a><a href="#three">Three</a></nav></header><main><p>Body</p></main></body></html>'
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
preg_match('/<!-- wp:navigation \{.*?(blocks-engine-source-nav-[A-Za-z0-9-]+)/', $markup, $marker);
$assert(isset($marker[1]) && 3 === substr_count($markup, '<!-- wp:navigation-link '), 'the menu becomes a core/navigation with one navigation-link per source link');

$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( 'css' === ($asset['kind'] ?? '') && ! str_contains((string) ($asset['path'] ?? ''), 'engine-support') ) {
        $css .= (string) ($asset['content'] ?? '');
    }
}

// Core's rendering of the menu.
$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML(
    '<!DOCTYPE html><html><body><header class="wp-block-group">'
    . '<nav class="wp-block-navigation ' . ($marker[1] ?? '') . ' is-layout-flex"><ul class="wp-block-navigation__container">'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#one"><span class="wp-block-navigation-item__label">One</span></a></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#two"><span class="wp-block-navigation-item__label">Two</span></a></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#three"><span class="wp-block-navigation-item__label">Three</span></a></li>'
    . '</ul></nav></header></body></html>'
);
libxml_clear_errors();
libxml_use_internal_errors(false);

$core = CssSelectorMatcher::specificity(CssSelectorMatcher::parse('.wp-block-navigation .wp-block-navigation-item'));
$hidden = array();
( new CssStylesheetTransformer() )->visitStyleRules($css, static function (string $prelude, string $body, array $conditions) use ($document, $core, &$hidden): void {
    if ( ! str_contains($body, 'display:none') || array( '@media(max-width:600px)' ) !== array_map(static fn ($condition): string => str_replace(' ', '', (string) $condition), $conditions) ) {
        return;
    }
    foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
        $parsed = CssSelectorMatcher::parse(trim($selector));
        if ( ! $parsed['supported'] || CssSelectorMatcher::specificity($parsed) <= $core ) {
            continue;
        }
        foreach ( $document->getElementsByTagName('li') as $item ) {
            $match = CssSelectorMatcher::matches($item, $parsed, true);
            if ( $match['supported'] && $match['matches'] ) {
                $hidden[] = trim($item->textContent);
            }
        }
    }
});
$assert(array( 'Two' ) === array_values(array_unique($hidden)), 'at the breakpoint only the second rendered item is hidden, above Core\'s item display: ' . json_encode($hidden));

if ( $failures > 0 ) {
    exit(1);
}
echo 'navigation-item-breakpoint-display: passed' . PHP_EOL;
