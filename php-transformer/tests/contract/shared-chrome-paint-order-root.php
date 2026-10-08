<?php
declare(strict_types=1);

/**
 * The detached-chrome paint-order rule from #2260 (`:where(#root){z-index:1}`)
 * must address the shared header part's root, never a descendant.
 *
 * A hoisted header renders before post-content, so page content that preceded
 * it in the source (a fixed page background) would otherwise paint over it.
 * The rule lifts the part's root. When it instead names the first anchored
 * group inside the part, a Wix header's background layer
 * (`#bgLayers_comp-…`, positioned, painted black as a 1px frame beneath the
 * content) is lifted above its own sibling content and the whole header
 * renders as a black box.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$links = '<nav><a href="index.html">Home</a><a href="about.html">About</a><a href="shop.html">Shop</a></nav>';
$background = '<div class="page-bg" style="position:fixed;inset:0;background:#eee"></div>';
$layers = '<div id="bgLayers_strip" class="layers" style="position:absolute;inset:0;background:#000"></div>';
$content = '<div class="content" style="position:relative;margin:1px;background:#ffd">' . $links . '</div>';

/** @return array{part: array<string,mixed>, css: string} */
$compile = static function (string $header, bool $preceded) use ($background): array {
    $document = static function (string $title) use ($header, $preceded, $background): string {
        $main = '<main><h1>' . $title . '</h1></main>';
        $before = $preceded ? $background : '';
        return '<div class="data-liberation-desktop-document"><div class="frame">' . $before . $header . $main . '</div></div>'
            . '<div class="data-liberation-mobile-document"><div class="frame">' . $before . str_replace('>Shop<', '>Shop now<', $header) . $main . '</div></div>';
    };
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $document('Home'),
        'about.html' => $document('About'),
        'shop.html' => $document('Shop'),
    )))->toArray()['source_reports']['wordpress_site_plan'];
    $part = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
    $css = implode("\n", array_map(
        static fn(array $asset): string => (string) ($asset['content'] ?? ''),
        array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) ($asset['path'] ?? ''), 'shared-chrome-context'))
    ));

    return array('part' => $part, 'css' => $css);
};

// 1. The #2260 intent: a header preceded by a fixed page background paints above it.
$anchored = $compile('<header id="site-header" class="site-header" style="position:relative">' . $layers . $content . '</header>', true);
$assert('shared_shell' === ($anchored['part']['placement']['kind'] ?? null) && !empty($anchored['part']['ancestor_context']['preceded']), '1a: the fixture hoists a preceded header into a shared part: ' . json_encode($anchored['part']['placement'] ?? null));
$assert(str_contains($anchored['css'], ':where(#site-header){z-index:1}'), '1b: the preceded header part root is lifted above earlier page content: ' . $anchored['css']);
$assert(!str_contains($anchored['css'], 'bgLayers_strip'), '1c: the background layer inside the header is not lifted: ' . $anchored['css']);

// 2. A header root without an id: lift nothing rather than the first anchored descendant.
$unanchored = $compile('<header class="site-header" style="position:relative">' . $layers . $content . '</header>', true);
$assert('shared_shell' === ($unanchored['part']['placement']['kind'] ?? null), '2a: the unanchored header still hoists into a shared part.');
$assert(!str_contains($unanchored['css'], 'bgLayers_strip'), '2b: no descendant is lifted when the part root has no anchor: ' . $unanchored['css']);
$assert(!str_contains($unanchored['css'], 'z-index'), '2c: no paint-order rule without a root anchor: ' . $unanchored['css']);

// 3. A header that was first in its page needs no paint-order rule.
$first = $compile('<header id="site-header" class="site-header" style="position:relative">' . $layers . $content . '</header>', false);
$assert(!str_contains($first['css'], 'z-index'), '3: an unpreceded header gets no paint-order rule: ' . $first['css']);

// 4. A root that is not a group block (Wix's scroll-state header) still names
// the root, inside each viewport partition, and never the background layer.
$method = new ReflectionMethod(WordPressSitePlan::class, 'partRootAnchors');
$variant = static fn(string $class, string $id): string => '<!-- wp:group {"className":"' . $class . '"} -->' . "\n" . '<div class="wp-block-group ' . $class . '">'
    . '<!-- wp:custom/scroll-state {"tagName":"header","anchor":"' . $id . '"} --><header id="' . $id . '">'
    . '<!-- wp:group {"anchor":"bgLayers_' . $id . '"} --><div id="bgLayers_' . $id . '" class="wp-block-group"></div><!-- /wp:group -->'
    . '</header><!-- /wp:custom/scroll-state --></div>' . "\n" . '<!-- /wp:group -->';
$roots = $method->invoke(null, $variant('data-liberation-desktop-document', 'SITE_HEADER') . "\n" . $variant('data-liberation-mobile-document', 'SITE_HEADER--dla-mobile'));
$assert(array('SITE_HEADER', 'SITE_HEADER--dla-mobile') === $roots, '4a: each viewport partition contributes its own root anchor: ' . json_encode($roots));
$plain = $method->invoke(null, '<!-- wp:group {"anchor":"site-top","tagName":"header"} --><header id="site-top" class="wp-block-group"><!-- wp:group {"anchor":"inner"} --><div id="inner" class="wp-block-group"></div><!-- /wp:group --></header><!-- /wp:group -->');
$assert(array('site-top') === $plain, '4b: an unpartitioned part names its single root: ' . json_encode($plain));
$wrapped = $method->invoke(null, '<!-- wp:group {"className":"shell"} --><div class="wp-block-group shell"><!-- wp:group {"anchor":"inner"} --><div id="inner" class="wp-block-group"></div><!-- /wp:group --></div><!-- /wp:group -->');
$assert(array() === $wrapped, '4c: an unanchored root is not replaced by an anchored descendant: ' . json_encode($wrapped));
$siblings = $method->invoke(null, '<!-- wp:group {"anchor":"a"} --><div id="a" class="wp-block-group"></div><!-- /wp:group --><!-- wp:group {"anchor":"b"} --><div id="b" class="wp-block-group"></div><!-- /wp:group -->');
$assert(array() === $siblings, '4d: a part with several top-level blocks outside viewport partitions has no single root: ' . json_encode($siblings));

if ($failures > 0) {
    fwrite(STDERR, sprintf("%d failure(s), %d pass(es)\n", $failures, $passes));
    exit(1);
}
echo sprintf("shared chrome paint-order root contract passed (%d assertions)\n", $passes);
