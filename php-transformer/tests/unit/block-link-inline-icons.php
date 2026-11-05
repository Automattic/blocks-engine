<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('files' => array(array(
    'path' => 'index.html',
    'kind' => 'html',
    'content' => file_get_contents(dirname(__DIR__, 2) . '/tools/visual-parity/tests/fixtures/block-link-inline-icons.html'),
))))->toArray();

$markup = $result['source_reports']['compiled_site']['pages'][0]['block_markup'] ?? '';
foreach (array('icon-mail', 'icon-chat') as $icon) {
    if (!str_contains($markup, 'class="' . $icon . ' has-inline-color"')) {
        throw new RuntimeException('Rendered link must retain pseudo-element icon: ' . $icon);
    }
}
if (in_array('--json', $argv, true)) {
    echo json_encode($result, JSON_THROW_ON_ERROR);
} else {
    echo "Block link inline icons passed\n";
}
