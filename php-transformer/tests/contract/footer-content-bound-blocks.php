<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// A shared footer part owns a form entity whose binding anchors on the form
// block, and the form block holds a consent paragraph. Footer copy shared
// across pages may be factored into its own part, but the bound block is
// replaced whole by the provider form: its consent line must stay, and the
// binding must still find its block.
$paragraph = static fn (string $text): string => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$form = '<!-- wp:group {"className":"signup"} --><div class="wp-block-group signup">' . $paragraph('Yes, send me the monthly newsletter and occasional offers.') . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Join</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->';
$footer = '<!-- wp:group {"anchor":"site-foot"} --><div id="site-foot" class="wp-block-group">' . $paragraph('12 Harbour Street') . $form . '</div><!-- /wp:group -->';
$formOffset = strpos($footer, '<!-- wp:group {"className":"signup"}');
$formIndex = null;
foreach (WordPressSitePlan::blockRanges($footer) as $index => $range) if ($range['offset'] === $formOffset) $formIndex = $index;
$partSource = 'wordpress-site-plan/shared/footer#footer';
$part = array('source_path' => $partSource, 'slug' => 'footer', 'area' => 'footer', 'canonical_block_markup' => $footer, 'placement' => array('kind' => 'shared_shell', 'template_slugs' => array('index', 'page', 'front-page')), 'provenance' => array('sources' => array('index.html' => 'x', 'about.html' => 'x', 'team.html' => 'x')));
$pages = array_map(static fn (string $path): array => array('source_path' => $path, 'canonical_block_markup' => $paragraph('Body for ' . $path), 'entrypoint' => 'index.html' === $path, 'post_type' => 'page', 'slug' => basename($path, '.html')), array('index.html', 'about.html', 'team.html'));
$declarations = array(array('kind' => 'entity_collection', 'payload' => array('schema' => 'x', 'entities' => array(array('bindings' => array(array('role' => 'form', 'source_path' => $partSource, 'search_block_markup' => $form, 'occurrence' => 1, 'position' => array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $formIndex, 'offset' => $formOffset, 'length' => strlen($form)))))))));

$extraction = new ShellExtraction(new WordPressSitePlan());
$result = $extraction->factorSharedFooterContent($pages, array($part), $declarations);
$factoredFooter = (string) $result['parts'][0]['canonical_block_markup'];
$assert(str_contains($factoredFooter, $form), 'The bound form block, consent line included, is untouched: ' . $factoredFooter);
$assert(1 === substr_count($factoredFooter, $form), 'The binding still finds exactly one block to replace.');
$contentPart = array_values(array_filter($result['parts'], static fn (array $row): bool => 'footer-content' === ($row['slug'] ?? null)))[0] ?? null;
$assert(is_array($contentPart) && str_contains((string) $contentPart['canonical_block_markup'], '12 Harbour Street'), 'Shared copy outside the bound block is still factored into its own part.');

// The same form stored as a runtime entity manifest record keeps the position
// it was compiled with, which page canonicalization can shift. Its block is
// still left whole.
$recordedBinding = $declarations[0]['payload']['entities'][0]['bindings'][0];
$recordedBinding['position']['offset'] += 7;
$manifest = RuntimeEntityManifest::fromEntities('generic/forms/v1', array(array('bindings' => array($recordedBinding))));
$recorded = $extraction->factorSharedFooterContent($pages, array($part), RuntimeDeclarations::normalizeList(array(array('kind' => 'entity_collection', 'type' => 'forms', 'source_path' => 'index.html', 'payload' => $manifest['payload']))), $manifest['records']);
$assert(1 === substr_count((string) $recorded['parts'][0]['canonical_block_markup'], $form), 'A bound block whose entity lives in a manifest record is untouched: ' . $recorded['parts'][0]['canonical_block_markup']);

echo "Footer content bound blocks contract passed.\n";
