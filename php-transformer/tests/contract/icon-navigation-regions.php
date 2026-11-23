<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationEntityProjection;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/icon-navigation-regions.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$menus = array();
$visit = static function (array $blocks) use (&$visit, &$menus): void {
    foreach ($blocks as $block) {
        if ('core/navigation' === ($block['blockName'] ?? '')) $menus[] = $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);
$assert(3 === count($menus), 'All three source landmarks stay native navigation.');
foreach ($menus as $index => $menu) {
    $assert(2 === count($menu['innerBlocks']), 'Each menu keeps its own two anchors.');
    foreach ($menu['innerBlocks'] as $link) {
        $attrs = $link['attrs'];
        $assert(str_contains($attrs['label'], 'screen-reader-text'), 'Icon names never become visible labels.');
        $artwork = $attrs['metadata']['blocksEngineNavigationAnchor']['svg'] ?? '';
        $assert(str_contains($artwork, '<svg') || str_contains($attrs['className'], 'blocks-engine-navigation-link-icon-'), 'Native navigation links retain artwork.');
        if (0 !== $index) {
            $assert(str_contains($artwork, '<use') && str_contains($artwork, 'https://example.org/icons.svg#'), 'Content and footer preserve their own sprite uses.');
            $assert(str_contains($attrs['className'], 'content-icon') && !str_contains($attrs['className'], 'header-icon'), 'Content never receives header classes.');
        }
    }
}
// Sharing happens after artifact compilation. Equal destinations must not
// substitute a structurally different region's artwork or anchor carriers.
$projected = NavigationEntityProjection::project(array(array('source_path' => 'index.html', 'canonical_block_markup' => $result['serialized_blocks'])), array(), array());
$assert(3 === count($projected['menus']), 'Different source artwork structures do not share an entity.');
echo "Icon navigation regions contract passed.\n";
