<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A priority menu marks the items it measured to fit `visible`. No rule names
// the token, and a route captured before the script ran (about) lacks it: the
// token is no part of the header's design, so the served link (marked only by
// a class swap) has one resting look and every route shares the header.
$page = static function (string $title, string $served, bool $measured): string {
    $item = 'item' . ($measured ? ' visible' : '');
    $link = static fn(string $url): string => $url === $served ? 'k2' : 'k1';
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>'
        . '<header class="bar"><nav><ul class="menu"><li class="' . $item . '"><a href="/" class="' . $link('/') . '">Home</a></li><li class="' . $item . '"><a href="/about/" class="' . $link('/about/') . '">About</a></li><li class="' . $item . '"><a href="/contact/" class="' . $link('/contact/') . '">Contact</a></li></ul></nav></header>'
        . '<main><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main></body></html>';
};
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Welcome home', '/', true),
    'about/index.html' => $page('About us', '/about/', false),
    'contact/index.html' => $page('Contact us', '/contact/', true),
    'style.css' => '.bar{padding:16px;background:#222}.menu{display:flex;gap:8px;list-style:none}.item{padding:4px}.k1{color:#fff}.k2{color:#ff0;font-weight:700}',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Routes differing only in an unstyled reveal-state class share one header part, got ' . count($headers));
foreach ($plan['pages'] as $routePage) $assert(!str_contains($routePage['canonical_block_markup'], 'wp:navigation'), "{$routePage['source_path']} keeps no inline header.");
$assert(!preg_match('/(?<![\w-])visible(?![\w-])/', $headers[0]['canonical_block_markup']), 'The shared header drops the unstyled reveal-state class.');
echo "navigation unstyled reveal state shared header: ok\n";
