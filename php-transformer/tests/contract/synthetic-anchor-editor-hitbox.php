<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>.eyebrow{font-size:12px;text-transform:uppercase}</style>'
    . '<main><div><a class="eyebrow" href="/">Interior</a><div><h1>Steering wheel</h1><p>Details</p></div></div></main>'
)->toArray();
$markup = $result['serialized_blocks'] ?? '';
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$paragraph = (new DOMXPath($dom))->query('//p[a[@href="/"]]')->item(0);
if (!$paragraph instanceof DOMElement || !str_contains($paragraph->getAttribute('class'), 'blocks-engine-synthetic-paragraph')) {
    throw new RuntimeException('Source brand must lower to a native synthetic paragraph.');
}
if ($path = getenv('SYNTHETIC_ANCHOR_EDITOR_ARTIFACT')) {
    file_put_contents($path, json_encode(array('markup' => $markup, 'css' => $css), JSON_THROW_ON_ERROR));
}
echo "Synthetic anchor editor hitbox contract passed.\n";
