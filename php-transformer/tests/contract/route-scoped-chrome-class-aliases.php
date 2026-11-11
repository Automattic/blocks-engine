<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

// Builders name chrome classes per route and scope each rule under the
// route's own page class (`.page-a .bar-1` on one route, `.page-b .bar-2` on
// another). Equal rules are one header design, shared as one part; a rule
// that differs keeps each route's header.
$page = static fn (string $scope, string $bar, string $rule, string $title): string => '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title>'
    . '<style>.' . $scope . ' .' . $bar . '{' . $rule . '}</style></head><body><div class="' . $scope . '">'
    . '<header class="' . $bar . '"><a href="/">Acme</a><nav><a href="/">Home</a><a href="/about/">About</a></nav></header>'
    . '<main><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main></div></body></html>';
$rule = 'display:flex;padding:16px;background:#222;color:#fff';
foreach (array('equal rules' => array($rule, 1), 'differing rules' => array('display:flex;padding:8px;background:#222;color:#fff', 0)) as $label => [$other, $parts]) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $page('page-a', 'bar-1', $rule, 'Home'),
        'about/index.html' => $page('page-b', 'bar-2', $other, 'About'),
    )))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert($parts === count($headers), "{$label}: {$parts} shared header part(s): " . json_encode(array_column($plan['diagnostics'], 'code')));
    foreach ($plan['pages'] as $row) {
        $assert((0 === $parts) === str_contains((string) $row['canonical_block_markup'], 'Acme'), "{$label}: {$row['source_path']} keeps its header only when unshared.");
    }
}

echo "route-scoped-chrome-class-aliases contract passed\n";
