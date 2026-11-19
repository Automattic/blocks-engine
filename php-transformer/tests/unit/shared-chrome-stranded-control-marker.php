<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

// Issue #5860: routes compile their own copy of one source-equal header; the
// shared part keeps one capture's control marker. The header's mobile-only
// "Menu" button also carries a subject marker that route content uses, so its
// author rules stay on the route. Its generated support rules (the wrapper
// hidden from the md breakpoint) were emitted only by the chosen capture's
// support sheet, though: kept there with the author rules, every other route
// adopting the part rendered "Menu" on desktop.
$control = 'blocks-engine-control-07832227e5d6-0';
$subject = 'blocks-engine-subject-class-d2cf63c704ae';
$hide = '@media (width>=48rem){.' . $control . '.' . $control . '.wp-block-buttons:has(>.' . $control . '.wp-block-button:only-child){display:none!important}}';
$presentation = '.' . $control . '{padding:24px;color:red}';
$author = ':where(.' . $control . ')>:where(.wp-block-button__link){letter-spacing:.2em}.' . $subject . '{text-transform:uppercase}';
$assets = array(
    array('kind' => 'css', 'source' => 'engine-support', 'content' => $hide . $presentation . '.fitment-intro{color:red}', 'source_path' => 'support.css', 'path' => 'support.css', 'target_path' => 'assets/support.css', 'scopes' => array(array('kind' => 'page', 'source_path' => 'fitment.html'))),
    array('kind' => 'css', 'source' => 'author', 'content' => $author, 'source_path' => 'site.css', 'path' => 'site.css', 'target_path' => 'assets/site.css', 'scopes' => array(array('kind' => 'global'))),
);
$classes = $control . ' ' . $subject;
$parts = array(array(
    'slug' => 'header',
    'area' => 'header',
    'placement' => array('kind' => 'shared_shell', 'source_paths' => array('fitment.html', 'about.html')),
    'canonical_block_markup' => '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:buttons {"className":"' . $classes . '"} --><div class="wp-block-buttons ' . $classes . '"><!-- wp:button {"className":"md:hidden ' . $classes . '"} --><div class="wp-block-button md:hidden ' . $classes . '"><button type="button" class="wp-block-button__link wp-element-button">Menu</button></div><!-- /wp:button --></div><!-- /wp:buttons --></header><!-- /wp:group -->',
));
$pages = array(
    array('source_path' => 'fitment.html', 'canonical_block_markup' => '<!-- wp:paragraph {"className":"fitment-intro"} --><p class="fitment-intro">Fitment</p><!-- /wp:paragraph -->'),
    array('source_path' => 'about.html', 'canonical_block_markup' => '<!-- wp:paragraph {"className":"' . $subject . '"} --><p class="' . $subject . '">About</p><!-- /wp:paragraph -->'),
);
$projected = SharedChromeStylesheets::project($assets, $parts, $pages);
$shared = implode("\n", array_map(static fn (array $asset): string => (string) $asset['content'], array_filter($projected, static fn (array $asset): bool => str_ends_with((string) ($asset['source_path'] ?? ''), '.shared-chrome'))));
$route = static function (string $source) use ($projected): string {
    foreach ($projected as $asset) if ($source === ($asset['source_path'] ?? null)) return (string) $asset['content'];
    throw new RuntimeException('Missing route stylesheet ' . $source);
};
if (!str_contains($shared, ':has(>.' . $control . '.wp-block-button:only-child){display:none!important}') || !str_contains($shared, 'width>=48rem')) throw new RuntimeException('The part control\'s generated wrapper visibility must render on every adopting route: ' . $shared);
if (str_contains($route('support.css'), $hide) || !str_contains($route('support.css'), '.fitment-intro')) throw new RuntimeException('Only route content keeps its generated support rules on the route: ' . $route('support.css'));
if ($author !== $route('site.css') || str_contains($shared, 'letter-spacing')) throw new RuntimeException('Author rules of a route-kept chrome element stay in one route stylesheet: ' . $route('site.css') . "\n" . $shared);
if (!str_contains($route('support.css'), $presentation) || str_contains($shared, 'padding:24px')) throw new RuntimeException('Stranded control presentation must not spread to other routes: ' . $shared);
echo "Shared chrome stranded control marker passed\n";
