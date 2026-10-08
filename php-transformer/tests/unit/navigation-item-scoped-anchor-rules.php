<?php
declare(strict_types=1);

/**
 * `li.item .link` rules follow the anchor class onto the rendered anchor.
 *
 * core/navigation-link puts the source anchor's class on its `<li>` next to
 * the source item's class, and renders its own
 * `.wp-block-navigation-item__content` anchor. A rule that reached the source
 * anchor through its own list item (`.navigation-item .item-name`) then needs
 * an `.item-name` descendant of `.navigation-item`, which no longer exists, so
 * the menu links lost their padding and ran together. The item compound folds
 * into the projected item selector.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$assets = static function (string $css, string $items): array {
    $source = '<style>' . $css . '</style><header><nav class="navigation-body"><ul class="navigation-list">' . $items . '</ul></nav></header><main><p>Body</p></main>';
    $result = ( new HtmlTransformer() )->transform(
        $source,
        array()
    )->toArray();

    $candidateCss = implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        is_array($result['assets'] ?? null) ? $result['assets'] : array()
    ));
    $rows = array('css' => $candidateCss);
    foreach (array('source' => $source, 'native' => StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($result['serialized_blocks'])) as $kind => $html) {
        $dom = new DOMDocument(); $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $cascade = new StaticCssCascade($dom, 'native' === $kind ? $candidateCss : '');
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $values = $cascade->resolve($anchor, array('padding', 'display'), array());
            $values['display'] ??= 'inline';
            ksort($values);
            $rows[$kind][trim($anchor->textContent ?? '')] = $values;
        }
    }
    return $rows;
};

$items = '<li class="navigation-item"><a href="/" class="item-name">Home</a></li>'
    . '<li class="navigation-item"><a href="/about" class="item-name">About</a></li>'
    . '<li class="navigation-item"><a href="/contact" class="item-name">Contact</a></li>';

// BaseKit: the item-scoped rule beats a later bare anchor-class rule.
$basekit = $assets(
    '.navigation-item .item-name{display:block;padding:14px}'
    . '.navigation-item .item-name{padding:5px 15px}'
    . '.item-name{padding:16px 20px}',
    $items
);
$assert(
    '5px 15px' === ($basekit['native']['Home']['padding'] ?? ''),
    'the winning item-scoped padding lands on the rendered anchor',
    json_encode($basekit)
);
$assert(
    'block' === ($basekit['native']['Home']['display'] ?? ''),
    'the item-scoped display lands on the rendered anchor',
    json_encode($basekit)
);
$assert(
    $basekit['source'] === $basekit['native'],
    'losing padding declarations are not promoted over the source winner',
    json_encode($basekit)
);

$hostScoped = $assets(
    '.navigation-body .navigation-item .item-name{padding:5px 15px}',
    $items
);
$assert(
    $hostScoped['source'] === $hostScoped['native'] && '5px 15px' === ($hostScoped['native']['Contact']['padding'] ?? ''),
    'a navigation-host ancestor stays in front of the folded item compound',
    json_encode($hostScoped)
);

$outerItem = $assets(
    '.navigation-item .item-name{padding:5px 15px}',
    '<li class="navigation-item"><a href="/" class="item-name">Home</a>'
    . '<ul><li class="sub-item"><a href="/team" class="item-name">Team</a></li></ul></li>'
    . '<li class="navigation-item"><a href="/about" class="item-name">About</a></li>'
);
$assert(
    !str_contains($outerItem['css'], '.navigation-item.item-name>.wp-block-navigation-item__content{padding'),
    'an item compound a nested anchor only reaches through its outer item is not folded',
    json_encode($outerItem)
);

$foreignAncestor = $assets(
    '.site-footer .item-name{padding:5px 15px}',
    $items
);
$assert(
    $foreignAncestor['source'] === $foreignAncestor['native'] && !isset($foreignAncestor['native']['Home']['padding']),
    'an ancestor that is neither the item nor a navigation host still fails closed',
    json_encode($foreignAncestor)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation item-scoped anchor rules FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "navigation item-scoped anchor rules passed: {$passes} assertions\n";
