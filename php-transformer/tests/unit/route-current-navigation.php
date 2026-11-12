<?php
declare(strict_types=1);

/**
 * Client routers (React Router's NavLink, for one) often mark the current
 * menu item only through utility classes: no aria-current and no
 * active/current token. The link targeting the compiled document is then the
 * only current signal. Without it, each page's header differs by one item's
 * classes, the header never becomes a shared template part, and an owner has
 * to edit the menu on every page.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RestingNavigation;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$css = '<style>.nav{display:flex;gap:2rem}.link{color:#fff}.link:hover{color:#e8501c}.is-on{color:#e8501c}</style>';
$header = static fn (string $current): string => '<header><nav class="nav">'
    . '<a class="link' . ('about' === $current ? ' is-on' : '') . '" href="' . ('about' === $current ? '/about/index.html' : '/about/index.html') . '">About</a>'
    . '<a class="link' . ('books' === $current ? ' is-on' : '') . '" href="/books/index.html">Books</a>'
    . '<a class="link' . ('contact' === $current ? ' is-on' : '') . '" href="/contact/index.html">Contact</a>'
    . '</nav></header><main><p>Body</p></main>';
$compile = static fn (string $current, string $source): string => (string) (( new HtmlTransformer() )->transform($css . $header($current), array( 'source' => $source ))->toArray()['serialized_blocks'] ?? '');
$currentLabels = static function (string $markup): array {
    preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $markup, $m);
    $labels = array();
    foreach ( $m[1] as $json ) {
        $attrs = json_decode($json, true);
        if ( str_contains((string) ($attrs['className'] ?? ''), 'blocks-engine-current-navigation-item') ) $labels[] = strip_tags((string) $attrs['label']);
    }
    return $labels;
};

$about = $compile('about', 'website/about/index.html');
$books = $compile('books', 'website/books/index.html');
$assert(array( 'About' ) === $currentLabels($about), 'a site-rooted link to the compiled document is the current item', json_encode($currentLabels($about)));
$assert(array( 'Books' ) === $currentLabels($books), 'each page marks its own link', json_encode($currentLabels($books)));
$assert(array() === $currentLabels($compile('about', 'website/index.html')), 'a nested page link is not current on the entry page');
$assert(array() === $currentLabels($compile('about', 'fixture:about')), 'a source without an artifact path never infers current state');

$relative = (string) (( new HtmlTransformer() )->transform('<header><nav><a href="../">Home</a><a href="../books/">Books</a></nav></header>', array( 'source' => 'website/books/index.html' ))->toArray()['serialized_blocks'] ?? '');
$assert(array( 'Books' ) === $currentLabels($relative), 'a relative directory href resolves to its index document', json_encode($currentLabels($relative)));

$identity = new ReflectionMethod(ChromeIdentity::class, 'markup');
$part = new ReflectionMethod(RestingNavigation::class, 'markup');
$headerOf = static function (string $markup): string {
    $start = strpos($markup, '<!-- wp:navigation ');
    return substr($markup, $start, strpos($markup, '<!-- /wp:navigation -->') + strlen('<!-- /wp:navigation -->') - $start);
};
$assert($identity->invoke(null, $headerOf($about)) === $identity->invoke(null, $headerOf($books)), 'headers that differ only by the routed current item share one identity');
$aboutPart = (string) $part->invoke(null, $headerOf($about));
$booksPart = (string) $part->invoke(null, $headerOf($books));
$assert(! str_contains($aboutPart, 'is-on') && ! str_contains($booksPart, 'is-on'), 'the shared part does not freeze one page\'s selected classes', $aboutPart);

// Exercise the emitted runtime, including the importer seam for non-page
// documents. The native page route deliberately disagrees with the filter.
$artifact = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<header><nav><a href="/">Home</a><a href="/accessories/">Accessories</a></nav></header><main>Home</main>'),
))->toArray();
$bootstrap = '';
foreach ($artifact['source_reports']['wordpress_site_plan']['writes'] as $write) {
    if ('functions.php' === ($write['target_path'] ?? null)) $bootstrap = $write['payload']['data'];
}
$runtime = array();
foreach (array('navigation-link', 'navigation-submenu', 'navigation') as $name) {
    $registration = strpos($bootstrap, "add_filter( 'render_block_core/$name',");
    if (false === $registration) {
        // Trunk has no container pass; keep exercising its existing item pass
        // so the regression fails on behavior rather than closure discovery.
        $runtime[$name] = static fn(string $content): string => $content;
        continue;
    }
    $start = strpos($bootstrap, 'static function ( string $content', $registration);
    $end = strpos($bootstrap, "\n}, 10, ", $start);
    eval('$runtime[$name] = ' . substr($bootstrap, $start, $end + 2 - $start) . ';');
}
function is_singular(): bool { return true; }
function get_queried_object_id(): int { return 42; }
function is_front_page(): bool { return false; }
function get_post_type(int $id): string { global $navigationPostType; return $navigationPostType; }
function get_page_uri(int $id): string { return 'unrelated-page'; }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    global $navigationServed, $navigationFilterCalls;
    if ('blocks_engine_served_route' !== $hook) return $value;
    $navigationFilterCalls[] = array($value, $args[0]);
    return $navigationServed;
}
$links = array('/', '/accessories/', '/about/');
foreach (array(
    array('/accessories/p/sticker', $links, '/accessories/', true),
    array('/accessories', $links, '/accessories/', false),
    array('/accessoriesx/p/a', $links, null, false),
    array('/a/b/c', array('/a/', '/a/b/'), '/a/b/', true),
    array('/a/b', array('/a/', '/a/b/'), '/a/b/', false),
    array('/unlinked', array('/'), null, false),
    array(null, $links, null, false),
) as [$navigationServed, $urls, $expected, $ancestor]) {
    foreach (array('product', 'page') as $navigationPostType) {
        foreach (array('navigation-link', 'navigation-submenu') as $itemType) {
            $navigationFilterCalls = array();
            $content = '<ul class="wp-block-navigation__container">';
            foreach ($urls as $url) {
                $item = '<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="' . $url . '">Link</a></li>';
                $content .= $runtime[$itemType]($item, array('attrs' => array('url' => $url)));
            }
            $content = $runtime['navigation']($content . '</ul>');
            preg_match_all('/<a\\b[^>]*aria-current="page"[^>]*>/', $content, $currentAnchors);
            $actual = array();
            foreach ($currentAnchors[0] as $anchor) {
                preg_match('/href="([^"]*)"/', $anchor, $href);
                $actual[] = $href[1];
            }
            $assert($actual === (null === $expected ? array() : array($expected)), 'generated navigation chooses exact or longest section: ' . var_export($navigationServed, true), $content);
            $assert($ancestor === str_contains($content, 'current-menu-ancestor'), 'only ancestry gets the ancestor class', $content);
            $assert(array() !== $navigationFilterCalls && array_reduce($navigationFilterCalls, static fn(bool $valid, array $call): bool => $valid && $call === array('page' === $navigationPostType ? '/unrelated-page' : null, 42), true), 'served-route filter receives the computed route and document');
        }
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, "route current navigation: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "route current navigation: {$passes} passed\n";
