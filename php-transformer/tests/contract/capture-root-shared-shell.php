<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$fixture = require dirname(__DIR__) . '/fixtures/capture-root-shared-shell.php';
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$parts = array_column($plan['template_parts'], null, 'area');
$header = (string) ($parts['header']['canonical_block_markup'] ?? '');
$assert('' !== $header, 'The three routes extract a shared header.');
foreach (array('desktop', 'mobile') as $device) {
    $marker = 'data-blocks-engine-capture-' . $device . '-document';
    preg_match_all('/<!-- wp:group (\{[^>]*?\}) -->\s*<div class="([^"]*)">/s', $header, $groups, PREG_SET_ORDER);
    $found = false;
    foreach ($groups as $group) {
        $attrs = json_decode($group[1], true);
        $classes = preg_split('/\s+/', $attrs['className'] ?? '') ?: array();
        if (!in_array($marker, $classes, true)) continue;
        $found = true;
        $assert(in_array('is-' . $device, $classes, true), "The {$device} variant's block attributes retain its root class.");
        $assert(in_array('is-' . $device, explode(' ', $group[2]), true), "The {$device} variant's saved wrapper retains its root class.");
        $assert(!in_array('is-' . ('mobile' === $device ? 'desktop' : 'mobile'), $classes, true), 'Root classes do not leak between scopes.');
    }
    $assert($found, "The shared header contains the {$device} variant group.");
}
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $assert(!str_contains($markup, '<header'), 'The shared header no longer lives in post content.');
    $assert(str_contains($markup, 'is-mobile') && str_contains($markup, 'is-desktop'), 'Post content retains the same root context.');
    $assert(1 === substr_count($markup, 'Studio Name'), 'Only the page-owned mobile bar title remains in content.');
}
fwrite(STDOUT, "Capture-root shared-shell contract passed.\n");
