<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) throw new RuntimeException($message);
};

$details = (new HtmlTransformer())->transform('<details><summary>More</summary><object data="/more.pdf"></object></details>')->toArray();
$assert('core/details' === ($details['blocks'][0]['blockName'] ?? null), 'Native details remains ahead of generic container lowering.');
$assert('html_unsupported_element' === ($details['fallbacks'][0]['diagnostic_code'] ?? null), 'Details commits child fallback diagnostics through the registry result.');

$button = (new HtmlTransformer())->transform('<a href="/go" aria-label="Open" style="display:inline-block;background:#000;color:#fff;padding:1rem">Go</a>')->toArray();
$assert('core/html' === ($button['blocks'][0]['blockName'] ?? null), 'Button recognition remains ahead of generic anchor lowering when its accessible-name fallback wins.');
$assert('html_stylable_button_accessible_name_fallback' === ($button['fallbacks'][0]['diagnostic_code'] ?? null), 'Button fallback is committed by the staged registry dispatcher.');

$quoteWithNavigation = (new HtmlTransformer())->transform('<blockquote><nav><a href="/one">One</a><a href="/two">Two</a></nav></blockquote>')->toArray();
$assert('core/quote' === ($quoteWithNavigation['blocks'][0]['blockName'] ?? null), 'Quote child lowering does not re-enter unrelated registry recognizers through the navigation probe.');

echo "pattern registry staged dispatch passed ({$assertions} assertions)\n";
