<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A shared rule whose ancestor only one page renders (`.tray button`) is
// dormant on every other page. Each of those pages projects it onto its own
// button controls. Those arms must not name each control's marker: the
// site-wide stylesheet would then grow with every page that loads it, even
// though the authored rule is the same.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$compile = static function (string $combinator, int $count): array {
    $files = array('site.css' => '.tray' . $combinator . 'button{flex:0 0 90px;padding:4px 8px;border:1px solid #ccc;background:none;color:#333}');
    for ($i = 0; $i < $count; ++$i) {
        // A page-specific rule gives each page's controls their own markers.
        $files['page-' . $i . '.css'] = '.r' . $i . ' button,.r' . $i . ' .btn{word-spacing:' . $i . 'px}.btn{display:inline-block;padding:6px 10px;background:#222;color:#fff}';
        $controls = '';
        for ($b = 0; $b < 3; ++$b) {
            $controls .= '<button type="button">Choice ' . $i . '-' . $b . '</button><a class="btn" href="/page-' . $b . '.html">Go ' . $b . '</a>';
        }
        $tray = 0 === $i ? '<div class="tray"><button type="button">Thumb</button></div>' : '';
        $files['page-' . $i . '.html'] = '<html><head><title>Page ' . $i . '</title><link rel="stylesheet" href="/site.css"><link rel="stylesheet" href="/page-' . $i . '.css"></head><body><main><h1>Page ' . $i . '</h1><div class="row r' . $i . '">' . $controls . '</div>' . $tray . '</main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'page-0.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $selectors = array();
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '') || !str_contains((string) ($asset['content'] ?? ''), 'flex:0 0 90px')) continue;
        (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $selector) use (&$selectors): void {
            if (str_contains($selector, '.tray')) $selectors[] = $selector;
        });
    }
    $routeClass = '';
    foreach ($plan['pages'] as $page) {
        if ('page-0.html' === $page['source_path'] && preg_match('/\bbe-r-[a-f0-9]+\b/', (string) ($page['document_metadata']['root_attributes']['class'] ?? ''), $match)) $routeClass = $match[0];
    }
    return array(implode(',', $selectors), $routeClass);
};

foreach (array('>' => 'child', ' ' => 'descendant') as $combinator => $label) {
    [$small] = $compile($combinator, 6);
    [$large, $activeRoute] = $compile($combinator, 12);
    $assert('' !== $large, "$label: the dormant rule keeps a projection for the other pages' buttons");
    $assert(strlen($small) === strlen($large), "$label: doubling the pages must not grow the dormant rule (" . strlen($small) . ' vs ' . strlen($large) . ' bytes)');
    $assert(0 === preg_match('/blocks-engine-control-[a-f0-9]{12}-\d+\s*[,)]\s*\.blocks-engine-control-/', $large), "$label: dormant arms are not marker lists: $large");
    $assert(str_contains($large, '.tray' . trim($combinator) . (' ' === $combinator ? ' ' : '') . ':where(.blocks-engine-role-control.blocks-engine-subject.blocks-engine-subject-tag-button)'), "$label: dormant arms address button controls by subject: $large");
    if (' ' === $combinator) {
        // The page that renders the ancestor projects the rule per element;
        // the shared subject arm must not reach its controls too.
        $assert('' !== $activeRoute && str_contains($large, ':root:not(.' . $activeRoute . ')'), "descendant: the subject arm is fenced off the active route: $large");
    }
    echo "$label: " . strlen($large) . " selector bytes at 6 and 12 pages\n";
}
