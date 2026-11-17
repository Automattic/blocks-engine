<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Playlist state</title><style>
body{margin:0;font-family:sans-serif}
.card{width:240px;padding:12px;margin:12px;background:#eee}
.label{opacity:0;transition:opacity .2s;color:#5d1ff2}
.card.is-current .label,.card.is-current:hover .label{opacity:1}
</style></head><body><main>
<div class="card is-current"><p>First video</p><div class="label">Now playing</div></div>
<div class="card"><p>Second video</p><div class="label">Now playing</div></div>
</main></body></html>
HTML;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$assert('success' === $result['status'], 'state labels compile');
$assert(2 === substr_count($result['serialized_blocks'], 'Now playing'), 'both state labels survive');
$assert(str_contains($result['serialized_blocks'], 'is-current'), 'authored current-card class survives');
$css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
$assert(! str_contains($css, 'opacity:1!important'), 'neither frontend nor editor forces inactive labels visible');
$assert(1 === preg_match('/\.label\s*\{\s*opacity:\s*0\s*[;}]/', $css), 'resting opacity remains hidden');
$assert(str_contains($css, '.card.is-current .label'), 'current-card reveal selector survives');
$assert(str_contains($css, '{opacity:1}'), 'authored positive opacity survives');

echo "PASS: class-gated labels retain authored resting and current opacity\n";
