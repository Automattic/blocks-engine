<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = file_get_contents(dirname(__DIR__) . '/fixtures/navigation-trailing-button.html');
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'One header part');
$header = $headers[0]['canonical_block_markup'];
$assert(1 === substr_count($header, '<!-- wp:navigation '), 'One native navigation');
$assert(str_contains($header, '"overlayMenu":"mobile"'), 'Mobile overlay retained');
$assert(str_contains($header, 'blocks-engine-native-navigation-toggle-'), 'Source toggle retained');
$assert(str_contains($header, 'blocksEngineNavigationOpener') && str_contains($header, 'M4 12h16') && str_contains($header, 'M4 6h16'), 'Original two-path toggle icon retained');
$assert(str_contains($header, '<!-- wp:button ') && str_contains($header, 'Join'), 'Native CTA retained');
$assert((bool) preg_match('/<!-- wp:group (\{(?:(?!-->).)*?"tagName":"nav"(?:(?!-->).)*?\}) --><nav[^>]*><!-- wp:navigation .*?\/--><!-- wp:buttons /', $header, $row), 'Navigation and CTA are adjacent in the same source row');
$rowAttrs = json_decode($row[1], true, 512, JSON_THROW_ON_ERROR);
$assert('flex' === $rowAttrs['layout']['type'] && 'nowrap' === $rowAttrs['layout']['flexWrap'], 'Desktop row keeps links then CTA without wrapping');
$assert(1 === count($plan['menus']), 'One saved navigation entity');
$assert(str_contains($header, '"ref":"{{wordpress-site-plan:navigation:'), 'Header references the saved navigation entity');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert((bool) preg_match('/<!-- wp:navigation \{[^>]*"className":"[^"]*(blocks-engine-navigation-instance-[a-f0-9]{12}-\d+)/', $header, $instance), 'Navigation has an instance scope');
$assert(str_contains($css, '.wp-block-navigation.' . $instance[1] . ' .wp-block-navigation-item__content{color:'), 'Items keep the source link colour');
$assert((bool) preg_match('/@media\(max-width:[0-9]+px\)\{\.wp-block-group\.blocks-engine-menu-fold-[a-z0-9]+\{display:flex!important\}/', $css), 'Responsive source wrapper exposes the owned toggle');
$menu = $plan['menus'][0]['block_markup'];
$assert(3 === substr_count($menu, '<!-- wp:navigation-link '), 'Menu contains exactly three links');
foreach (array('one', 'two', 'three') as $target) {
    $assert(str_contains($menu, '#' . $target), 'Menu retains ' . $target);
}
echo "navigation-trailing-button: ok\n";
