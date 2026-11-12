<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Two shared footer icons plus a different footer. A page-local icon on the
// first route makes its support payload differ, so whole-file deduplication
// alone cannot hide the repeated footer rule.
$files = array();
foreach (array('index.html', 'about.html', 'contact.html') as $index => $path) {
    $footer = $index < 2
        ? '<footer><nav aria-label="Profiles"><a href="https://example.com/profile" aria-label="Profile"><svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg></a></nav></footer>'
        : '<footer><p>Contact information</p></footer>';
    $files[$path] = '<!doctype html><html><head><title>Page</title><style>footer svg{width:24px;height:24px}</style></head><body><main><h1>Page ' . $index . '</h1>' . (0 === $index ? '<nav aria-label="More"><a href="https://example.com/more" aria-label="More"><svg width="16" height="16" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6"/></svg></a></nav>' : '') . '</main>' . $footer . '</body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$sheets = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'engine-support' === ($asset['source'] ?? '') && str_contains($asset['content'], 'data:image/svg+xml')));
$copies = 0;
$bytes = 0;
foreach ($sheets as $sheet) {
    $copies += substr_count($sheet['content'], 'M0%200h24v24H0z');
    $bytes += strlen($sheet['content']);
}
echo "Navigation icon support: {$copies} shared-icon copies, " . count($sheets) . " sheets, {$bytes} bytes\n";
$assert(1 === $copies, 'An icon used by two of three routes is emitted once.');
$sheets = array_values(array_filter($sheets, static fn(array $sheet): bool => str_contains($sheet['content'], 'M0%200h24v24H0z')));
foreach (array_keys($files) as $index => $source) {
    $loads = in_array($sheets[0]['target_path'], array_column(WordPressSitePlan::documentStylesheets($plan, $source), 'target_path'), true);
    $assert($loads === ($index < 2), 'Only routes owning the icon load its shared resource.');
}
// Exercise the allocator's URL boundary independently of icon lowering.
$allocate = new ReflectionMethod(WordPressSitePlan::class, 'allocateDocumentSupport');
foreach (array('url("data:image/svg+xml,%3Csvg/%3E")', "url('data:image/svg+xml,%3Csvg/%3E')", 'url(data:image/svg+xml,%3Csvg/%3E)', 'url(../images/icon.svg)', 'url("#icon")') as $url) {
    $assets = array();
    foreach (array('index.html', 'about.html') as $source) {
        $assets[] = array('source' => 'engine-support', 'kind' => 'css', 'content' => '@media(min-width:1px){.icon{background-image:' . $url . '}}', 'scopes' => array(array('kind' => 'page', 'source_path' => $source)), 'stylesheet_placement' => 'after-author');
    }
    $allocated = $allocate->invoke(null, $assets, array_keys($files));
    $assert(count($allocated) === (str_contains($url, 'data:') ? 1 : 2), 'Only self-contained URL rules may move: ' . $url);
    $assert(str_contains($allocated[0]['content'], '@media(min-width:1px){.icon{background-image:' . $url . '}}'), 'URL bytes and conditional wrappers survive allocation.');
}
echo "Navigation icon subset sharing passed\n";
