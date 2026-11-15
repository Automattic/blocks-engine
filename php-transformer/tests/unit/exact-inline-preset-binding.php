<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The resting paint is supplied through a scoped custom property, not a
// document-root variable. State binding must prove the local cascade first.
$html = '<!doctype html><html><head><style>
body{color:#212529;background:#fff;font-family:Arial,sans-serif}
p{color:#c80000}.paint{--local-paint:#c80000;color:var(--local-paint)}
.paint:hover{color:#000}
</style></head><body><main><p>Palette red text</p><p class="paint" style="color:#c80000">Local resting paint</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$markup = implode('\n', array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
if (1 !== preg_match('/<p\b([^>]*)>Local resting paint<\/p>/', $markup, $state)) throw new RuntimeException('The paragraph remains native.');
if (!str_contains($state[1], 'color:var(--wp--preset--color--')) throw new RuntimeException('A proven local resting variable binds inline.');
if (preg_match('/has-[a-z0-9-]+-color\b/', str_replace('has-text-color', '', $state[1]))) throw new RuntimeException('No important preset class masks hover.');
echo "Local resting preset binding passed.\n";
