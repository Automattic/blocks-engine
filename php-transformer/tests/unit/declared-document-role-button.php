<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;

$artifact = require dirname(__DIR__) . '/fixtures/declared-document-role-button.php';
$source = $artifact['files']['index.html'];
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$failures = array(); $passes = 0;
$assert = static function (bool $ok, string $message) use (&$failures, &$passes): void { if (!$ok) $failures[] = $message; else ++$passes; };
$html = $result['serialized_blocks'];
$assert(0 === substr_count($html, '<!-- wp:html'), 'A declared document and script-bound role button must produce zero core/html blocks.');
$assert(str_contains($html, '/authored-button') && str_contains($html, 'role="button"') && str_contains($html, 'tabindex="0"'), 'Role button uses the existing editable authored control and preserves its source root semantics.');
$assert(str_contains($html, '/layout-shell') && str_contains($html, 'data-dla-device-document="slate"') && str_contains($html, 'frame host-state profile-slate more-context'), 'Any declared profile preserves its root attributes/class state through layout-shell.');
$assert('pass' === (new BlockValidityValidator())->validateBlocks($result['blocks'])['status'], 'Canonical block structure remains valid.');
$assert(array() === ($result['source_reports']['runtime_dependency_parity']['findings'] ?? array()), 'Runtime target proof remains strict and passes.');
$roleBlocks = array(); $roots = array();
$collect = static function (array $blocks) use (&$collect, &$roleBlocks, &$roots): void { foreach ($blocks as $block) {
    if (str_ends_with($block['blockName'] ?? '', '/authored-button') && 'div' === ($block['attrs']['tagName'] ?? '')) $roleBlocks[] = $block;
    foreach ($block['attrs']['wrappers'] ?? array() as $wrapper) if (isset($wrapper['attributes']['data-dla-device-document'])) $roots[] = $wrapper;
    $collect($block['innerBlocks'] ?? array());
} };
$collect($result['blocks']);
$assert(4 === count($roleBlocks) && 4 === count($roots), 'Four arbitrary profile scopes and four source role controls have independent editable owners.');
$generator = new AuthoredButtonBlockGenerator();
foreach ($roleBlocks as $block) $assert($generator->markup($block['attrs']) === $block['innerHTML'] && 3 === substr_count($block['innerHTML'], '<span'), 'Role control retains its exact three-bar descendants through the canonical generator.');
$assert(array() === array_filter($result['diagnostics'], static fn(array $row): bool => 'runtime_dom_contract_fallback' === ($row['code'] ?? '') || str_starts_with($row['code'] ?? '', 'html_semantic_parity_landmark')), 'Declared wrapper/control ownership carries no raw-contract or landmark loss.');
$document = new DOMDocument(); $document->loadHTML($source, LIBXML_NOERROR | LIBXML_NOWARNING);
$root = $document->getElementsByTagName('main')->item(2);
$assert($root instanceof DOMElement && 'slate' === SourceDom::documentVariantRoot($root)?->getAttribute('data-dla-device-document'), 'Explicit scope, independent of class cardinality or profile names, owns document ancestry.');
if (isset($result['source_reports']['wordpress_site_plan'])) {
    $resolved = (new WordPressSitePlanResolver())->resolve($result['source_reports']['wordpress_site_plan'], array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true));
    $assert(isset($resolved['resolution']), 'The scoped source reaches strict materialization.');
    $mediaAssets = array_values(array_filter($resolved['assets'], static fn(array $asset): bool => 'screen' === ($asset['source_media'] ?? null)));
    $assert(4 === count($mediaAssets), 'All four declared profiles retain authored media provenance as canonical asset data.');
    foreach ($mediaAssets as $asset) $assert(!str_contains($asset['content'], '@media not all'), 'Inactive activation state cannot be baked into the stylesheet payload.');
}
$modal = (new ArtifactCompiler())->compile(array('files' => array('index.html' => '<section><button type="button" aria-haspopup="dialog" aria-controls="modal-panel" data-dla-dialog-trigger="modal-panel">Open modal</button><div id="modal-panel" role="dialog" aria-modal="true" hidden data-dla-dialog-panel="modal-panel"><p>Modal body</p></div></section><script>document.querySelectorAll("[data-dla-dialog-trigger]").forEach(function(control){control.addEventListener("click",function(){document.getElementById(control.getAttribute("aria-controls")).hidden=false;});});</script>')))->toArray();
$assert(!str_contains($modal['serialized_blocks'], 'wp:details') && str_contains($modal['serialized_blocks'], 'id="modal-panel"'), 'A real modal must retain its own target and focus/close contract rather than become details.');
$assert(array() === ($modal['source_reports']['runtime_dependency_parity']['findings'] ?? array()), 'Modal script target proof remains strict after native wrapper dispatch.');
if ($failures) {
    $raw = array();
    $walk = static function (array $blocks) use (&$walk, &$raw): void { foreach ($blocks as $block) { if ('core/html' === ($block['blockName'] ?? '')) $raw[] = substr($block['innerHTML'] ?? '', 0, 500); $walk($block['innerBlocks'] ?? array()); } };
    $walk($result['blocks']);
    fwrite(STDERR, implode("\n", $failures) . "\n" . json_encode($raw, JSON_PRETTY_PRINT) . "\n"); exit(1);
}
fwrite(STDOUT, "Declared document role button passed: {$passes} assertions\n");
