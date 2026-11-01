<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;

$rows = array(
    'modal-looking carousel' => array('<div id="surface" class="modal" aria-roledescription="carousel" style="position:fixed!important"><div role="group" aria-roledescription="slide"><img src="one.jpg"></div><div role="group" aria-roledescription="slide" hidden><img src="two.jpg"></div></div>', 'carousel', 'semantics'),
    'listbox popup' => array('<button aria-haspopup="listbox" aria-controls="surface">Choose</button><div id="surface" class="popup" role="listbox"><div role="option">One</div></div>', 'listbox', 'semantics'),
    'role dialog' => array('<div id="surface" role="dialog"><p>Dialog</p></div>', 'modal', 'semantics'),
    'native dialog' => array('<dialog id="surface" open><p>Dialog</p></dialog>', 'modal', 'semantics'),
    'declared gallery' => array('<div id="surface" class="modal" role="dialog" data-blocks-engine-capture-gallery=""><img src="one.jpg"><img src="two.jpg"></div>', 'carousel', 'evidence'),
    'plain HTML' => array('<div id="surface" class="popup"><p>Plain panel</p></div>', 'modal', 'inference'),
);
foreach ($rows as $label => [$html, $kind, $source]) {
    $html = '<section id="owner"><h2>Collection</h2>' . $html . '</section>';
    $projected = (new CapturedDialogProjector())->project(array(array('path' => 'index.html', 'content' => $html)));
    $document = new DOMDocument();
    @$document->loadHTML($projected['files'][0]['content']);
    $surface = $document->getElementById('surface');
    if (!$surface || 'owner' !== $surface->parentNode?->attributes?->getNamedItem('id')?->nodeValue) {
        throw new RuntimeException($label . ': surface left its section');
    }
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $candidates = array_values(array_filter($result['source_reports']['interaction_candidates'] ?? array(), static fn(array $row): bool => '#surface' === ($row['target'] ?? '')));
    $surfaces = array_values(array_filter($candidates, static fn(array $row): bool => in_array($row['kind'], array('modal', 'listbox', 'carousel'), true)));
    if (1 !== count($surfaces) || $kind !== $surfaces[0]['kind'] || $source !== ($surfaces[0]['classification_source'] ?? null)) {
        throw new RuntimeException($label . ': wrong classification ' . json_encode($surfaces));
    }
    if ('modal' !== $kind && str_contains($result['serialized_blocks'], '/captured-dialog')) {
        throw new RuntimeException($label . ': became a dialog');
    }
    if ('plain HTML' === $label && !str_contains($result['serialized_blocks'], 'Plain panel')) {
        throw new RuntimeException('Plain HTML content lost');
    }
}
echo "interactive surface precedence: ok\n";
