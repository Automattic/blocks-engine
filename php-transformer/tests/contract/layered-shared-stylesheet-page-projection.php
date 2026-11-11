<?php
/**
 * A shared stylesheet whose rules sit inside named cascade layers (the
 * Tailwind v4 shape) is route scoped as one shared sheet. Each page's rules
 * differ inside `@layer base`, which used to copy the whole layer per page.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '@layer theme,base,components,utilities;'
    . '@layer base{*,:after,:before{box-sizing:border-box;border:0 solid;margin:0;padding:0}html{line-height:1.5}h1,h2,h3,p,blockquote,figure{margin:0}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code{font-family:monospace}small{font-size:80%}button,input{font:inherit;color:inherit;background:#0000}img,svg{display:block;max-width:100%}ol,ul{list-style:none}table{border-collapse:collapse}}'
    . '@layer utilities{.flex{display:flex}.grid{display:grid}.gap-4{gap:1rem}.p-4{padding:1rem}.font-bold{font-weight:700}.text-lg{font-size:1.125rem}.rounded{border-radius:.25rem}.bg-brand{background:#f50}.text-white{color:#fff}.border-b{border-bottom-width:1px}}';
$page = static fn (string $title, string $main): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/app.css"></head><body><main class="p-4">' . $main . '</main></body></html>';
$files = array(
    'app.css' => $css,
    'index.html' => $page('Home', '<h1 class="text-lg font-bold">Home</h1><p>Intro <strong>bold</strong> and <code>x</code>.</p><a class="rounded bg-brand text-white p-4" href="/b/">Start</a>'),
    'a/index.html' => $page('A', '<h2 class="font-bold">A</h2><ul class="grid gap-4"><li class="border-b"><span>01</span> One</li><li>Two <small>s</small></li></ul><blockquote><p>Quote</p></blockquote>'),
    'b/index.html' => $page('B', '<h3 class="text-lg">B</h3><div class="flex gap-4"><button class="rounded p-4">Go</button><label>Mail <input type="email"></label></div><table><tr><td>1</td></tr></table>'),
    'c/index.html' => $page('C', '<h1>C</h1><figure><img src="/c.png" alt="C"><figcaption>Cap</figcaption></figure><p class="font-bold">Caption <a href="/a/">link <svg width="8" height="8" viewBox="0 0 8 8"><path d="M0 0h8v8z"/></svg></a></p>'),
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();

$shared = 0;
$pageCopies = array();
foreach ( $result['assets'] ?? array() as $asset ) {
    $path = (string) ($asset['path'] ?? '');
    $bytes = strlen((string) ($asset['content'] ?? ''));
    if ( 'app.css' === $path ) {
        $shared = $bytes;
    } elseif ( 1 === preg_match('/^app\.page-[0-9a-f]+\.css$/', $path) ) {
        $pageCopies[$path] = $bytes;
    }
}
$routeFallbacks = array_filter($result['diagnostics'] ?? array(), static fn (array $d): bool => 'author_stylesheet_route_scope_fallback' === ($d['code'] ?? ''));

if ( 0 === $shared || array() !== $pageCopies || array() !== $routeFallbacks ) {
    fwrite(STDERR, sprintf("FAIL: shared app.css %d bytes, %d page copies (%d bytes), route-scope fallbacks: %s\n", $shared, count($pageCopies), array_sum($pageCopies), implode('; ', array_map(static fn (array $d): string => (string) ($d['message'] ?? ''), $routeFallbacks))));
    exit(1);
}
echo "layered shared stylesheet page projection: ok ({$shared} bytes, no page copies)\n";
