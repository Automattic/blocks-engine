<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$source = file_get_contents(__DIR__ . '/fixtures/shared-navigation-editor-typography.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$assert(1 === count($plan['menus']), 'Identical links share one navigation entity.');
$hosts = array();
foreach (array_merge($plan['pages'], $plan['template_parts']) as $document) {
    preg_match_all('/<!-- wp:navigation (\{.*?\}) \/-->/', $document['canonical_block_markup'], $matches);
    foreach ($matches[1] as $json) $hosts[] = json_decode($json, true);
}
$assert(2 === count($hosts), 'Both navigation instances reference the entity.');
// Model Core's editor: both references render the shared item classes, but
// neither renders the frontend-only li > div > span label boxes.
$html = '<html><body>';
foreach ($hosts as $host) {
    $assert(str_contains($host['ref'], $plan['menus'][0]['token']), 'Both instances use the same entity.');
    $html .= '<nav class="wp-block-navigation ' . htmlspecialchars($host['className'] ?? '') . '"><ul class="wp-block-navigation__container">';
    preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $plan['menus'][0]['block_markup'], $items);
    $assert(4 === count($items[1]), 'The shared entity retains all four links.');
    foreach ($items[1] as $json) {
        $attrs = json_decode($json, true);
        $html .= '<li class="wp-block-navigation-item ' . htmlspecialchars($attrs['className'] ?? '') . '"><a class="wp-block-navigation-item__content">' . $attrs['label'] . '</a></li>';
    }
    $html .= '</ul></nav>';
}
$html .= '</body></html>';
$dom = new DOMDocument();
@$dom->loadHTML($html);
$editorCss = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'editor' === ($asset['stylesheet_target'] ?? 'both')), 'content'));
$cascade = new StaticCssCascade($dom, $editorCss);
$styledMenus = 0;
foreach ($dom->getElementsByTagName('nav') as $nav) {
    $styled = 0;
    foreach ($nav->getElementsByTagName('a') as $link) {
        $actual = $cascade->resolve($link, array('font-size', 'font-family', 'line-height'), array());
        if ('' === ($actual['font-size'] ?? '')) continue;
        $assert('28px' === $actual['font-size'] && 'Georgia,serif' === $actual['font-family'] && '56px' === $actual['line-height'], 'The boxed menu retains its editor typography.');
        ++$styled;
    }
    $assert(0 === $styled || 4 === $styled, 'Typography covers every link of its own instance.');
    if (4 === $styled) ++$styledMenus;
}
$assert(1 === $styledMenus, 'Editor label-box typography must match only its own navigation instance; matched ' . $styledMenus . ' menus.');
echo "Shared navigation editor typography stays instance-scoped.\n";
