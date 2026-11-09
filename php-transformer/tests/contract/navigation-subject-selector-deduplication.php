<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Three copies of one nested menu exercise shared-chrome consolidation as well
// as the many source items that share each navigation presentation marker.
$item = static fn(string $id, string $children = ''): string => '<li class="item"><div class="box"><a class="lnk" href="/entry-' . $id . '/">Entry ' . $id . '</a></div>' . ('' === $children ? '' : '<ul class="sub">' . $children . '</ul>') . '</li>';
$menu = '';
for ($top = 1; $top <= 9; ++$top) {
    $submenu = '';
    if (in_array($top, array(2, 3, 9), true)) {
        for ($sub = 1; $sub <= 6; ++$sub) {
            $leaves = '';
            if ($sub >= 3) {
                for ($leaf = 1; $leaf <= 7; ++$leaf) $leaves .= $item($top . '-' . $sub . '-' . $leaf);
            }
            $submenu .= $item($top . '-' . $sub, $leaves);
        }
    }
    $menu .= $item((string) $top, $submenu);
}
$files = array();
foreach (array('index', 'about', 'contact') as $page) {
    $files[$page . '.html'] = '<!doctype html><html lang="en"><head><title>' . $page . '</title><style>.item{position:relative}.sub .item>.box{font-size:14px}</style></head><body><header><nav class="menu"><ul class="list">' . $menu . '</ul></nav></header><main><h1>' . $page . '</h1><p>Page content.</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$assert(1 === count($plan['menus']), 'The three pages consolidate their shared menu.');
$css = '';
$sharedBytes = 0;
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    $css .= $asset['content'];
    if (str_contains((string) ($asset['target_path'] ?? $asset['path'] ?? ''), 'shared-chrome')) $sharedBytes += strlen($asset['content']);
}
$lists = 0;
$largestEntries = $largestUnique = 0;
// Balance parentheses: navigation paths themselves contain :nth-child().
for ($offset = 0; false !== ($start = strpos($css, ':is(', $offset)); $offset = $start + 4) {
    $depth = 1;
    for ($end = $start + 4; $end < strlen($css) && $depth > 0; ++$end) {
        if ('(' === $css[$end]) ++$depth;
        elseif (')' === $css[$end]) --$depth;
    }
    $entries = CssStylesheetTransformer::splitSelectorList(substr($css, $start + 4, $end - $start - 5));
    $assert(null !== $entries, 'Every emitted :is() list is parseable within the selector scanner budget.');
    $entries = array_map('trim', $entries);
    $unique = count(array_unique($entries));
    if (count($entries) > $largestEntries) {
        $largestEntries = count($entries);
        $largestUnique = $unique;
    }
    ++$lists;
    $assert(count($entries) === $unique, 'A projected :is() list repeats subject paths: ' . count($entries) . ' entries / ' . $unique . ' unique.');
}
$assert($lists > 0 && $sharedBytes > 0, 'The fixture emits projected shared-chrome selector lists.');
$assert(strlen($css) < 100000, 'Navigation CSS stays proportional to distinct item paths.');
$assert(1 === preg_match('/\.wp-block-navigation__submenu-container>\.wp-block-navigation-item(?:,|\))/', $css), 'A full sibling run has an unindexed navigation item target.');
echo 'navigation-subject-selector-deduplication contract passed: shared-chrome ' . $sharedBytes . ' bytes; largest list ' . $largestEntries . ' entries / ' . $largestUnique . " unique\n";
