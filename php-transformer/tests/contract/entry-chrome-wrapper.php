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
$assert(is_array($part) && 'inline_shared_shell' === $part['placement']['kind'] && 3 === count($part['placement']['source_paths']), 'Every route references one shared header owner at its source position.');
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$home = array_values(array_filter($plan['pages'], static fn (array $row): bool => !empty($row['entrypoint'])))[0];
$front = $home['canonical_block_markup'];
$assert(1 === preg_match('/<!-- wp:group \{[^>]*"anchor":"pin"[^>]*--><div id="pin"[^>]*>\s*<!-- wp:template-part \{"slug":"header"[^>]*\/-->\s*<\/div><!-- \/wp:group -->/', $front), 'The source-position reference remains inside the home page\'s pinned layer: ' . substr($front, 0, 400));
$assert(!str_contains((string) ($templates['page'] ?? ''), 'id="pin"'), 'Other templates render the shared header without that layer.');
$assert(!str_contains((string) ($templates['front-page'] ?? ''), 'wp:template-part'), 'The front-page template does not add a detached duplicate header.');
foreach ($plan['pages'] as $page) if (!$page['entrypoint']) $assert(!str_contains($page['canonical_block_markup'], 'id="pin"') && str_contains($page['canonical_block_markup'], '"slug":"header"'), 'Other routes share the content without inheriting the pinned layer.');

echo "Entry chrome wrapper contract passed.\n";
