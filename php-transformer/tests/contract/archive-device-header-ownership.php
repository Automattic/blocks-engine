<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Device correspondence alone does not turn archive-owned chrome into the
// site's shared header. Exercise the public compiler, including lifecycle
// template placement, rather than calling the extraction helper directly.
$artifact = require dirname(__DIR__) . '/fixtures/taxonomy/corroborated-category.php';
foreach ($artifact['files'] as &$file) {
    if (!is_array($file) || 'archives/field-notes.html' !== $file['path']) continue;
    $html = str_replace('<header>', '<header class="archive-owned-header" style="padding:23px">', $file['content']);
    $file['content'] = '';
    foreach (array('desktop', 'mobile') as $device) {
        $file['content'] .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope="">' . $html . '</div>';
    }
}
unset($file);
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$archive = $templates['category-field-notes'] ?? '';
if (2 !== substr_count($archive, '<header') || str_contains($archive, '"area":"header"')) {
    throw new RuntimeException('Responsive archive headers must retain their content ownership and presentation.');
}
echo "archive-device-header-ownership: passed\n";
