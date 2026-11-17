<?php
declare(strict_types=1);

// core/code and core/preformatted have no text-align support. A source <pre>
// centered by its own inline style keeps that alignment on its carrier class.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
foreach (array(
    'core/code' => '<pre style="text-align: center"><code>One advisor.</code></pre>',
    'core/preformatted' => '<pre style="text-align: center">One advisor.</pre>',
) as $name => $source) {
    $result = (new HtmlTransformer())->transform('<main>' . $source . '</main>')->toArray();
    $block = $result['blocks'][0]['innerBlocks'][0] ?? $result['blocks'][0];
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
    $carrier = preg_match('/be-inline-geometry-[a-f0-9]{16}/', (string) ($block['attrs']['className'] ?? ''), $match) ? $match[0] : '';
    $assert($name === ($block['blockName'] ?? '') && '' !== $carrier, $name . ' carries its inline alignment on a geometry class: ' . json_encode($block['attrs'] ?? null));
    $assert(1 === preg_match('/\.' . $carrier . '\{text-align:center/', $css), $name . ' carrier centers its text');
}
echo "pre inline text-align: ok\n";
