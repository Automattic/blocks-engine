<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Author selectors also define the specificity ceiling for projected inline
// document styles. An unmatched selector cannot simply be erased before that
// ceiling is computed, even though it does not currently match the document.
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html style="overflow:auto"><head><style>#absent #also-absent{color:salmon}</style></head><body><main>Home</main></body></html>',
)))->toArray();
$frontend = '';
foreach ($compiled['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('frontend' === ($asset['stylesheet_target'] ?? 'both')) $frontend .= $asset['content'] ?? '';
}
if (!preg_match('/,(#[a-zA-Z0-9_-]+)\1\1\)/', $frontend)) {
    throw new RuntimeException('Inline root carrier must retain the author specificity ceiling, including unmatched author IDs');
}
echo "Inline selector specificity preservation passed\n";
