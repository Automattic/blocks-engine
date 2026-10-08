<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$icon = '<svg width="24" height="24" viewBox="0 0 24 24"><path d="M2 2h20v20H2z" fill-rule="evenodd"/></svg>';
$control = static fn(string $state, string $extra = ''): string => '<div class="reaction"><span class="reaction-row"><button role="checkbox" aria-checked="' . $state . '" tabindex="0" title="Favorite" class="reaction-control" ' . $extra . '>' . $icon . '</button><i class="reaction-count">1</i></span></div>';
$artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>' . $control('false') . $control('true') . '</main>'));
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
$assert(!str_contains($markup, '<!-- wp:html'), 'Named SVG checkboxes and their counters compile as editable blocks without core/html.');
$assert(2 === substr_count($markup, '<!-- wp:custom/authored-button ') && 2 === substr_count($markup, 'role="checkbox"'), 'Both initial states use the existing authored-button surface with checkbox semantics.');
$assert(str_contains($markup, 'aria-checked="false"') && str_contains($markup, 'aria-checked="true"') && 2 === substr_count($markup, 'title="Favorite"'), 'The source checked states and title accessible names survive compilation.');
$assert(2 === substr_count($markup, 'viewBox="0 0 24 24"') && 2 === substr_count($markup, 'fill-rule="evenodd"') && str_contains($markup, '<span class="reaction-row') && str_contains($markup, '<i class="reaction-count'), 'SVG presentation and wrapper/counter source tags remain addressable.');
$assert('passed' === ($result['source_reports']['editability_policy']['status'] ?? null), 'The generated control and layout blocks pass the required editability policy.');
foreach (array('onclick="fetch(\'/favorite\')"', 'aria-controls="remote-panel"', 'data-action="persist"') as $unsupported) {
    $unproven = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $control('false', $unsupported))))->toArray();
    $assert(!str_contains($unproven['serialized_blocks'], 'data-blocks-engine-checkable'), 'Unproven application behavior is not replaced by a local checkbox toggle: ' . $unsupported);
}
$mixed = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $control('mixed'))))->toArray();
$assert(!str_contains($mixed['serialized_blocks'], 'data-blocks-engine-checkable'), 'A tri-state checkbox does not invent a binary transition.');

echo "SVG checkable control compiler contract passed.\n";
