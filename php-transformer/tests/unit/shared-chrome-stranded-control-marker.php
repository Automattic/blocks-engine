<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

// A route-kept hook keeps the element's generated and authored styles on
// the route together. Moving even its visibility rule to shared chrome can
// create another globally enqueued support sheet and grow unrelated routes.
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
if (str_contains($shared, $control)) throw new RuntimeException('Route-kept control created global CSS: ' . $shared);
if (!str_contains($route('support.css'), $hide) || !str_contains($route('support.css'), '.fitment-intro')) throw new RuntimeException('Route-kept controls retain their support rules: ' . $route('support.css'));
if ($author !== $route('site.css') || str_contains($shared, 'letter-spacing')) throw new RuntimeException('Author rules of a route-kept chrome element stay in one route stylesheet: ' . $route('site.css') . "\n" . $shared);
if (!str_contains($route('support.css'), $presentation) || str_contains($shared, 'padding:24px')) throw new RuntimeException('Stranded control presentation must not spread to other routes: ' . $shared);
echo "Shared chrome stranded control marker passed\n";
