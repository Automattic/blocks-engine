<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Route-specific capture IDs must affect neither conversion nor shared chrome.
$document = static fn(string $title): string => '<!doctype html><html><head><title>' . $title . '</title><style>.c{display:flex;gap:10px}.hero{display:flex;gap:24px}.hero img{width:240px;height:160px;object-fit:cover}</style></head><body>'
    . '<header><nav><ul><li><a href="/about"><span class="w"><span class="label">About</span></span></a></li><li><a href="/contact">Contact</a></li></ul></nav>'
    . '<section class="hero"><img src="https://example.com/hero.png" alt="Hero"><div><h2>Welcome</h2><p>Shared introduction</p></div></section></header>'
    . '<main><h1>' . $title . '</h1><div class="c"><div class="w"><div class="w"><div class="w"><p>Hello world</p></div></div></div>'
    . '<div class="w"><div class="w"><img src="https://example.com/a.png" alt="x"></div></div></div></main></body></html>';
$stamp = static function (string $source, string $route): string {
    $index = 0;
    return preg_replace_callback('/<([a-z][a-z0-9]*)\b/', static function (array $match) use (&$index, $route): string {
        return '<' . $match[1] . ' data-blocks-engine-node-id="' . $route . '-' . $index++ . '"';
    }, $source);
};
$cases = array(
    'single page' => static function (bool $stamped) use ($document, $stamp): array {
        $html = $document('Home');
        return (new HtmlTransformer())->transform($stamped ? $stamp($html, 'home') : $html)->toArray();
    },
    'two routes with shared header' => static function (bool $stamped) use ($document, $stamp): array {
        $files = array();
        foreach (array('index.html' => 'Home', 'about.html' => 'About') as $path => $title) {
            $html = $document($title);
            $files[$path] = $stamped ? $stamp($html, $title) : $html;
        }
        return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    },
);
foreach ($cases as $name => $compile) {
    $plain = $compile(false);
    $withIds = $compile(true);
    if (array() === $plain['assets']) throw new RuntimeException("{$name}: fixture must emit CSS assets");
    if ('two routes with shared header' === $name && array() === ($plain['source_reports']['wordpress_site_plan']['template_parts'] ?? array())) {
        throw new RuntimeException('Two-route fixture must extract a shared header');
    }
    foreach (array(
        'serialized_blocks' => static fn(array $result): mixed => $result['serialized_blocks'],
        'CSS assets' => static fn(array $result): array => array_values(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '') || str_ends_with($asset['path'] ?? $asset['source_path'] ?? '', '.css'))),
        'site-plan parts' => static fn(array $result): array => $result['source_reports']['wordpress_site_plan']['template_parts'] ?? array(),
    ) as $output => $select) {
        if ($select($plain) !== $select($withIds)) throw new RuntimeException("{$name}: capture node IDs changed {$output}");
    }
    if (!str_contains($plain['serialized_blocks'], 'blocks-engine-media-text-image-')) throw new RuntimeException("{$name}: fixture must exercise media-text marker identity");
}
echo "ok\n";
