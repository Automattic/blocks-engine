<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$fixture = require dirname(__DIR__) . '/fixtures/route-subset-shell.php';
$result = (new ArtifactCompiler())->compile($fixture)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$templates = ThemeTemplates::rendered($plan);
$parts = array_column($plan['template_parts'], null, 'slug');
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], 'Shared brand')) {
        throw new RuntimeException($page['source_path'] . ' retains a header copy in post content');
    }
}
foreach ($templates as $slug => $markup) {
    preg_match_all('/<!-- wp:template-part (\{.*?\}) \/-->/', $markup, $matches);
    $headers = array_filter(array_map(static fn(string $json): array => json_decode($json, true), $matches[1]), static fn(array $ref): bool => 'header' === ($parts[$ref['slug']]['area'] ?? null));
    if (1 !== count($headers)) {
        throw new RuntimeException($slug . ' must reference exactly one header part');
    }
}
echo "route-subset-shell: passed\n";
