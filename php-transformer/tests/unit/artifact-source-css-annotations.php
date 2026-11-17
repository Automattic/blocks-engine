<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Build annotations (`/* rtl:… */`, section headers) are not runtime CSS, yet
// they count towards the published theme's weight. License banners and
// comment syntax inside strings are content and must survive.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="lead">Hello</p><p class="note">World</p></main></body></html>',
    'site.css' => "/*! Kit license */\n/* build-only annotation */\n.lead{letter-spacing:.051em}\n/* rtl:begin:ignore */.note/* rtl:end:ignore */{margin:5px}.lead::after{content:\"/* literal */\"}",
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && 'files' === ($asset['source'] ?? null)), 'content'));
if (!str_contains($css, '/*! Kit license */')) throw new RuntimeException('License comments must survive: ' . $css);
if (str_contains($css, 'build-only annotation') || str_contains($css, 'rtl:')) throw new RuntimeException('Non-runtime annotations must not ship: ' . $css);
if (1 !== preg_match('/(?:^|[}\s])\.note(?::not\([^)]*\))*\{margin:5px\}/', $css)) throw new RuntimeException('Dropping an annotation must not change the selector: ' . $css);
if (!str_contains($css, '"/* literal */"')) throw new RuntimeException('Comment syntax inside a string must survive: ' . $css);
echo "Artifact source CSS ships without build annotations\n";
