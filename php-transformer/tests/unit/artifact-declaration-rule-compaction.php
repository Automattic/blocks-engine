<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Artifact consumers must receive the same compact source CSS as site-plan
// consumers, without moving declarations across a competing cascade rule.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="lead">Hello</p><p class="note">World</p><p class="aside">Aside</p></main></body></html>',
    'site.css' => '/*! Kit license */ /* build-only annotation */ .lead{letter-spacing:.051em}.note{letter-spacing:.051em}.lead{margin:3px}.note{margin:5px}.aside{margin:3px}.lead::after{content:"/* literal */"}',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && 'files' === ($asset['source'] ?? null)), 'content'));
if (1 !== substr_count($css, 'letter-spacing:.051em')) throw new RuntimeException('Artifact source CSS must ship repeated declarations once: ' . $css);
if (2 !== substr_count($css, 'margin:3px')) throw new RuntimeException('Compaction must retain declarations separated by a competing rule: ' . $css);
if (!str_contains($css, '/*! Kit license */')) throw new RuntimeException('License comments must survive');
if (str_contains($css, 'build-only annotation')) throw new RuntimeException('Non-runtime annotations must not ship');
if (!str_contains($css, '"/* literal */"')) throw new RuntimeException('Comment syntax inside a string must survive');
echo "Artifact declaration compaction preserves cascade barriers\n";
