<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A border colour need not paint any text or background to be editable.
$html = '<!doctype html><html><head><style>
body{color:#212529;background:#fff;font-family:Arial,sans-serif}
.frame{border:7px solid #e9ecef;padding:20px}
</style></head><body><main><div class="frame"><p>Border-only paint</p></div></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$encoded = json_encode($plan, JSON_THROW_ON_ERROR);
if (!str_contains($encoded, '#e9ecef')) throw new RuntimeException('Border paint survives compilation.');
// The public plan exposes derived design tokens, including the border-only paint.
$found = false;
$walk = function (mixed $value) use (&$walk, &$found): void {
    if (!is_array($value)) return;
    if (isset($value['slug'], $value['color']) && '#e9ecef' === $value['color']) $found = true;
    foreach ($value as $child) $walk($child);
};
$walk($plan);
if (!$found) throw new RuntimeException('Border-only colour receives a palette preset.');
echo "Border-only palette passed.\n";
