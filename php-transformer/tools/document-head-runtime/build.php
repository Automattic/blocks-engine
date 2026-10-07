<?php
declare(strict_types=1);

/** Reproducible, activation-free compiler/theme evidence for issue #2526. */
$options = getopt('', array('input:', 'output:', 'autoload:', 'theme-uri:'));
if (!isset($options['output'])) throw new InvalidArgumentException('Use --output=<new evidence directory> [--input=<artifact.json>] [--autoload=<paired compiler autoload.php>] [--theme-uri=<absolute URL>].');
require $options['autoload'] ?? dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$artifact = isset($options['input']) ? json_decode(file_get_contents($options['input']), true, flags: JSON_THROW_ON_ERROR) : require dirname(__DIR__, 2) . '/tests/fixtures/document-head-runtime.php';
$output = rtrim($options['output'], '/');
if (file_exists($output)) throw new InvalidArgumentException('Evidence output must be a fresh directory.');
if (!mkdir($output, 0777, true)) throw new RuntimeException('Cannot create evidence output.');
$json = static fn(mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
file_put_contents($output . '/input.json', $json($artifact));
foreach ($artifact['files'] as $path => $file) {
    $path = is_array($file) ? ($file['path'] ?? $path) : $path;
    if (!is_string($path) || str_starts_with($path, '/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) throw new InvalidArgumentException('Unsafe source artifact path.');
    $content = is_string($file) ? $file : ($file['content'] ?? (isset($file['content_base64']) ? base64_decode($file['content_base64'], true) : null));
    if (!is_string($content)) throw new InvalidArgumentException('Source artifact payload is not inline.');
    $target = $output . '/source/' . $path;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
    file_put_contents($target, $content);
}
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
file_put_contents($output . '/result.json', $json($result));
$summary = array('input_sha256' => hash('sha256', $json($artifact)), 'compiler_autoload' => $options['autoload'] ?? dirname(__DIR__, 2) . '/vendor/autoload.php', 'status' => $result['status'], 'errors' => array_values(array_filter($result['diagnostics'], static fn(array $row): bool => 'error' === ($row['severity'] ?? ''))), 'parity' => $result['source_reports']['runtime_dependency_parity'] ?? null);
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
if (is_array($plan)) {
    file_put_contents($output . '/plan.json', $json($plan));
    try {
        $resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => $options['theme-uri'] ?? 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true));
        file_put_contents($output . '/resolved-plan.json', $json($resolved));
        foreach ($resolved['writes'] as $write) {
            if ('reference' === $write['payload']['encoding']) throw new RuntimeException('This evidence builder requires inline artifact payloads.');
            $target = $output . '/theme/' . $write['target_path'];
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
            file_put_contents($target, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data']);
        }
        if (class_exists(DocumentHeadContext::class)) foreach ($plan['pages'] as $index => $page) {
            $head = DocumentHeadContext::fromPlan($plan, $page['source_path']);
            if ('' === $head) continue;
            $head = WordPressSitePlanResolver::resolvePayload($head, WordPressSitePlanResolver::references($plan['reference_tokens'], $resolved['resolution']['theme_uri']));
            file_put_contents($output . '/head-' . $index . '.html', $head);
            file_put_contents($output . '/parser-contract-' . $index . '.html', '<!doctype html><html><head>' . $head . '</head><body>' . $resolved['pages'][$index]['resolved_block_markup'] . '</body></html>');
        }
        $summary['strict_resolution'] = 'pass';
        $summary['plan_identity'] = $plan['plan_identity'];
        $summary['theme_tree'] = $output . '/theme';
    } catch (InvalidArgumentException $exception) {
        $summary['strict_resolution'] = 'failed';
        $summary['strict_resolution_error'] = $exception->getMessage();
    }
}
file_put_contents($output . '/summary.json', $json($summary));
print $json($summary);
