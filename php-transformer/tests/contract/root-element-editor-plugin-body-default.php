<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Issue #5647 (bedford-demo.squarespace.com): the source sets its typeface on
// the root element only, and the body inherits it. Plugins enqueue editor-only
// zero-weight body defaults into the canvas (Jetpack ships
// `:where(body){font-family:-apple-system,…}`) that the frontend never loads.
// The canvas must keep inheriting the source root's font, as the frontend does.
$source = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Root font</title>'
    . '<style>html{font-family:sans-serif}.nav a{color:#000}</style></head><body>'
    . '<main><nav class="nav"><p><a href="/">Home</a></p></nav></main></body></html>';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$plan = (new WordPressSitePlan())->fromCompilerResult(
    (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray()
);
$editorCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter(
        $plan['assets'],
        static fn (array $asset): bool => 'css' === $asset['kind'] && 'frontend' !== ($asset['stylesheet_target'] ?? 'both')
    )
));

// The plugin stylesheet loads into the canvas after the theme's editor styles.
$pluginCss = ':where(body){font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0}';
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><head><style>' . $editorCss . '</style><style>' . $pluginCss . '</style></head><body class="editor-styles-wrapper"><main><p>Home</p></main></body></html>');
// Core defines the theme's font presets on the canvas root.
$cascade = new StaticCssCascade($document, ':root{--wp--preset--font-family--body:sans-serif}');
$paragraph = $document->getElementsByTagName('p')->item(0);
$assert(null !== $paragraph, 'The editor canvas fixture resolves the paragraph.');
$resolved = $cascade->resolve($paragraph, array('font-family'), array('font-family'));
$assert('sans-serif' === trim((string) ($resolved['font-family'] ?? '')), 'The canvas keeps the source root typeface over an editor-only zero-weight plugin body default: ' . json_encode($resolved));

fwrite(STDOUT, "Root element editor typography survives plugin body defaults\n");
