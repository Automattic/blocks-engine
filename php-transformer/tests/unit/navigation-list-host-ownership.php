<?php
declare(strict_types=1);

$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
$root = getenv('BE_TRANSFORMER_ROOT');
if (is_string($root) && '' !== $root) $loader->addPsr4('Automattic\\BlocksEngine\\PhpTransformer\\', $root . '/src', true);

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function(bool $value, string $message) use (&$failures): void { if (!$value) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); } };
$flatten = static function(array $blocks) use (&$flatten): array {
    $result = array();
    foreach ($blocks as $block) { $result[] = $block; array_push($result, ...$flatten($block['innerBlocks'] ?? array())); }
    return $result;
};
$matches = static fn(string $selector, DOMElement $element): bool => (bool) (CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector))['matches'] ?? false);
foreach (array('DIV' => 'navigation-list-host-ownership.php', 'UL' => 'navigation-list-panel-ownership.php') as $kind => $fixture) {
    $source = require dirname(__DIR__) . '/fixtures/' . $fixture;
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $navigations = array_values(array_filter($flatten($result['blocks'] ?? array()), static fn(array $block): bool => 'core/navigation' === ($block['blockName'] ?? '')));
    $control = array_values(array_filter($navigations, static fn(array $block): bool => isset($block['attrs']['metadata']['blocksEngineNavigationOpener'])))[0];
    $list = array_values(array_filter($navigations, static fn(array $block): bool => 'placed-menu' === ($block['attrs']['anchor'] ?? '')))[0];
    $dom = new DOMDocument();
    $dom->loadHTML('<nav id="control" class="wp-block-navigation ' . htmlspecialchars($control['attrs']['className'] ?? '') . '"><ul id="row" class="wp-block-navigation wp-block-navigation__container ' . htmlspecialchars($control['attrs']['className'] ?? '') . '"></ul></nav><nav id="placed-menu" class="wp-block-navigation ' . htmlspecialchars($list['attrs']['className'] ?? '') . '"></nav>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $nodes = array();
    foreach ($dom->getElementsByTagName('*') as $node) if ($node->hasAttribute('id')) $nodes[$node->getAttribute('id')] = $node;
    $marginRules = array();
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'] ?? '', static function(string $selector, string $body, array $conditions) use (&$marginRules): void {
            if (preg_match('/margin-bottom\s*:\s*(13|23)px\b/', $body, $match)) $marginRules[] = array('selector' => $selector, 'value' => $match[1], 'conditions' => $conditions);
        });
    }
    $assert(2 === count($marginRules), $kind . ': base and tablet list-margin facts both survive');
    foreach ($marginRules as $rule) {
        $assert(!$matches($rule['selector'], $nodes['control']), $kind . ': source list type cannot claim a control-owned NAV root');
        $assert($matches($rule['selector'], $nodes['placed-menu']), $kind . ': genuine UL-owned native root keeps the authored list rule');
        $assert(('UL' === $kind) === $matches($rule['selector'], $nodes['row']), $kind . ': only a real source UL panel owns its native inner list');
        if ('23' === $rule['value']) $assert(array() !== $rule['conditions'], $kind . ': tablet fact stays inside its source query');
    }
}
if ($failures) exit(1);
echo "Source list/control ownership contract passed\n";
