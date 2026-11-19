<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A route-set guard on an arm's subject only needs its root alternative when
// that subject can be the document element. A subject after a combinator has
// an ancestor, so `:root.be-rs-…` alone never matches it: the guard keeps only
// the descendant half (Automattic/blocks-engine#6145).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$css = '';
foreach (array('listing', 'detail') as $variant) {
    foreach (array('body.', '.') as $prefix) $css .= $prefix . 'tweak-' . $variant . ' .foo{--sample-' . $variant . ':1;padding-bottom:3px}';
}
$files = array('site.css' => $css);
for ($i = 0; $i < 4; ++$i) {
    $files['page-' . $i . '.html'] = '<html><head><title>Page ' . $i . '</title><link rel="stylesheet" href="/site.css"></head><body class="tweak-' . ($i % 2 ? 'detail' : 'listing') . '"><main><nav><ul><li class="foo"><a href="/page-0.html">Page ' . $i . '</a></li></ul></nav></main></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'page-0.html', 'files' => $files))->toArray();
$compiled = implode('', array_map(
    static fn(array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['source_reports']['wordpress_site_plan']['assets'], static fn(array $asset): bool => str_contains((string) ($asset['content'] ?? ''), '--sample-'))
));
$assert(1 === preg_match('/\.tweak-listing \.foo:where\(:root\.be-rs-[0-9a-f]+ \*\)/', $compiled), 'Descendant subject keeps a descendant-only route-set guard: ' . $compiled);
$assert(0 === preg_match('/:where\(:root\.be-rs-([0-9a-f]+),:root\.be-rs-\1 \*\)/', $compiled), 'No root alternative for a subject that cannot be the root: ' . $compiled);
echo "route-set descendant guard: ok\n";
