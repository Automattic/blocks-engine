<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$files = array();
foreach (array('index', 'about', 'team', 'services', 'news', 'contact', 'help') as $index => $route) {
    // Different route CSS contexts allocate different source marker seeds;
    // identical chrome declarations must still have a shared identity.
    $marker = 'masthead';
    $files[$route . '.html'] = '<!doctype html><html><head><style>.route-' . $route . '{color:red}.' . $marker . '{position:sticky;top:0;background:#123456;color:white}main{min-height:2500px}.colophon{background:#abcdef}</style></head><body><div id="root"><header class="' . $marker . '"><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header><main><h1>' . $route . '</h1></main><footer class="colophon"><p>Shared colophon</p></footer></div></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$assert(2 === count($plan['template_parts']), 'Seven independently compiled routes produce exactly two shared parts.');
foreach ($plan['template_parts'] as $part) $assert(str_contains($part['canonical_block_markup'], '<' . $part['area']), 'The source landmark remains inside its part.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], 'wp:template-part') && !str_contains($page['canonical_block_markup'], '<header') && !str_contains($page['canonical_block_markup'], '<footer'), 'Content contains neither chrome copies nor references.');
foreach ($plan['templates'] as $template) {
    $markup = $template['canonical_block_markup'];
    $assert(1 === substr_count($markup, '"slug":"header"') && 1 === substr_count($markup, '"slug":"footer"'), $template['slug'] . ' uses both shared parts exactly once.');
    $assert(2 === substr_count($markup, '"className":"blocks-engine-shell-transport"'), 'Every shell reference marks its transport, including native lifecycle fallbacks.');
    $assert(!str_starts_with($template['slug'], 'page-'), 'Equivalent chrome does not create route template variants.');
}
$global = '';
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? null) && array(array('kind' => 'global')) === ($asset['scopes'] ?? null)) $global .= $asset['content'] ?? '';
$assert(str_contains($global, 'position:sticky') && str_contains($global, '#abcdef'), 'Both parts have site-wide styles, including on search and 404.');
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
$assert(!str_contains($bootstrap, '$slugs =') && str_contains($bootstrap, ':where(.blocks-engine-shell-transport){display:contents}'), 'Explicit boxless transport replaces slug-based HTML stripping.');

// Exercise the actual emitted transport CSS and part HTML in a browser. A
// sticky header must still be at the viewport top after scrolling its app root.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $header = current(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $html = preg_replace('/<!--.*?-->/s', '', $header['canonical_block_markup']);
    $temporary = tempnam(sys_get_temp_dir(), 'site-chrome-');
    $file = $temporary . '.html';
    unlink($temporary);
    preg_match("/'(:where\\(body[^']+blocks-engine-shell-transport[^']+)'/", $bootstrap, $transport);
    $assert(isset($transport[1]), 'The generated bootstrap carries the transport rule.');
    file_put_contents($file, '<!doctype html><style>body{margin:0}' . $transport[1] . $global . '</style><div id="root"><div class="wp-block-template-part blocks-engine-shell-transport">' . $html . '</div><main style="height:2500px"></main></div><script>scrollTo(0,500);requestAnimationFrame(()=>{const h=document.querySelector("header");document.body.dataset.sticky=JSON.stringify([h.tagName,getComputedStyle(h).position,h.getBoundingClientRect().top]);});</script>');
    try {
        $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --virtual-time-budget=1000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        preg_match('/data-sticky="([^"]+)"/', (string) $output, $match);
        $measured = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
        $assert(array('HEADER', 'sticky', 0) === $measured, 'The real browser retains the header tag and sticky position after scrolling: ' . json_encode($measured));
    } finally { unlink($file); }
}
echo "site-wide-chrome: ok\n";
