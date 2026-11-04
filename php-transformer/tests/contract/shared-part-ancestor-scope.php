<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Two routes include the same header inside the page wrapper `#app`; the entry
// route carries its own, different header inline. Every authored rule for the
// header's call-to-action is scoped by `#app`, which wraps the header on every
// member page but is not part of the header itself. The route-shared header is
// compiled as one canonical shell, and it must compile with the ancestor
// context its members share, as it renders on each of them.
$fixture = dirname(__DIR__) . '/fixtures/shared-part-ancestor-scope/';
$files = array();
foreach (array('index.html', 'about.html', 'team.html', 'parts/header.html', 'site.css') as $path) {
    $files[$path] = (string) file_get_contents($fixture . $path);
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null) && 'inline_shared_shell' === ($part['placement']['kind'] ?? null)));
$assert(1 === count($headers), 'The included header is one route-shared template part.');
$sources = $headers[0]['placement']['source_paths'] ?? array();
sort($sources);
$assert(array('about.html', 'team.html') === $sources, 'The shared header is shared by the routes that include it.');
$header = (string) $headers[0]['canonical_block_markup'];

// `#app :where(.cta).button .icon.icon { display: none }` hides the icon on
// every member page, so the shell carries no icon at all, let alone one at its
// 200px intrinsic size.
$assert(! str_contains($header, '<img') && ! str_contains($header, '<svg') && ! str_contains($header, '200px'), 'The icon an ancestor-scoped rule hides stays hidden in the shared part: ' . $header);
// `#app :where(.cta)` styles the call-to-action as a button on every member page.
$assert(str_contains($header, '<!-- wp:button ') && str_contains($header, 'Order now'), 'The call-to-action stays one button.');
$assert(str_contains($header, 'border-radius:999px') && str_contains($header, 'border-width:1px'), 'The ancestor-scoped button styles resolve in the shared part.');

// The ancestor context only supplies style resolution: it never becomes
// content of the part, and a page's own wrapper id does not seed the shell.
$assert(! str_contains($header, 'route-about') && ! str_contains($header, 'route-team') && ! str_contains($header, 'route'), 'Member-specific ancestor attributes do not seed the shared part.');
$assert(! preg_match('/id="app"|"anchor":"app"/', $header), 'The shared ancestor is context, not part content.');
$assert(str_starts_with(ltrim($header), '<!-- wp:group {"anchor":"masthead"'), 'The header landmark is the part root.');

$pages = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
$assert(str_contains((string) $pages['index.html'], 'Order today'), 'The entry route keeps its own inline header.');
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$assert(str_contains((string) ($templates['page-team'] ?? ''), '"slug":"header"') && ! str_contains((string) $templates['page-team'], 'Order now'), 'A member route renders the shared header part instead of a copy.');

fwrite(STDOUT, "shared-part-ancestor-scope contract passed\n");
