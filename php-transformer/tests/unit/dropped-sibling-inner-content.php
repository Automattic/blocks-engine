<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><title>Store</title></head><body><div><div id="header"><span><a class="wsite-cart-link" href="/store/checkout"></a></span></div><div id="content-wrapper"><div><select name="Category"></select></div></div><div></div></div></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
if ('failed' === $result['status']) throw new RuntimeException('Reduced store page must compile.');
$check = static function (array $blocks, string $path = 'blocks') use (&$check): void {
    foreach ($blocks as $index => $block) {
        $location = $path . '.' . $index;
        $children = $block['innerBlocks'] ?? array();
        $nulls = count(array_filter($block['innerContent'] ?? array(), static fn ($part): bool => null === $part));
        if ($nulls !== count($children)) {
            throw new RuntimeException($location . ' ' . $block['blockName'] . ': nulls=' . $nulls . ' inner=' . count($children));
        }
        $check($children, $location . '.innerBlocks');
    }
};
if (empty($result['blocks'])) throw new RuntimeException('Expected a nonempty block tree.');
$check($result['blocks']);
echo "dropped-sibling-inner-content: ok\n";
