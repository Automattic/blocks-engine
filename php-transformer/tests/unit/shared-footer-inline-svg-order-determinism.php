<?php
declare(strict_types=1);

// One capture compiles to one site plan, whatever order the page workers run
// in. The base44 shape: pages at different directory depths share a footer
// whose icons are inline lucide SVGs, and a fixed-position anchor follows the
// footer. The footer's chrome journal is recorded by whichever page worker
// reaches it first and replayed on the others; the inline SVGs materialize to
// page-relative image URLs, so a replay across depths must not carry the
// recording page's relative URL. It did: the URL resolved to missing media,
// distinct icons collapsed onto one placeholder asset, and which pages that
// hit (and so whether a footer variant split off) depended on worker timing.
// See Automattic/blocks-engine#7482.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\DirectoryChromeJournalStore;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\MemoryChromeJournalStore;

$facebook = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-facebook h-4 w-4" data-blocks-engine-capture-glyph-color="#faf8f5"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>';
$message = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-message-circle h-4 w-4" data-blocks-engine-capture-glyph-color="#faf7f5"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"></path></svg>';
$footer = '<footer class="site-footer"><div class="footer-grid"><div class="footer-col"><h3 class="footer-title">Catering</h3><p class="footer-copy">Fresh food for every event, delivered on time.</p></div>'
    . '<div class="footer-col"><h3 class="footer-title">Follow us</h3><div class="social">'
    . '<a class="social-link flex h-9 w-9 items-center justify-center" href="https://facebook.com/example" target="_blank" rel="noreferrer" aria-label="Facebook">' . $facebook . '</a>'
    . '<a class="social-link flex h-9 w-9 items-center justify-center" href="https://wa.me/15550100" target="_blank" rel="noreferrer" aria-label="WhatsApp">' . $message . '</a>'
    . '</div><ul class="contact-list"><li class="flex items-start gap-2">' . $facebook . '<span>example.catering</span></li><li class="flex items-start gap-2">' . $message . '<span>+1 555 0100</span></li></ul></div></div><p class="legal">Copyright Example Catering. All rights reserved.</p></footer>'
    . '<a class="whatsapp-float fixed bottom-5 right-5 z-50" href="https://wa.me/15550100" aria-label="Chat on WhatsApp">' . $message . '</a>';
$css = '.site-header{display:flex;justify-content:space-between;padding:24px;background:#111;color:#fff}.menu{display:flex;gap:16px;list-style:none}.menu a{color:#fff}'
    . '.site-footer{background:#222;color:#ddd;padding:48px}.footer-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:24px}'
    . '.social{display:flex;gap:12px}.flex{display:flex}.items-center{align-items:center}.items-start{align-items:flex-start}.justify-center{justify-content:center}.gap-2{gap:.5rem}.h-9{height:2.25rem}.w-9{width:2.25rem}.contact-list{list-style:none}.social-link{color:#fff}.h-4{height:1rem}.w-4{width:1rem}.legal{font-size:12px}'
    . '.fixed{position:fixed}.bottom-5{bottom:1.25rem}.right-5{right:1.25rem}.z-50{z-index:50}';
// Pages share a top-level section at different depths (the journal key
// names the section), so a footer journal recorded at one depth would be
// replayed at another in every worker order.
$routes = array('index.html' => '', 'booking/index.html' => '../', 'booking/event/index.html' => '../../', 'booking/school/index.html' => '../../', 'contact/index.html' => '../', 'contact/info/index.html' => '../../', 'contact/media/index.html' => '../../');
$files = array(array('path' => 'assets/style.css', 'content' => $css));
foreach ($routes as $path => $up) {
    $nav = '';
    foreach (array_keys($routes) as $target) $nav .= '<li><a href="' . $up . $target . '">' . ucfirst(trim(dirname($target), '.') ?: 'home') . '</a></li>';
    $files[] = array('path' => $path, 'content' => '<!doctype html><html><head><title>' . $path . '</title><link rel="stylesheet" href="' . $up . 'assets/style.css"></head><body>'
        . '<header class="site-header"><a class="brand" href="' . $up . 'index.html">Example Catering</a><nav><ul class="menu">' . $nav . '</ul></nav></header>'
        . '<main><h1>' . $path . '</h1><p>Content for the ' . $path . ' page.</p></main>' . $footer . '</body></html>');
}

