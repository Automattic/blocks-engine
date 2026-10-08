<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = require dirname(__DIR__) . '/fixtures/navigation-opener-presentation.php';
$compile = static fn(string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert = static function(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$plan = $compile($source);
$page = $plan['pages'][0]['canonical_block_markup'] ?? '';
if ('' === $page) foreach ($plan['pages'] as $document) $page .= $document['canonical_block_markup'] ?? '';
$assert(str_contains($page, 'blocksEngineNavigationOpener'), 'source-only single passive SVG reaches native navigation metadata');
$assert(str_contains($page, 'M2 3h16v2H2zM2 9h16v2H2zM2 15h16v2H2z'), 'original source artwork is retained rather than a generic menu variant');
foreach (array(
    'mixed label' => str_replace('data-dla-dialog-trigger="phone-menu">', 'data-dla-dialog-trigger="phone-menu">Menu', $source),
    'multiple icons' => str_replace('data-dla-dialog-trigger="phone-menu">', 'data-dla-dialog-trigger="phone-menu"><svg width="12" height="12"><circle r="4" /></svg>', $source),
    'document-dependent svg' => str_replace('data-dla-dialog-trigger="phone-menu">' . $icon, 'data-dla-dialog-trigger="phone-menu"><svg width="20" height="20"><defs><linearGradient id="paint" /></defs><rect fill="url(#paint)" width="20" height="20" /></svg>', $source),
) as $name => $negative) {
    $result = $compile($negative);
    $markup = '';
    foreach ($result['pages'] as $document) $markup .= $document['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'blocksEngineNavigationOpener'), $name . ' does not justify replacing Core button content');
    $assert(str_contains($markup, 'wp:navigation'), $name . ' retains native menu behavior');
}
echo "Navigation source opener contract passed\n";
