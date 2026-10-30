<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL);
};
$navigation = static function (string $style): array {
    return ( new HtmlTransformer() )->transform(
        '<style>.menu{' . $style . '}</style><nav class="menu"><ul><li><a href="/">Home</a></li></ul></nav>'
    )->toArray();
};

$block = $navigation('display:block');
$attrs = $block['blocks'][0]['attrs'] ?? array();
$serialized = (string) ($block['serialized_blocks'] ?? '');
$assert('default' === ($attrs['layout']['type'] ?? null), 'a resolver-proven block navigation records the core flow layout');
$assert(str_contains($serialized, '"layout":{"type":"default"}'), 'the flow layout survives canonical navigation serialization', $serialized);

$conditional = ( new HtmlTransformer() )->transform(
    '<style>:root{--navbar-display:flex}.captured-header{--navbar-display:flex}'
    . '@media(min-width:769px){.captured-header{--navbar-display:block}}'
    . '.navbar{display:var(--navbar-display,block)}</style>'
    . '<header class="captured-header"><nav class="navbar"><ul><li><a href="/">Home</a></li></ul></nav></header>'
)->toArray();
$conditionalNavigation = $conditional['blocks'][0]['innerBlocks'][0] ?? array();
$assert(
    'default' === ($conditionalNavigation['attrs']['layout']['type'] ?? null),
    'a captured desktop custom-property override resolves on its navigation ancestor rather than from the global flex fallback'
);

$vertical = ( new HtmlTransformer() )->transform('<nav style="display:flex;flex-direction:column"><ul><li><a href="/">Home</a></li></ul></nav>')->toArray();
$assert(
    array( 'type' => 'flex', 'orientation' => 'vertical' ) === ($vertical['blocks'][0]['attrs']['layout'] ?? null),
    'an explicit vertical flex navigation retains its native flex intent'
);

$grid = ( new HtmlTransformer() )->transform('<nav style="display:grid"><ul><li><a href="/">Home</a></li></ul></nav>')->toArray();
$gridClass = (string) ($grid['blocks'][0]['attrs']['className'] ?? '');
$gridCss = implode("\n", array_column($grid['assets'] ?? array(), 'content'));
$assert(
    ! isset($grid['blocks'][0]['attrs']['layout']) && '' !== $gridClass && str_contains($gridCss, '.' . strtok($gridClass, ' ') . '{display:grid}'),
    'an explicit grid navigation keeps its author-owned grid intent without a fabricated flow layout'
);

$unstyled = ( new HtmlTransformer() )->transform('<nav><ul><li><a href="/">Home</a></li></ul></nav>')->toArray();
$assert(array( 'type' => 'default' ) === ($unstyled['blocks'][0]['attrs']['layout'] ?? null), 'an unstyled navigation list records the UA flow (stacked) layout');

$legalList = '<footer><nav id="legal"><ul><li><a href="/a">Legal notice</a></li><li><a href="/b">Report content</a></li></ul></nav></footer>';
foreach ( array(
    'no author CSS' => array( '', array( 'type' => 'default' ) ),
    'list reset and item margins' => array( 'nav#legal ul{list-style:none;margin:0;padding:0} nav#legal li{margin-bottom:1rem}', array( 'type' => 'default' ) ),
    'inline-block items' => array( 'li{display:inline-block}', null ),
    'flex list' => array( 'ul{display:flex}', array( 'type' => 'flex', 'flexWrap' => 'nowrap' ) ),
    'floated items' => array( 'li{float:left}', null ),
) as $name => [$css, $expectedLayout] ) {
    $result = ( new HtmlTransformer() )->transform('<style>' . $css . '</style>' . $legalList)->toArray();
    $legal = $result['blocks'][0] ?? array();
    $assert('core/navigation' === ($legal['blockName'] ?? null), $name . ': legal links remain native navigation');
    $assert($expectedLayout === ($legal['attrs']['layout'] ?? null), $name . ': legal list keeps its source layout', json_encode($legal['attrs']['layout'] ?? null));
}

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation default layout contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation default layout contract passed: {$passes} assertions\n";
