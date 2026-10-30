<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Every file arrives as `content_base64`, as an uploaded archive delivers it.
// The library stylesheet's href is percent-encoded (`%40` for `@`), so it is
// rewritten late, by preset binding, rather than projected early. A text
// asset rewritten in place must not keep a transport twin that describes the
// bytes as delivered, or the site plan contradicts itself and is rejected.
$style = '<style>body{background:#ffffff;color:#222222;margin:0}h1{color:#222222}a{color:#0a7}</style>';
$page = static fn(string $title, string $head = ''): string => '<!doctype html><html><head><meta charset="utf-8"><title>Min</title>' . $head . $style . '</head><body><main><h1>' . $title . '</h1><p>Body text <a href="/">link</a>.</p><div class="box">Box</div></main></body></html>';
$library = '.widget-pane{background:#ffffff;color:#222222;padding:8px}';
$file = static fn(string $path, string $content): array => array('path' => $path, 'content_base64' => base64_encode($content));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    $file('index.html', $page('Hello', '<link rel="stylesheet" href="/external/abc/lib%401.0/lib.css">')),
    $file('about/index.html', $page('About')),
    $file('external/abc/lib@1.0/lib.css', $library),
)))->toArray();
$messages = implode(' | ', array_map(static fn(array $diagnostic): string => (string) ($diagnostic['message'] ?? ''), $result['diagnostics'] ?? array()));
$assert(in_array($result['status'] ?? '', array('success', 'success_with_warnings'), true), 'An archive-delivered site whose stylesheet is rewritten in place compiles: ' . $messages);
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$assets = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_contains((string) ($asset['content'] ?? ''), '.widget-pane{')));
$assert(array() !== $assets, 'The library stylesheet is a site plan asset.');
foreach ($assets as $asset) {
    $assert(str_contains($asset['content'], 'var(--wp--preset--color--'), 'The library stylesheet was rewritten in place by preset binding: ' . $asset['content']);
    $assert(!isset($asset['content_base64']) && !isset($asset['transport_sha256']), 'A rewritten text asset drops its transport twin: ' . $asset['target_path']);
    $assert(hash('sha256', $asset['content']) === $asset['content_hash'] && strlen($asset['content']) === $asset['bytes'], 'A rewritten text asset hashes and counts its rewritten content: ' . $asset['target_path']);
}

// The helper itself keeps every content field consistent.
$rewritten = WordPressSitePlan::withRewrittenTextContent(array('content' => 'a{}', 'content_base64' => base64_encode('a{}'), 'transport_sha256' => 'x', 'hash' => 'y', 'bytes' => 3, 'content_hash' => 'z'), 'b{color:red}');
$assert(array('content' => 'b{color:red}', 'hash' => hash('sha256', 'b{color:red}'), 'bytes' => 12, 'content_hash' => hash('sha256', 'b{color:red}')) == $rewritten, 'withRewrittenTextContent sets content, bytes and hashes and drops the transport twin: ' . json_encode($rewritten));

echo "Rewritten asset transport twin: ok\n";
