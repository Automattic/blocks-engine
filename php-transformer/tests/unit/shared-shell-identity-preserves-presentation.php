<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

// Equal source landmarks can compile differently in the full route context.
// An isolated artifact proves identity, but must not replace contextual classes.
$landmark = static fn(string $class): string => '<!-- wp:group {"tagName":"footer","className":"' . $class . '"} --><footer class="wp-block-group ' . $class . '"><!-- wp:paragraph --><p>Contact us</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$pages = array();
foreach (array('home.html', 'about.html') as $i => $path) {
    $markup = $landmark('route-context-' . $i);
    $pages[] = array('source_path' => $path, 'canonical_block_markup' => $markup, 'shell_candidates' => array(array('area' => 'footer', 'markup' => $markup, 'identity_markup' => $markup)));
}
$artifacts = array(array('area' => 'footer', 'canonical_block_markup' => $landmark('isolated-context'), 'placement' => array('equal_source_identity' => true, 'source_paths' => array_column($pages, 'source_path'))));
$result = (new ShellExtraction(new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RouteLinks('', '', array(), array(), null, array(), array())))->withSourceSharedShellIdentity($pages, $artifacts);
foreach ($result as $i => $page) {
    $candidate = $page['shell_candidates'][0];
    if (!str_contains($candidate['template_part_markup'], 'route-context-' . $i) || str_contains($candidate['template_part_markup'], 'isolated-context')) throw new RuntimeException('Source identity must not overwrite route-compiled presentation.');
}
if ($result[0]['shell_candidates'][0]['identity_markup'] !== $result[1]['shell_candidates'][0]['identity_markup']) throw new RuntimeException('Equal source shells must still compare equal.');
echo "Shared shell identity preserves presentation: passed\n";
