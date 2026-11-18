<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A provider replaces the form root. Its layout graph must carry the bounded,
// centred box, not merely the presentation of the controls inside that box.
$html = '<style>form{max-width:42rem;margin-inline:auto;padding-inline:20px 24px;padding-inline-end:28px;margin-block:12px 16px}@media(min-width:700px){form{max-width:48rem;margin-inline:10px 30px}}</style>'
    . '<main><form><input name="email" type="email"><button type="submit">Send</button></form></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$graph = null;
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] as $row) {
    if ('forms' === ($row['type'] ?? null)) $graph = $row['payload']['entities'][0]['layout_graph'];
}
$root = current(array_filter($graph['nodes'] ?? array(), static fn(array $node): bool => 'form' === $node['id']));
$expected = array('max_width' => '42rem', 'margin_inline_start' => 'auto', 'margin_inline_end' => 'auto', 'padding_inline_start' => '20px', 'padding_inline_end' => '28px', 'margin_block_start' => '12px', 'margin_block_end' => '16px');
foreach ($expected as $key => $value) {
    if ($value !== ($root['layout'][$key] ?? null)) throw new RuntimeException('Missing form box fact: ' . $key);
}
$patch = current(array_filter($graph['variants'], static fn(array $variant): bool => 'form' === $variant['node']));
foreach (array('max_width' => '48rem', 'margin_inline_start' => '10px', 'margin_inline_end' => '30px') as $key => $value) {
    if ($value !== ($patch['layout_patch'][$key] ?? null)) throw new RuntimeException('Missing responsive form box fact: ' . $key);
}
echo "Logical form container box facts retained\n";
