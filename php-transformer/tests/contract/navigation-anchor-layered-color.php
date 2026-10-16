<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #2864: core navigation's unlayered `color:inherit` beats any layered rule, so
// the engine's restatement of a layered author anchor colour must be unlayered.
// The state colour is restated beside it, so hover still outranks the rest colour.
$html = '<!doctype html><html><head><style>@layer base,utilities;@layer utilities{.text-muted-foreground{color:#777}.hover\\:text-foreground:hover{color:#eee}}</style></head>'
    . '<body><main>Home</main><footer><nav class="foot"><ul>'
    . '<li><a class="text-muted-foreground hover:text-foreground" href="/a">A</a></li><li><a class="text-muted-foreground hover:text-foreground" href="/b">B</a></li>'
    . '</ul></nav></footer></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();

$unlayered = '';
foreach ($result['assets'] ?? array() as $asset) {
    if (str_contains((string) ($asset['path'] ?? ''), 'after-author')) {
        $unlayered .= (string) ($asset['content'] ?? '');
    }
}
$found = 1 === preg_match('/\.wp-block-navigation\.blocks-engine-list-navigation [^{}]*wp-block-navigation-item__content[^{}]*\{[^{}]*color:#777/', $unlayered)
    && ! str_contains($unlayered, '@layer');
if (! $found) {
    fwrite(STDERR, "FAIL: unlayered navigation anchor colour restatement missing\n" . $unlayered . "\n");
    exit(1);
}
if (1 !== preg_match('/\.wp-block-navigation\.blocks-engine-list-navigation [^{}]*wp-block-navigation-item__content[^{}]*:hover\{color:#eee\}/', $unlayered)) {
    fwrite(STDERR, "FAIL: unlayered navigation anchor hover colour restatement missing\n" . $unlayered . "\n");
    exit(1);
}
echo "ok\n";
