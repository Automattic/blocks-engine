<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Selector-addressed branching wrappers must retain their native editing
// controls, even when their exact save markup could fit in a layout shell.
$html = '<!doctype html><html><head><style>.frame > .content { color: red; }</style></head><body><div id="frame" class="frame"><div id="content" class="content"><h2>Heading</h2><p>Editable copy</p></div></div></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'files' => array(array('path' => 'website/index.html', 'content' => $html)),
))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (str_contains($markup, '/layout-shell') || 2 !== substr_count($markup, '<!-- wp:group')) {
    throw new RuntimeException('Native Group chains must not become generated companion blocks: ' . $markup);
}
if (!preg_match('~<div[^>]*id="frame"[^>]*>\s*<!-- wp:group .*?--><div[^>]*id="content"~s', $markup)
    || !str_contains($markup, '<!-- wp:heading') || !str_contains($markup, '<!-- wp:paragraph')
    || !str_contains($markup, '>Editable copy</p>')) {
    throw new RuntimeException('Native wrappers must retain selector topology and editable children: ' . $markup);
}
fwrite(STDOUT, "Native Group chain editability test passed.\n");
