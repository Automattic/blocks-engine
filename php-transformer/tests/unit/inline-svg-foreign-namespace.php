<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$html = <<<'HTML'
<!doctype html><html><head><title>Info</title></head><body><main><p>Price info
<svg width="16px" height="16px" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" xmlns:sketch="http://www.bohemiancoding.com/sketch/ns"><g sketch:type="MSPage" fill="#C9CDCF"><path d="M8,15 C11.9,15 15,11.9 15,8 C15,4.1 11.9,1 8,1 C4.1,1 1,4.1 1,8 C1,11.9 4.1,15 8,15 Z"></path></g></svg>
</p></main></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$output = (string) json_encode($result, JSON_UNESCAPED_SLASHES);
$countHtml = static function (array $blocks) use (&$countHtml): int {
    $count = 0;
    foreach ( $blocks as $block ) {
        $count += 'core/html' === ($block['blockName'] ?? '') ? 1 : 0;
        $count += $countHtml($block['innerBlocks'] ?? array());
    }
    return $count;
};
$count = $countHtml($result['blocks'] ?? array());
if ( 0 !== $count ) {
    throw new RuntimeException("Expected zero core/html blocks; found {$count}");
}
if ( ! str_contains($output, 'M8,15 C11.9,15 15,11.9 15,8 C15,4.1 11.9,1 8,1 C4.1,1 1,4.1 1,8 C1,11.9 4.1,15 8,15 Z') ) {
    throw new RuntimeException('The icon path must survive compilation');
}
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( str_contains((string) ($asset['content'] ?? ''), 'sketch:') ) {
        throw new RuntimeException('Foreign namespace metadata must not survive in generated assets');
    }
}
fwrite(STDOUT, "inline-svg-foreign-namespace: zero core/html blocks; icon path preserved\n");
