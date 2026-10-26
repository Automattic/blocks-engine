<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
$cycle = static fn(array $keys, int $initial): array => array('coverage' => 'complete', 'restoration' => 'verified', 'initial' => $initial, 'frames' => array_map(static fn(string $key): array => array('key' => $key, 'fullImage' => $key), $keys));
$inline = $cycle(array('/full/a.png', '/full/b.png', '/full/c.png'), 1);
$inline['selector'] = '#pictures';
$inline['viewport'] = array('width' => 1440, 'height' => 900);
$full = $cycle(array('/full/c.png', '/full/a.png', '/full/b.png'), 2);
$state = array('kind' => 'gallery', 'status' => 'captured', 'gallery' => array('inline' => $inline, 'lightbox' => $full, 'closed' => true, 'selection' => array(1, 2, 0)));
$items = static fn(array $keys): string => implode('', array_map(static fn(string $key): string => '<div><img src="' . $key . '" alt="Image"></div>', $keys));
// Only the published gallery runtime is retired with its native replacement.
$galleryRuntime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-gallery-runtime.js'));
$galleryScript = '<script data-blocks-engine-capture-gallery-runtime>' . $galleryRuntime . '</script>';
$html = '<html><head>' . $galleryScript . '</head><body><section id="pictures" data-blocks-engine-capture-gallery data-blocks-engine-capture-gallery-source="#pictures" data-blocks-engine-capture-gallery-capture-width="1440"><h2>Pictures</h2><div class="gallery carousel"><div id="stage" data-blocks-engine-capture-gallery-stage data-blocks-engine-capture-gallery-initial="1" data-blocks-engine-capture-dialog-trigger="panel" aria-controls="panel">' . $items(array('/inline/a.png', '/inline/b.png', '/inline/c.png')) . '</div><button aria-label="Previous image">Previous</button><button aria-label="Next image">Next</button></div><div id="panel" data-blocks-engine-capture-dialog-panel="panel" hidden><div data-blocks-engine-capture-gallery-stage>' . $items(array('/full/c.png', '/full/a.png', '/full/b.png')) . '</div></div></section></body></html>';
$report = array('schema' => 'blocks-engine-capture/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://neutral.test/', 'states' => array($state))));
$receipt = array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://neutral.test/', 'path' => 'website/index.html')));
$files = array('website/index.html' => $html, 'interaction-states.json' => json_encode($report), 'capture-receipt.json' => json_encode($receipt));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$markup = $result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '';
$runtime = new Runtime();
$visit = static function(array $blocks) use (&$visit): array { $all = array(); foreach ($blocks as $block) { $all[] = $block; $all = array_merge($all, $visit($block['innerBlocks'] ?? array())); } return $all; };
$tree = $visit($runtime->parseBlocks($markup));
$carousels = array_values(array_filter($tree, static fn(array $block): bool => str_ends_with($block['blockName'] ?? '', '/authored-carousel')));
$dialogs = array_values(array_filter($tree, static fn(array $block): bool => str_ends_with($block['blockName'] ?? '', '/captured-dialog')));
$assert = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$assert(2 === count($carousels), 'Inline and captured lightbox compile through the existing native carousel generator: ' . json_encode(array('count' => count($carousels), 'dialogs' => count($dialogs), 'status' => $result['status'], 'diagnostics' => $result['diagnostics'] ?? array())));
$assert(1 === count($dialogs), 'Image-triggered lightbox compiles through the existing native dialog generator');
$assert(6 === count(array_filter($tree, static fn(array $block): bool => 'core/image' === ($block['blockName'] ?? ''))), 'Every observed frame is an editable image');
$assert(array(array('triggerId' => 'stage', 'indices' => array(1, 2, 0))) === $dialogs[0]['attrs']['gallerySelection'], 'Observed image identities preserve rotated full-cycle selection');
$assert('stage' === ($carousels[0]['attrs']['sourceIdentityAttributes']['id'] ?? ''), 'Source stage trigger identity survives native replacement');
$assert(1 === $carousels[0]['attrs']['initialSlide'] && 2 === $carousels[1]['attrs']['initialSlide'], 'Viewport baseline and lightbox initial state survive compilation');
$assert(!str_contains($markup, 'data-blocks-engine-capture-gallery-sequence') && !str_contains(json_encode($result['assets']), 'data-blocks-engine-capture-gallery-sequence'), 'Capture helper is retired, rather than carried as source JavaScript');
$assert(array(CaptureEvidence::GALLERY_RUNTIME => CaptureEvidence::GALLERY_RUNTIME_SHA256) === array_column($result['source_reports']['native_runtime_replacements'] ?? array(), 'body_hash', 'attribute'), 'the published gallery runtime is retired with a native replacement proof', json_encode($result['source_reports']['native_runtime_replacements'] ?? null));
// One changed byte makes the gallery body unknown: it keeps running, so the
// page keeps the gallery wiring it drives and claims no native replacement.
$alteredGalleryFiles = $files;
$alteredGalleryFiles['website/index.html'] = str_replace('function all(selector,root){', 'function all( selector,root){', $html);
$alteredGallery = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $alteredGalleryFiles))->toArray();
$assert(array() === ($alteredGallery['source_reports']['native_runtime_replacements'] ?? array()), 'an unrecognized gallery body is not retired', json_encode($alteredGallery['source_reports']['native_runtime_replacements'] ?? null));
$assert(str_contains(json_encode($alteredGallery['assets']), 'function all( selector,root){'), 'the unrecognized gallery body ships with the page');
$assert(!str_contains((string) $alteredGallery['serialized_blocks'], '/captured-dialog'), 'an unrecognized gallery body gets no native lightbox dialog');
$assert($runtime->serializeBlocks($runtime->parseBlocks($markup)) === $markup, 'Native image/dialog tree has a canonical serialize/parse roundtrip');
$wrappedHtml = str_replace(array('<body>', '</body>', '<div><img src="'), array('<body><div class="scroll">', '</div></body>', '<div class="frame active" data-blocks-engine-capture-gallery-display="block" hidden style="display:none!important"><img src="'), $html);
$wrappedHtml = str_replace('class="gallery carousel"', 'class="gallery carousel" style="margin-left:auto!important;margin-right:auto!important"', $wrappedHtml);
$wrappedFiles = $files;
$wrappedFiles['website/index.html'] = $wrappedHtml;
$wrapped = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $wrappedFiles))->toArray();
$wrappedMarkup = $wrapped['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '';
$wrappedTree = $visit($runtime->parseBlocks($wrappedMarkup));
$assert(2 === count(array_filter($wrappedTree, static fn(array $block): bool => str_ends_with($block['blockName'] ?? '', '/authored-carousel'))), 'A thin host and inactive-frame styles cannot flatten a proved directional owner');
$assert(!str_contains($wrappedMarkup, '<!-- wp:gallery '), 'Captured directional behavior remains native navigation rather than a static image collection');
$wrappedCarousels = array_values(array_filter($wrappedTree, static fn(array $block): bool => str_ends_with($block['blockName'] ?? '', '/authored-carousel')));
$assert('auto!important' === ($wrappedCarousels[0]['attrs']['sourceRootGeometry']['margin-left'] ?? null), 'Captured gallery retains the canonical source margin-priority contract');
// Dialog and gallery budgets are independent, including at the producer's
// combined trigger-dialog and initially-open-dialog boundary.
foreach (array(
    'mixed capture' => array(7, 2, false),
    'producer dialog boundary' => array(16, 2, false),
    'dialog overflow' => array(17, 0, true),
    'gallery boundary' => array(0, 8, false),
    'gallery overflow' => array(0, 9, true),
) as $label => [$dialogCount, $galleryCount, $overLimit]) {
    $boundedStates = array();
    $dialogSource = '';
    for ($index = 0; $index < $dialogCount; ++$index) {
        $dialogSource .= '<button id="dialog-trigger-' . $index . '">Open</button>';
        $boundedStates[] = array(
            'status' => 'captured',
            'trigger' => array('selector' => '#dialog-trigger-' . $index, 'tag' => 'button', 'label' => 'Open'),
            'dialog' => array('html' => '<div><p>Dialog content</p></div>', 'htmlBytes' => strlen('<div><p>Dialog content</p></div>'), 'htmlTruncated' => false),
        );
        // Exercise both explicit and legacy kindless dialog evidence.
        if (0 === $index % 2) $boundedStates[$index]['kind'] = 'dialog';
    }
    $gallerySource = '';
    $gallerySection = substr($html, strpos($html, '<section'), strpos($html, '</section>') + strlen('</section>') - strpos($html, '<section'));
    for ($index = 0; $index < $galleryCount; ++$index) {
        $gallerySource .= str_replace(array('pictures', '"stage"', '"panel"'), array('pictures-' . $index, '"stage-' . $index . '"', '"panel-' . $index . '"'), $gallerySection);
        $galleryState = $state;
        $galleryState['gallery']['inline']['selector'] = '#pictures-' . $index;
        $boundedStates[] = $galleryState;
    }
    $boundedReport = $report;
    $boundedReport['pages'][0]['states'] = $boundedStates;
    $bounded = (new CapturedDialogProjector())->project(array(
        array('path' => 'website/index.html', 'content' => '<html><head>' . $galleryScript . '</head><body>' . $dialogSource . $gallerySource . '</body></html>'),
        array('path' => 'interaction-states.json', 'content' => json_encode($boundedReport)),
        array('path' => 'capture-receipt.json', 'content' => json_encode($receipt)),
    ));
    $assert($overLimit === in_array('captured_interaction_state_limit_exceeded', array_column($bounded['diagnostics'], 'code'), true), $label . ': only genuinely over-bound pages emit the limit diagnostic');
    if ($overLimit) continue;
    $boundedHtml = $bounded['files'][0]['content'];
    $assert($dialogCount + $galleryCount === $bounded['projected_count'], $label . ': every dialog and verified gallery projects');
    $assert($galleryCount === substr_count($boundedHtml, 'data-blocks-engine-gallery-selection='), $label . ': every verified gallery retains native selection');
    $assert(!str_contains($boundedHtml, 'data-blocks-engine-capture-gallery-runtime'), $label . ': gallery runtime is retired');
}

$report['pages'][0]['states'][0]['gallery']['selection'] = array(0, 1, 2);
$rows = array(); foreach ($files as $path => $content) $rows[] = array('path' => $path, 'content' => 'interaction-states.json' === $path ? json_encode($report) : $content);
$invalid = (new CapturedDialogProjector())->project($rows);
$assert(in_array('captured_gallery_selection_invalid', array_column($invalid['diagnostics'], 'code'), true), 'A shape-valid selection with mismatched image identities remains diagnosed');

// An already-proven native gallery must coexist with a different scope's mixed
// dropdown. A partial gallery remains a source-owned binding, not a forged
// native endpoint merely because another scope has a replaceable menu.
$mixed = (static fn(): string => require dirname(__DIR__) . '/fixtures/scoped-mixed-dropdown.php')();
libxml_use_internal_errors(true);
$document = new DOMDocument();
$document->loadHTML($mixed);
$mobile = (new DOMXPath($document))->query('//*[contains(@class,"data-blocks-engine-capture-mobile-document")]')->item(0);
$mobileHtml = $document->saveHTML($mobile);
$knownRuntime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$assert(CaptureEvidence::GALLERY_RUNTIME_SHA256 === hash('sha256', $galleryRuntime), 'the gallery fixture is the published gallery runtime');
$coexistHtml = str_replace($galleryScript, '<script data-blocks-engine-capture-disclosure-runtime>' . $knownRuntime . '</script>' . $galleryScript, $html);
$coexistHtml = str_replace(array('<body>', '</body>'), array('<body><div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">', '</div>' . $mobileHtml . '</body>'), $coexistHtml);
$coexistReport = array('schema' => 'blocks-engine-capture/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://neutral.test/', 'states' => array($state))));
$coexistFiles = array('website/index.html' => $coexistHtml, 'interaction-states.json' => json_encode($coexistReport), 'capture-receipt.json' => json_encode($receipt));
$coexist = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $coexistFiles))->toArray();
$coexistTree = $visit($runtime->parseBlocks($coexist['serialized_blocks']));
$assert(2 === count(array_filter($coexistTree, static fn(array $block): bool => str_ends_with($block['blockName'] ?? '', '/captured-dialog'))), 'complete gallery and mixed dropdown each retain their native scoped endpoint');
$assert(!str_contains(json_encode($coexist['assets']), 'function triggers()'), 'all proven gallery/dropdown bindings allow generated disclosure retirement');
$assert('pass' === $coexist['source_reports']['runtime_dependency_parity']['status'], 'updated native aria-controls target identities remain present');
$replaced = array_column($coexist['source_reports']['native_runtime_replacements'] ?? array(), 'body_hash', 'attribute');
$assert(CaptureEvidence::DISCLOSURE_RUNTIME_SHA256 === ($replaced['data-blocks-engine-capture-disclosure-runtime'] ?? null) && CaptureEvidence::GALLERY_RUNTIME_SHA256 === ($replaced['data-blocks-engine-capture-gallery-runtime'] ?? null), 'the published disclosure and gallery runtimes are both replaced natively', json_encode($coexist['source_reports']['native_runtime_replacements'] ?? null));
$assert(!str_contains(json_encode($coexist['assets']), 'data-blocks-engine-capture-gallery-sequence'), 'the retired gallery runtime is not shipped as an asset');
// One changed byte makes the gallery body unknown: the disclosure runtime it
// would run beside is not claimed by a native replacement.
$unknownGalleryFiles = $coexistFiles;
$unknownGalleryFiles['website/index.html'] = str_replace('function all(selector,root){', 'function all( selector,root){', $coexistHtml);
$unknownGallery = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $unknownGalleryFiles))->toArray();
$assert(array() === array_filter($unknownGallery['source_reports']['native_runtime_replacements'] ?? array(), static fn(array $proof): bool => 'data-blocks-engine-capture-disclosure-runtime' === $proof['attribute']), 'an unknown gallery body keeps the captured disclosure runtime');
$assert(str_contains(json_encode($unknownGallery['assets']), 'function triggers()'), 'the kept disclosure runtime still ships with the page');
$coexistReport['pages'][0]['states'][0]['status'] = 'observed-incomplete';
$coexistFiles['interaction-states.json'] = json_encode($coexistReport);
$partial = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $coexistFiles))->toArray();
$assert(array() === array_filter($partial['source_reports']['native_runtime_replacements'] ?? array(), static fn(array $proof): bool => 'data-blocks-engine-capture-disclosure-runtime' === $proof['attribute']), 'an incomplete gallery prevents an all-native disclosure retirement claim');
echo "Captured gallery producer/consumer contract passed\n";
