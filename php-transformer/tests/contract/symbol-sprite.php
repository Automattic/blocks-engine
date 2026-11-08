<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Sprite</title><style>
.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#333;color:#fff;text-decoration:none}
.btn svg{width:16px;height:16px;fill:currentColor}
</style></head><body>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 40 40" display="none" width="0" height="0"><symbol id="icon-arrow" viewBox="0 0 40 40"><path d="M17 5l13 14-13 14-3-3 11-11-11-11z"></path></symbol></svg>
<main><p><a class="btn" href="#about"><svg><use xlink:href="#icon-arrow"></use></svg><span>About</span></a></p></main>
</body></html>
HTML;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array($source, str_replace('xlink:href=', 'href=', $source), str_replace('<symbol ', '<defs><symbol ', str_replace('</symbol>', '</symbol></defs>', $source))) as $html) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = $result['serialized_blocks'];
    $assert(!str_contains($markup, '<!-- wp:html'), 'A materialized symbol sprite must emit zero core/html blocks.');
    $assert(!str_contains($markup, '<!-- wp:custom/svg-artwork'), 'The dead sprite carrier must emit no artwork block.');
    $assert(1 === preg_match('/<a\b[^>]*href="#about"[^>]*>.*?<img\b[^>]*src="([^"]+)"[^>]*>.*?About.*?<\/a>/s', $markup, $match), 'The About button retains its arrow image.');
    $payload = '';
    foreach ($result['assets'] as $asset) {
        if ('svg' === ($asset['kind'] ?? null) || str_contains($asset['content'] ?? '', '<svg')) $payload .= $asset['content'] ?? '';
    }
    $assert(str_contains($payload, 'M17 5l13 14-13 14-3-3 11-11-11-11z'), 'The standalone icon retains the arrow path.');
    $assert(1 === preg_match('/<symbol\b[^>]*id="([^"]+)"[^>]*viewBox="0 0 40 40"/', $payload, $symbol), 'The copied symbol retains its viewport.');
    $assert(str_contains($payload, 'href="#' . $symbol[1] . '"'), 'The icon use resolves to its own copied symbol.');
}
// A use copy does not retire other consumers of the same definition.
foreach (array('<style>.btn{clip-path:url(#icon-arrow)}</style>', '<a href="#icon-arrow">Definition</a>', '<span aria-describedby="icon-arrow">Description</span>') as $consumer) {
    $html = str_replace('</body>', $consumer . '</body>', $source);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $assert(str_contains($result['serialized_blocks'], '<symbol id="icon-arrow"') || str_contains($result['serialized_blocks'], '<symbol id=\\"icon-arrow\\"'), 'Definitions still needed in-page retain their carrier.');
}
echo "Symbol sprite contract passed.\n";
