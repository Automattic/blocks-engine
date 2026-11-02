<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/page-edge-section-footer.html');
$cases = array(
    'last section' => $fixture,
    'main wrapper' => '<main><div><header><p>Brand</p></header><h1>Hero</h1><footer><p>© 2026 Brand</p></footer></div></main>',
    'article content' => str_replace('<h1>Hero</h1>', '<h1>Hero</h1><article><header><h2>Article title</h2></header><p>Article body</p></article>', $fixture),
);
foreach ($cases as $label => $html) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = (new WordPressSitePlan())->fromResult($result);
    $parts = array_column($plan['template_parts'], null, 'area');
    foreach (array('header', 'footer') as $area) {
        $assert(isset($parts[$area]), "$label: missing $area part");
        foreach ($plan['templates'] as $template) {
            $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $parts[$area]['slug'] . '"'), "$label: {$template['slug']} must place $area");
        }
    }
    foreach ($plan['pages'] as $page) {
        $markup = $page['canonical_block_markup'];
        $assert(!str_contains($markup, '© 2026 Brand') && !str_contains($markup, '"slug":"footer"'), "$label: footer must leave post_content entirely");
        $assert(str_contains($markup, 'Hero'), "$label: content must survive");
        if ('article content' === $label) $assert(str_contains($markup, 'Article title') && !str_contains($parts['header']['canonical_block_markup'], 'Article title'), 'Article header must stay content');
    }
    if ('main wrapper' !== $label) {
        $footer = $parts['footer']['canonical_block_markup'];
        $palette = array_column($plan['theme']['design_system']['palette'], 'color', 'slug');
        foreach (array('textColor' => '#eee', 'backgroundColor' => '#111') as $attribute => $color) {
            preg_match('/"' . $attribute . '":"([^"]+)"/', $footer, $match);
            $assert(isset($match[1], $palette[$match[1]]) && str_starts_with($palette[$match[1]], $color), "$label: footer must retain section $attribute through theme palette");
        }
    }
}
fwrite(STDOUT, "Page-edge landmark shell tests passed\n");
