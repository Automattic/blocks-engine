<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

// Only these four source rules trigger replay. The flex pair lowers to
// media-text (with per-image markers); the presentation table lowers to cells.
// Vary route links so staged workers cannot reuse an identical page receipt.

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$compile = static function (int $pages, int $items): array {
    $css = 'nav .item a > img{width:24px}.list .card a img{height:20px}.menu li a > img{opacity:.8}';
    $files = array(
        array('path' => 'site.css', 'content' => $css, 'metadata' => array('compilation' => array('scope' => 'shared'))),
        array('path' => 'menu.css', 'content' => '.list .card a img{max-width:100%}', 'metadata' => array('compilation' => array('scope' => 'shared')))
    );
    for ($page = 0; $page < $pages; ++$page) {
        $nav = $cards = $menu = '';
        for ($item = 0; $item < $items; ++$item) {
            $image = '<a href="/target-' . $page . '-' . $item . '"><img src="icon.svg" width="240" height="200" alt="Icon"></a>';
            $nav .= '<div class="item">' . $image . '</div>';
            $cards .= '<div class="card" style="display:flex"><figure>' . $image . '</figure><div><h2>Card text</h2></div></div>';
            $menu .= '<li>' . $image . '</li>';
        }
        $files[] = array('path' => 0 === $page ? 'index.html' : 'page-' . $page . '.html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"><link rel="stylesheet" href="menu.css"></head><body><nav>' . $nav . '</nav><main><div class="list">' . $cards . '</div><table role="presentation"><tr><td><ul class="menu">' . $menu . '</ul></td><td><p>Cell text</p></td></tr></table></main></body></html>');
    }
    $files[] = array('path' => 'icon.svg', 'content' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="20"><path d="M0 0h24v20H0z"/></svg>');
    $artifact = array('entrypoints' => array('index.html'), 'files' => $files);
    $compiler = new ArtifactCompiler();
    $shared = $compiler->prepareShared($artifact);
    $receipts = array();
    foreach ($shared['analysis']['page_ids'] as $id) $receipts[] = $compiler->compilePage($artifact, $shared, $id);
    return $compiler->compose($shared, $receipts)->toArray();
};
$baseline = null;
foreach (array(array(1, 2), array(3, 2), array(3, 8)) as [$pages, $items]) {
    $result = $compile($pages, $items);
    $stats = array();
    $replay = '';
    foreach ($result['assets'] as $asset) {
        if ('wordpress-compat' !== ($asset['source'] ?? '')) continue;
        $css = $asset['content'];
        $replay = $css;
        $alternatives = 0;
        (new CssStylesheetTransformer())->transform($css, static function (string $prelude, string $body) use (&$alternatives): string {
            $alternatives += count(CssStylesheetTransformer::splitSelectorList($prelude) ?? array());
            return $prelude;
        });
        $stats[] = array(strlen($css), $alternatives);
    }
    echo $pages . ' pages x ' . $items . ' items: ' . json_encode($stats) . "\n";
    $assert(count($stats) === $pages, 'Each page has a compat receipt.');
    foreach ($stats as [$bytes, $alternatives]) {
        $assert($bytes < 2000 && $alternatives <= 8, 'Source rules have bounded replay, not generated transport cross products.');
        $baseline ??= array($bytes, $alternatives);
        $assert($baseline === array($bytes, $alternatives), 'Replay size is independent of pages and elements.');
    }
    if ($pages > 1) {
        $parents = array();
        foreach ($result['assets'] as $asset) {
            if (isset($asset['stylesheet_parent'])) $parents[$asset['stylesheet_parent']] = true;
        }
        $assert(isset($parents['site.css'], $parents['menu.css']), 'Multi-page growth fixture produces deltas for two different parent sheets.');
    }
    $plan = $result['source_reports']['wordpress_site_plan'];
    $sharedReplay = array_values(array_filter($plan['assets'], static fn (array $asset): bool => 'wordpress-compat' === ($asset['source'] ?? '')));
    $assert(1 === count($sharedReplay) && $pages === count($sharedReplay[0]['scopes']), 'Identical replay is one resource retaining all route scopes.');
    $assert(str_contains(json_encode($plan), 'blocks-engine-media-text-image-'), 'Fixture actually produces per-image transport markers.');

    // Core runtime navigation shapes: fused and descendant wrappers, plus the
    // source-list container. Match the replay against both layouts, including
    // a non-navigation negative control. Authored declarations must survive.
    $dom = new DOMDocument();
    $dom->loadHTML('<html><body><nav><div class="item wp-block-navigation"><a class="wp-block-navigation-item__content"><img id="direct"></a></div><div class="item"><div class="wp-block-navigation"><a class="wp-block-navigation-item__content"><img id="descendant"></a></div></div></nav><div class="list"><div class="card wp-block-navigation"><a class="wp-block-navigation-item__content"><img id="card"></a></div></div><nav class="menu wp-block-navigation"><ul class="wp-block-navigation__container"><li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content"><img id="menu"></a></li></ul></nav><img id="unrelated"></body></html>');
    $matched = array();
    (new CssStylesheetTransformer())->transform($replay, static function (string $prelude, string $body) use ($dom, &$matched): string {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse(trim($selector));
            foreach ($dom->getElementsByTagName('img') as $image) {
                if (CssSelectorMatcher::matches($image, $parsed)['matches']) $matched[$image->getAttribute('id')] = trim($body);
            }
        }
        return $prelude;
    });
    $assert('width:24px' === ($matched['direct'] ?? '') && 'width:24px' === ($matched['descendant'] ?? ''), 'Both navigation anchor shapes retain the replayed width.');
    $assert('max-width:100%' === ($matched['card'] ?? '') && 'opacity:.8' === ($matched['menu'] ?? ''), 'Card and list structure replay retain declarations.');
    $assert(!isset($matched['unrelated']), 'Replay does not style unrelated images.');
}

