<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$html = <<<'HTML'
<style>body{margin:0;background:#fff;color:#111}p{color:#fff;background:#111}
.banner{min-height:300px;background:linear-gradient(90deg,#000e,#0002),url(hero.jpg) center/cover;color:VALUE;padding:40px}</style>
<main><p>Base text on contrast.</p><section class="banner"><h2>Heading</h2><p>Copy</p></section></main>
HTML;
$failures = array();
foreach (array('white' => 'base', '#fff' => 'base', '#ffffff' => 'base', 'rgb(255,255,255)' => 'base', '#fefefe' => null) as $color => $expected) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => str_replace('VALUE', $color, $html),
    )))->toArray()['source_reports']['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $count = $plan['theme']['design_system']['metrics']['literal_preset_color_count'];
    echo $color . ': literal_preset_color_count=' . $count . PHP_EOL;
    $assert(1 === preg_match('/<!-- wp:cover (\{.*?\}) -->\s*(<[^>]+>)/s', $markup, $match), $color . ': the banner remains a native cover.');
    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    if (null === $expected) {
        $assert($color === ($attrs['style']['color']['text'] ?? null) && !isset($attrs['textColor']), 'A near shade keeps its literal text paint.');
        $assert(str_contains($match[2], 'color:' . $color), 'The near shade stays inline.');
        continue;
    }
    // Collect failures so the trunk run reports every notation, not only the first.
    if (0 !== $count || $expected !== ($attrs['textColor'] ?? null) || isset($attrs['style']['color']['text'])) {
        $failures[] = $color . ': expected no preset-equal literals and textColor=' . $expected;
        continue;
    }
    $assert(str_contains($match[2], 'has-base-color') && str_contains($match[2], 'has-text-color') && !str_contains($match[2], 'color:'), $color . ': saved wrapper uses the preset class instead of inline text paint.');
    $palette = array();
    foreach ($plan['writes'] as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    }
    $assert(CssColor::hex($color) === CssColor::hex($palette[$expected] ?? ''), $color . ': the preset paints exactly the source colour.');
}
$assert(array() === $failures, implode("\n", $failures));
echo "Cover text preset binding contract passed.\n";
