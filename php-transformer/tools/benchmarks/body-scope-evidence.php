<?php
declare(strict_types=1);

$options = getopt('', array('source:', 'input:', 'report:', 'output:', 'autoload:', 'wp-parser:', 'neutral', 'theme-uri:'));
require $options['autoload'] ?? dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;

if (isset($options['wp-parser'])) foreach (array('class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php') as $file) require_once $options['wp-parser'] . '/' . $file;
$parse = static fn(string $html): array => isset($options['wp-parser']) ? (new WP_Block_Parser())->parse($html) : (new Runtime())->parseBlocks($html);

$json = static fn(mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (isset($options['report'])) {
    $report = json_decode(file_get_contents($options['report']), true, flags: JSON_THROW_ON_ERROR);
    $findings = array();
    $scan = static function (mixed $node, string $path = '') use (&$scan, &$findings): void {
        if (!is_array($node)) return;
        if (isset($node['code']) && (str_contains((string) $node['code'], 'runtime_dom_contract_fallback') || str_contains((string) $node['code'], 'semantic'))) $findings[] = array('path' => $path, 'finding' => $node);
        foreach ($node as $key => $value) if (is_array($value)) $scan($value, $path . '/' . $key);
    };
    $scan($report);
    $plan = $report['blocks_engine']['wordpress_site_plan'] ?? $report['source_reports']['wordpress_site_plan'] ?? (isset($report['pages']) ? $report : array());
    $raw = array();
    foreach (array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array()) as $document) {
        $islands = array();
        $walk = static function (array $blocks) use (&$walk, &$islands): void { foreach ($blocks as $block) { if ('core/html' === ($block['blockName'] ?? null)) $islands[] = array('bytes' => strlen($block['innerHTML']), 'sample' => substr($block['innerHTML'], 0, 600)); $walk($block['innerBlocks'] ?? array()); } };
        $walk($parse($document['canonical_block_markup'] ?? ''));
        $raw[] = array('source' => $document['source_path'], 'html_comments' => substr_count($document['canonical_block_markup'] ?? '', '<!-- wp:html'), 'bytes' => strlen($document['canonical_block_markup'] ?? ''), 'parsed_islands' => $islands);
    }
    if (isset($options['output'])) {
        mkdir($options['output'], 0777, true);
        file_put_contents($options['output'] . '/receipt-plan.json', $json($plan));
        file_put_contents($options['output'] . '/source-artifact-metadata.json', $json($report['source_artifact'] ?? array()));
    }
    print $json(array('keys' => array_keys($report), 'source_identity' => $plan['source'] ?? null, 'source_artifact_keys' => array_keys($report['source_artifact'] ?? array()), 'raw' => $raw, 'findings' => array_values(array_filter($findings, static fn(array $row): bool => str_starts_with($row['path'], '/blocks_engine/')))));
    exit;
}
if (!isset($options['output'])) throw new InvalidArgumentException('Use --source=<portable source> or --input=<artifact.json>, --output=<fresh evidence directory>.');
$output = $options['output'];
if (file_exists($output)) throw new InvalidArgumentException('Use a fresh evidence output.');
mkdir($output, 0777, true);
if (isset($options['neutral'])) $artifact = require dirname(__DIR__, 2) . '/tests/fixtures/declared-document-role-button.php';
elseif (isset($options['input'])) $artifact = json_decode(file_get_contents($options['input']), true, flags: JSON_THROW_ON_ERROR);
else {
    $root = rtrim($options['source'], '/');
    $files = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) continue;
        $path = 'website/' . substr($file->getPathname(), strlen($root) + 1);
        $content = file_get_contents($file->getPathname());
        $text = in_array(strtolower($file->getExtension()), array('html', 'css', 'js', 'json', 'svg', 'txt', 'xml'), true);
        $files[$path] = array('path' => $path, $text ? 'content' : 'content_base64' => $text ? $content : base64_encode($content));
    }
    ksort($files);
    $artifact = array('entrypoint' => 'website/index.html', 'files' => $files);
}
file_put_contents($output . '/input.json', $json($artifact));
foreach ($artifact['files'] as $path => $file) {
    $path = is_array($file) ? ($file['path'] ?? $path) : $path;
    $content = is_string($file) ? $file : ($file['content'] ?? base64_decode($file['content_base64'] ?? '', true));
    if (!is_string($path) || str_starts_with($path, '/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path) || !is_string($content)) throw new InvalidArgumentException('Invalid evidence source payload.');
    $target = $output . '/source/' . $path;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
    file_put_contents($target, $content);
}
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
file_put_contents($output . '/result.json', $json($result));
$runtime = new Runtime();
$islands = array();
$blockCounts = array();
$walk = static function (array $blocks, string $path = '') use (&$walk, &$islands, &$blockCounts): void {
    foreach ($blocks as $index => $block) {
        $here = $path . '/' . $index;
        $name = $block['blockName'] ?? 'freeform';
        $blockCounts[$name] = ($blockCounts[$name] ?? 0) + 1;
        if ('core/html' === ($block['blockName'] ?? null)) {
            $html = $block['innerHTML'] ?? $block['attrs']['content'] ?? '';
            $islands[] = array('path' => $here, 'bytes' => strlen($html), 'sha256' => hash('sha256', $html), 'html' => $html);
        }
        $walk($block['innerBlocks'] ?? array(), $here);
    }
};
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$resolution = null;
if (is_array($plan)) {
    $themeUri = $options['theme-uri'] ?? 'https://example.test/theme';
    $resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => $themeUri, 'require_proven_dynamic_client_assets' => true));
    file_put_contents($output . '/plan.json', $json($plan));
    file_put_contents($output . '/resolved-plan.json', $json($resolved));
    foreach ($resolved['pages'] as $page) $walk($parse($page['resolved_block_markup']), $page['source_path']);
    foreach ($resolved['template_parts'] as $part) $walk($parse($part['resolved_block_markup']), $part['source_path']);
    foreach ($resolved['writes'] as $write) {
        if ('reference' === $write['payload']['encoding']) throw new RuntimeException('Evidence requires inline write payloads.');
        $target = $output . '/theme/' . $write['target_path'];
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
        file_put_contents($target, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data']);
    }
    $parts = array_column($resolved['template_parts'], null, 'slug');
    $render = static function (array $blocks) use (&$render, $parts, $parse): string {
        $html = '';
        foreach ($blocks as $block) {
            if ('core/template-part' === ($block['blockName'] ?? null)) {
                $part = $parts[$block['attrs']['slug'] ?? ''] ?? null;
                if (!is_array($part)) throw new RuntimeException('Unbound template part.');
                $tag = $block['attrs']['tagName'] ?? $part['tag_name'] ?? 'div';
                $html .= '<' . $tag . '>' . $render($parse($part['resolved_block_markup'])) . '</' . $tag . '>';
                continue;
            }
            $child = 0;
            foreach ($block['innerContent'] ?? array() as $content) $html .= null === $content ? $render(array($block['innerBlocks'][$child++] ?? array())) : $content;
        }
        return $html;
    };
    foreach ($resolved['pages'] as $index => $page) {
        $head = WordPressSitePlanResolver::resolvePayload(DocumentHeadContext::fromPlan($plan, $page['source_path']), WordPressSitePlanResolver::references($plan['reference_tokens'], $themeUri));
        $tail = '';
        foreach ($page['document_metadata']['scripts'] ?? array() as $script) if ('body' === $script['placement'] && isset($script['resolved_url'])) $tail .= '<script src="' . htmlspecialchars($script['resolved_url'], ENT_QUOTES) . '"></script>';
        file_put_contents($output . '/frontend-' . $index . '.html', '<!doctype html><html><head>' . $head . '</head><body>' . $render($parse($page['resolved_block_markup'])) . $tail . '</body></html>');
    }
    $resolution = 'pass';
}
else $walk($runtime->parseBlocks($result['serialized_blocks'] ?? ''), 'unmaterialized');
file_put_contents($output . '/core-html-islands.json', $json($islands));
file_put_contents($output . '/block-counts.json', $json($blockCounts));
$summary = array('input_sha256' => hash('sha256', $json($artifact)), 'status' => $result['status'], 'strict_resolution' => $resolution, 'recursive_core_html_count' => count($islands), 'islands' => array_map(static fn(array $island): array => array_diff_key($island, array('html' => true)) + array('sample' => substr($island['html'], 0, 1700)), $islands), 'diagnostics' => array_values(array_filter($result['diagnostics'], static fn(array $row): bool => 'error' === ($row['severity'] ?? '') || str_contains((string) ($row['code'] ?? ''), 'runtime_dom_contract_fallback') || str_contains((string) ($row['code'] ?? ''), 'semantic'))), 'runtime_parity' => $result['source_reports']['runtime_dependency_parity'] ?? null);
file_put_contents($output . '/summary.json', $json($summary));
print $json($summary);
