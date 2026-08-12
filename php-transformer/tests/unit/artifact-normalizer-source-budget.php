<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$html = '<!doctype html><html><head>' . str_repeat('<style>.x{color:red}</style>', 100) . '<link rel="preload" href="/_runtimes/site.js" as="script"></head><body></body></html>';
$result = (new ArtifactNormalizer())->normalize(array(
    'entrypoint' => 'website/index.html',
    'compiler_limits' => array('max_files' => 100),
    'files' => array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'website/_runtimes/site.js', 'content' => 'export const site = true;'),
    ),
));

$paths = array_column($result['files'], 'path');
$assert(in_array('website/index.html', $paths, true), 'The declared entrypoint retains admission priority.');
$assert(in_array('website/_runtimes/site.js', $paths, true), 'A declared runtime asset cannot be starved by generated inline expansions.');
$assert(count($result['files']) === 100, 'The configured file limit remains enforced.');
$assert(in_array('file_limit_exceeded', array_column($result['diagnostics'], 'code'), true), 'Generated overflow remains observable.');

echo "artifact normalizer source budget: ok\n";