// A route-owned source delta is not linked from the HTML. It still contributes
// authored replay, without replaying its generated per-element alternatives.
$authored = array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => '<html><head><link rel="stylesheet" href="site.css"><link rel="stylesheet" href="later.css"></head><body><nav class="menu"><a href="/one">One</a></nav></body></html>', 'metadata' => array('compilation' => array('scope' => 'page', 'id' => 'index.html'))),
    array('path' => 'other.html', 'kind' => 'html', 'content' => '<html><head><link rel="stylesheet" href="site.css"><link rel="stylesheet" href="later.css"></head><body><nav class="menu"><a href="/two">Two</a></nav></body></html>', 'metadata' => array('compilation' => array('scope' => 'page', 'id' => 'other.html'))),
    array('path' => 'site.css', 'kind' => 'css', 'content' => '.menu a{color:red}'),
    array('path' => 'route.css', 'kind' => 'css', 'content' => '@media(min-width:700px){nav .item a > img{width:37px}}', 'metadata' => array('page_stylesheet_of' => 'site.css', 'compilation' => array('scope' => 'page', 'id' => 'other.html'))),
);
$authored[] = array('path' => 'later.css', 'kind' => 'css', 'content' => 'nav .item a > img{width:41px}');
$authored[] = array('path' => 'later-route.css', 'kind' => 'css', 'content' => '.menu a{color:blue}', 'metadata' => array('page_stylesheet_of' => 'later.css', 'compilation' => array('scope' => 'page', 'id' => 'other.html')));
$projected = $authored;
$projected[4]['content'] = 'nav .item a > img,:where(.generated-later){width:41px}';
$projected[5]['content'] = '.menu a,:where(.generated-delta){color:blue}';
$projected[3]['content'] = '@media(min-width:700px){nav .item a > img,:where(.blocks-engine-media-text-image-page-1),:where(.blocks-engine-media-text-image-page-2){width:37px}}';
$method = new ReflectionMethod(ArtifactCompiler::class, 'wordpressCompatAssetsForDocuments');
$assets = $method->invoke(new ArtifactCompiler(), $projected, $authored);
$byOwner = array();
foreach ($assets as $asset) $byOwner[$asset['compilation']['id']] = $asset['content'];
$assert(!str_contains($byOwner['index.html'], '37px'), 'A page-only delta must not leak to another route.');
$assert(str_contains($byOwner['other.html'], '@media(min-width:700px)') && str_contains($byOwner['other.html'], 'width:37px'), 'A navigation rule found only in the page delta retains conditional compat replay.');
$assert(!str_contains($byOwner['other.html'], 'blocks-engine-media-text-image-page-'), 'Page delta replay uses authored rules, not generated alternatives.');
$assert(1 === substr_count($byOwner['other.html'], 'width:37px') && strlen($byOwner['other.html']) < 2500, 'Page delta replay stays bounded by its authored rules.');
$assert(strpos($byOwner['other.html'], 'width:37px') < strpos($byOwner['other.html'], 'width:41px'), 'The later linked sheet wins over the earlier parent delta at equal specificity.');
$assert(str_contains($byOwner['other.html'], 'color:blue') && !str_contains($byOwner['index.html'], 'color:blue'), 'The second parent delta retains its route ownership.');
$assert(!str_contains($byOwner['other.html'], '.generated-'), 'Both parents and their deltas replay pre-projection content.');
echo 'Two-parent delta fixture compat bytes: ' . json_encode(array_map('strlen', $byOwner)) . "\n";
$reversed = $method->invoke(new ArtifactCompiler(), array_reverse($projected), array_reverse($authored));
$reversedByOwner = array();
foreach ($reversed as $asset) $reversedByOwner[$asset['compilation']['id']] = $asset['content'];
ksort($byOwner);
ksort($reversedByOwner);
$assert($byOwner === $reversedByOwner, 'Two-parent delta replay is independent of transport order.');

// Metadata, not generated CSS spelling, decides which authored subjects have
// already been carried natively. A sibling selector in the same list survives.
$nativeSource = $authored;
$nativeSource[2]['content'] = '.menu li a > img,.other li a > img{width:30px}';
$nativeProjection = $nativeSource;
$nativeProjection[2]['content'] = '.opaque-native-target{margin-right:30px}';
$nativeProjection[2]['metadata']['native_selectors'] = array('.menu li a > img');
$nativeAssets = $method->invoke(new ArtifactCompiler(), $nativeProjection, $nativeSource);
$assert(!str_contains($nativeAssets[0]['content'], '.menu') && str_contains($nativeAssets[0]['content'], '.other'), 'Skip exactly the recorded native selector, independent of projected spelling.');
$nativeProjection[2]['metadata']['native_selectors'] = array();
$nativeProjection[2]['content'] = '.menu .wp-block-navigation-item:not(be-t-0){margin-right:30px}';
$nativeAssets = $method->invoke(new ArtifactCompiler(), $nativeProjection, $nativeSource);
$assert(str_contains($nativeAssets[0]['content'], '.menu'), 'Native-looking projected text alone must not suppress authored replay.');
