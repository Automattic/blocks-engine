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
$assert(! isset($unstyled['blocks'][0]['attrs']['layout']), 'a navigation without resolved display evidence keeps the core default untouched');

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation default layout contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation default layout contract passed: {$passes} assertions\n";
