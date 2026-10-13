<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Every page shares one header, but the home page holds it inside a pinned
// layer the other pages lack. The layer belongs to the home page's chrome: it
// wraps the shared reference at its source position, while the other pages
// reference that same part without inheriting the home page's layer.
$header = '<header id="top"><p class="brand">Studio</p><nav><a href="index.html">Home</a><a href="about.html">About</a><a href="team.html">Team</a></nav></header>';
$page = static fn (string $chrome, string $title): string => '<!doctype html><html><head><style>.pin{position:sticky;top:0;z-index:52;padding:4px}#top{background:#fff;padding:8px}</style></head><body><div id="root"><div class="grid">'
    . $chrome . '<main><h1>' . $title . '</h1><p>Body for ' . $title . '</p></main></div></div></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('<div id="pin" class="pin">' . $header . '</div>', 'Home'),
    'about.html' => $page($header, 'About'),
    'team.html' => $page($header, 'Team'),
)))->toWordPressSitePlanView()['wordpress_site_plan'];

$part = array_values(array_filter($plan['template_parts'], static fn (array $row): bool => 'header' === ($row['area'] ?? null)))[0] ?? null;
$assert(is_array($part) && 'inline_shared_shell' === $part['placement']['kind'] && 3 === count($part['placement']['source_paths']), 'Every route shares one header owner.');
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$home = array_values(array_filter($plan['pages'], static fn (array $row): bool => !empty($row['entrypoint'])))[0];
$assert(!str_contains($home['canonical_block_markup'], 'wp:template-part') && !str_contains($home['canonical_block_markup'], 'id="pin"'), 'The home page content holds no shared header and no pinned layer.');
$assert(1 === preg_match('/<!-- wp:group \{[^>]*"anchor":"pin"[^>]*--><div id="pin"[^>]*>\s*<!-- wp:template-part \{"slug":"header"[^>]*\/-->\s*<\/div><!-- \/wp:group -->\s*<!-- wp:post-content/', (string) ($templates['front-page'] ?? '')), 'The front-page template keeps the pinned layer around the shared header: ' . substr((string) ($templates['front-page'] ?? ''), 0, 600));
$assert(!str_contains((string) ($templates['page'] ?? ''), 'id="pin"') && 1 === substr_count((string) ($templates['page'] ?? ''), '"slug":"header"'), 'Other templates render the shared header without that layer.');
foreach ($plan['pages'] as $page) if (!$page['entrypoint']) $assert(!str_contains($page['canonical_block_markup'], 'id="pin"') && !str_contains($page['canonical_block_markup'], 'wp:template-part'), 'Other routes keep only their own content.');

echo "Entry chrome wrapper contract passed.\n";