$artifact = array('entrypoint' => 'index.html', 'files' => $files);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$plans = $compiler->preparePages($artifact, $shared);
if (array() === ($shared['analysis']['repeated_subtrees'] ?? array())) throw new RuntimeException('The shared plan names no repeated subtrees.');

// Static Site Importer compiles each page in its own worker; the workers share
// one journal store, so whichever worker reaches the footer first records it.
// Without a shared store every page converts its own footer: the baseline.
$sitePlan = static function (array $order, bool $shareJournal) use ($shared, $plans): array {
    $directory = sys_get_temp_dir() . '/footer-order-' . bin2hex(random_bytes(4));
    $receipts = array();
    try {
        foreach ($order as $index) {
            $store = $shareJournal ? new DirectoryChromeJournalStore($directory) : new MemoryChromeJournalStore();
            $receipts[] = (new ArtifactCompiler())->compilePreparedPage($shared, $plans[$index], null, $store);
        }
    } finally {
        array_map('unlink', glob($directory . '/*') ?: array());
        @rmdir($directory);
    }
    $plan = (new ArtifactCompiler())->compose($shared, $receipts)->toWordPressSitePlanView()['wordpress_site_plan'] ?? null;
    if (!is_array($plan)) throw new RuntimeException('The composed result has no site plan.');
    $untimed = static function (mixed $value) use (&$untimed): mixed {
        if (!is_array($value)) return $value;
        foreach (array('metrics', 'step_timings', 'timings', 'work') as $key) unset($value[$key]);
        return array_map($untimed, $value);
    };
    return $untimed($plan);
};

$listed = array_keys($plans);
$orders = array('listed' => $listed, 'reversed' => array_reverse($listed));
mt_srand(7482);
foreach (range(1, 3) as $round) {
    $shuffled = $listed;
    shuffle($shuffled);
    $orders['shuffled-' . $round] = $shuffled;
}
$baselinePlan = $sitePlan($listed, false);
$baseline = json_encode($baselinePlan, JSON_THROW_ON_ERROR);
foreach ($orders as $label => $order) {
    if (json_encode($sitePlan($order, true), JSON_THROW_ON_ERROR) !== $baseline) {
        throw new RuntimeException("Workers compiling pages in '{$label}' order produced a different site plan.");
    }
}

// Every page's icons resolve: none is replaced by a missing-media placeholder.
$missing = array();
array_walk_recursive($baselinePlan, static function (mixed $value, int|string $key) use (&$missing): void {
    if ('code' === $key && 'wordpress_site_plan_missing_local_media' === $value) $missing[] = $value;
});
if (array() !== $missing) throw new RuntimeException('Inline SVG icons resolved to missing media on ' . count($missing) . ' reference(s).');

// Distinct icons never share an asset; the footer is one shared part.
$footers = array_values(array_filter($baselinePlan['template_parts'] ?? array(), static fn (array $part): bool => str_starts_with((string) ($part['slug'] ?? ''), 'footer')));
if (1 !== count($footers)) throw new RuntimeException('Expected one shared footer part, got ' . count($footers) . '.');
$markup = (string) ($footers[0]['canonical_block_markup'] ?? '');
$assetFor = static function (string $icon) use ($markup): string {
    // The icon's class rides on the image's figure (or the image itself);
    // the asset token is the next image source after it.
    if (1 !== preg_match('/' . preg_quote($icon, '/') . '[^>]*>(?:<img [^>]*?)?src="\\{\\{wordpress-site-plan:asset:(asset-[a-f0-9]{16})\\}\\}/', $markup, $match)) {
        throw new RuntimeException("The shared footer has no asset-backed {$icon} image.");
    }
    return $match[1];
};
if ($assetFor('lucide-facebook') === $assetFor('lucide-message-circle')) throw new RuntimeException('Two different footer icons share one asset.');
echo "ok\n";
