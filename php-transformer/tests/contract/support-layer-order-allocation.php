<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Author CSS that declares a layer order (Tailwind v4 does) puts that order
// at the head of every page's before-author support sheet. The order
// statement must not keep the sheet's rules out of owner-set allocation:
// a support rule every page emits is written once, ungated.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$beforeAuthor = static function (int $pages): array {
    $files = array('site.css' => '@layer theme,utilities;@layer utilities{.card{padding:12px}}');
    for ($i = 0; $i < $pages; ++$i) {
        $files[$i ? 'page-' . $i . '.html' : 'index.html'] = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main>'
            . '<div class="card">Read <a href="/guide-' . $i . '/">guide ' . $i . '</a> today.</div>'
            // Each page also emits support only it needs.
            . '<p class="note"><a href="/x-' . $i . '/"><svg class="dot-' . $i . '" viewBox="0 0 4 4"><circle cx="2" cy="2" r="2"/></svg>Note</a></p></main></body></html>';
        $files['site.css'] .= '.dot-' . $i . '{display:block;width:' . (4 + $i) . 'px;height:' . (4 + $i) . 'px}';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    return array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'engine-support' === ($asset['source'] ?? '') && 'before-author' === ($asset['stylesheet_placement'] ?? '')));
};
$small = $beforeAuthor(3);
$large = $beforeAuthor(12);
$css = static fn(array $sheets): string => implode("\n", array_column($sheets, 'content'));
foreach (array(3 => $small, 12 => $large) as $pages => $sheets) {
    $content = $css($sheets);
    echo $pages, ' pages: ', count($sheets), ' before-author sheet(s), ', strlen($content), " bytes\n";
    $assert(str_contains($content, 'blocks-engine-synthetic-paragraph'), 'The fixture emits synthetic-paragraph support on every page.');
    $assert(1 === substr_count($content, '@layer blocks-engine-reset,theme,utilities;'), 'The layer order is stated once.');
    $assert(!str_contains($content, ':root:is('), 'Support every page emits carries no route gate.');
    }
$perPage = static fn(string $content): int => substr_count($content, 'blocks-engine-synthetic-paragraph');
$assert($perPage($css($large)) === $perPage($css($small)), 'Shared support rules do not repeat with the page count.');
echo "Support layer-order allocation passed\n";
