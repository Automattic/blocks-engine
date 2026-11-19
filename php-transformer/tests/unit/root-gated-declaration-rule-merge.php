<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A rule that names the root only as a gate or a specificity shim still
// styles its carrier: `:root .lead` selects `.lead`, not the document. Its
// restatements for other carriers ship their shared declaration block once,
// like any carrier rule. Rules whose subject is the document or every element
// (`:root`, `*`) stay standalone.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="lead">Hello</p><p class="note">World</p></main></body></html>',
    'site.css' => ":root{--kit-gap:1rem}\n"
        . ":root .lead{letter-spacing:.05em;padding:var(--kit-gap);border-color:#123456}\n"
        . ":root .note{letter-spacing:.05em;padding:var(--kit-gap);border-color:#123456}\n"
        . "main *{outline-offset:3px}\n.note{outline-offset:3px}\n",
)))->toArray();
$css = implode("\n", array_column(array_filter($result['source_reports']['wordpress_site_plan']['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) $asset['content'], 'letter-spacing')), 'content'));
if ('' === $css) throw new RuntimeException('Author stylesheet missing');
if (1 !== substr_count($css, 'letter-spacing:.05em')) throw new RuntimeException("A root-gated declaration block repeated across carriers must ship once:\n" . $css);
if (!str_contains($css, ':root{--kit-gap:')) throw new RuntimeException("The document token block keeps its own selector:\n" . $css);
if (2 !== substr_count($css, 'outline-offset:3px')) throw new RuntimeException("An every-element reset keeps its own selector:\n" . $css);
echo "Root-gated carrier rules ship a repeated declaration block once\n";
