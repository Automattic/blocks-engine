<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$roundTrip = static fn(array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$artifact = array('entrypoint' => 'index.html', 'files' => array());
foreach (array('index' => 'Home', 'first' => 'First story', 'second' => 'Second story') as $slug => $title) {
    $html = '<html><head><title>' . $title . '</title><style>.desktop-document{display:block}.mobile-document{display:none}@media(max-width:600px){.desktop-document{display:none}.mobile-document{display:block}}</style></head><body>';
    foreach (array('desktop', 'mobile') as $variant) {
        $html .= '<div class="' . $variant . '-document"><header class="' . $variant . '-header"><p>' . $variant . ' publication</p></header><main><article><h1>' . $title . '</h1><p><time datetime="2024-03-01">March 1, 2024</time></p><p>Unique body for ' . $slug . '.</p></article></main><footer class="' . $variant . '-footer"><p>' . $variant . ' legal copy</p></footer></div>';
    }
    $artifact['files'][] = array('path' => $slug . '.html', 'content' => $html . '</body></html>', 'metadata' => array('post_type' => 'index' === $slug ? 'page' : 'post'));
}
$compiler = new ArtifactCompiler();
$shared = $roundTrip($compiler->prepareShared($artifact));
$prepared = $roundTrip($compiler->preparePages($artifact, $shared));
$receipts = $roundTrip($compiler->compilePreparedPages($shared, $prepared));
fwrite(STDOUT, "Compiled neutral post receipts; composing inline chrome.\n");
$result = $compiler->compose($shared, array_reverse($receipts))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
if (array() === $plan) {
    foreach ($result['diagnostics'] ?? array() as $diagnostic) {
        if ('wordpress_site_plan' === substr((string) ($diagnostic['code'] ?? ''), 0, 19)) fwrite(STDOUT, json_encode($diagnostic, JSON_THROW_ON_ERROR) . "\n");
    }
}
$assert(array() !== $plan, 'Staged post composition must produce a canonical plan, not a placement validation diagnostic.');
WordPressSitePlan::assertValid($plan);
$parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'inline_shared_shell' === ($part['placement']['kind'] ?? null)));
$assert(4 === count($parts), 'The neutral fixture extracts both responsive header and footer variants: ' . json_encode(array_column($plan['template_parts'], 'placement')));
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']));
$assert(2 === count($posts), 'Both declared source posts survive composition.');
foreach ($parts as $part) {
    $reference = '"slug":"' . $part['slug'] . '"';
    foreach ($posts as $post) $assert(1 === substr_count($post['canonical_block_markup'], $reference), 'Each inline part stays at its source post position exactly once.');
    foreach ($plan['templates'] as $template) $assert(!str_contains($template['canonical_block_markup'], $reference), 'Inline parts never become global template bindings.');
}
$whole = $compiler->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert($plan === $whole, 'Whole and JSON-resumed staged compilation agree on inline post ownership.');
fwrite(STDOUT, "staged-inline-post-chrome: ok\n");
