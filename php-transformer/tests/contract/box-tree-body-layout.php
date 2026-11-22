<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// A captured page whose author centres a narrow, padded column on the body
// itself (`body{max-width:26em;margin:auto;padding:25vh 2em 2em;text-align:center}`).
// The source body becomes no block, so once the box-tree route retires the
// author stylesheet only an engine rule on the WordPress body keeps the column.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$fixture = __DIR__ . '/../fixtures/box-tree-body-layout';
$compile = static function (bool $evidence) use ($fixture): array {
    $files = array('index.html' => file_get_contents($fixture . '/index.html'));
    if ($evidence) $files['box-tree/index.json'] = file_get_contents($fixture . '/index.json');
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
};
// The declarations delivered on the document body, by the theme's stylesheets.
$bodyDeclarations = static function (array $result): array {
    $declarations = array();
    foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? null)) continue;
        (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body, array $ancestors) use (&$declarations): void {
            if (array() === $ancestors && 1 === preg_match('/^(?::root\s+)?body(?::not\([^()]*\))*$/', trim($prelude))) $declarations = array_replace($declarations, SourceDeclarations::parse($body));
        });
    }
    return $declarations;
};

$plain = $compile(false);
$captured = $compile(true);
$assert(1.0 === (float) $captured['source_reports']['box_tree']['index.html']['coverage'], 'The capture route has complete box-tree evidence.');
$assert(array() === array_filter($captured['source_reports']['wordpress_site_plan']['assets'], static fn(array $asset): bool => 'inline-style' === ($asset['source'] ?? null) && '' !== trim((string) $asset['content'])), 'The capture route retires the author stylesheet.');
foreach (array('plain' => $plain, 'capture' => $captured) as $route => $result) {
    $body = $bodyDeclarations($result);
    $assert('26em' === ($body['max-width'] ?? null), "The $route route keeps the body's 26em column.");
    $assert('auto' === ($body['margin'] ?? null), "The $route route centres the body column.");
    $assert('25vh 2em 2em' === ($body['padding'] ?? null), "The $route route keeps the body's 25vh 2em 2em padding.");
    $assert('center' === ($body['text-align'] ?? null), "The $route route keeps the body's centred text.");
}
