<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '<style>.row{display:flex;flex-wrap:wrap;--g:1.5rem}.row>*{padding-left:calc(var(--g)*.5);padding-right:calc(var(--g)*.5)}.box{max-width:1000px;margin:0 auto}</style>';
$a = $css . '<main><div class="row"><div class="box"><p>In a row</p></div></div><a href="/b/">b</a></main>';
$b = $css . '<main><section><div class="box"><p>Not in a row</p></div></section></main>';
$attributes = static function (array $files): string {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    foreach ($plan['pages'] as $page) {
        $markup = $page['canonical_block_markup'];
        if (str_contains($markup, 'Not in a row') && preg_match('/<!-- wp:group (\{[^\n]*?"className":"box"[^\n]*?) -->/', $markup, $match)) return $match[1];
    }
    throw new RuntimeException('Missing box group.');
};
if ($attributes(array('index.html' => $b)) !== $attributes(array('index.html' => $a, 'b/index.html' => $b))) {
    throw new RuntimeException('Contextual padding must not spread across pages through a shared class.');
}
// Ancestor, sibling and positional winners must be equally non-portable.
foreach (array('section .box', 'p + .box', '.box:first-child', '.box:nth-of-type(2)') as $selector) {
    $css = '<style>' . $selector . '{padding:12px}.box{max-width:1000px;margin:0 auto}</style>';
    $a = $css . '<main><section><div class="box"><p>First</p></div><p>Sibling</p><div class="box"><p>Second</p></div></section><a href="/b/">b</a></main>';
    $b = '<main><section><div class="box"><p>Not in a row</p></div></section></main>';
    if ($attributes(array('index.html' => $b)) !== $attributes(array('index.html' => $a, 'b/index.html' => $b))) {
        throw new RuntimeException('Contextual winner leaked: ' . $selector);
    }
}
echo "PASS contextual class styles stay local\n";
