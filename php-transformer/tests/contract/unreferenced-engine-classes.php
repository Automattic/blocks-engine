<?php
declare(strict_types=1);

/**
 * An engine class exists for an emitted stylesheet or script to name. One
 * that nothing delivered to the frontend or the editor canvas names is not
 * written to block markup, and the plan reports how many such classes remain.
 * A class only an editor stylesheet names is kept, and so is an editor anchor
 * whose id the markup delivers: it is that id in the editor canvas.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\UnreferencedEngineClasses;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$css = '.hero{padding:20px}.hero span{color:red}#about{margin-top:10px}.card p{font-size:14px}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body><main>'
    . '<section id="about" class="hero"><h2>About <span class="x">us</span></h2><p>Text</p></section>'
    . '<section id="stories"><div class="card"><p>Story ' . $title . '</p></div></section></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'site.css' => $css,
    'index.html' => $page('Home'),
    'about.html' => $page('About'),
)))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = '';
foreach (array('pages', 'template_parts', 'templates', 'synced_patterns', 'menus') as $group) {
    foreach ($plan[$group] ?? array() as $unit) $markup .= ($unit['canonical_block_markup'] ?? $unit['block_markup'] ?? '') . "\n";
}
$references = '';
$editorReferences = '';
$frontendReferences = '';
foreach ($plan['assets'] as $asset) if (in_array($asset['kind'] ?? null, array('css', 'js'), true)) {
    $references .= (string) ($asset['content'] ?? '') . "\n";
    if ('editor' === ($asset['stylesheet_target'] ?? 'both')) $editorReferences .= (string) ($asset['content'] ?? '') . "\n";
    else $frontendReferences .= (string) ($asset['content'] ?? '') . "\n";
}
preg_match_all('/\sid="([^"]+)"/', $markup, $ids);

$assert(str_contains($markup, 'Story Home') && str_contains($markup, '>us</'), 'The fixture emits both sections and the authored span.');
$assert(str_contains($markup, 'id="stories"'), 'The unstyled section keeps its anchor.');
$editorOnly = EngineMarker::editorAnchorClass('about');
$assert(str_contains($editorReferences, $editorOnly) && !str_contains($frontendReferences, $editorOnly), 'The fixture names the about anchor only in an editor stylesheet.');
$assert(str_contains($markup, $editorOnly), 'A class only an editor stylesheet names is kept.');
$assert(str_contains($markup, EngineMarker::editorAnchorClass('stories')), 'An editor anchor whose id is delivered is kept as that id in the editor canvas.');
$assert(!str_contains($markup, EngineMarker::subjectTagClass('span')), 'A subject tag class no rule names is not written.');
$assert(!str_contains($markup, EngineMarker::subjectClassClass('x')), 'A subject class no rule names is not written.');

preg_match_all('/(?<![A-Za-z0-9_-])(?:blocks-engine-|be-)[A-Za-z0-9_-]+/', $markup, $classes);
foreach (array_unique($classes[0]) as $class) {
    if (null !== ($anchor = EngineMarker::editorAnchorId($class)) && in_array($anchor, $ids[1], true)) continue;
    if (EngineMarker::isSelectorHook($class)) $assert(str_contains($references, $class), "Selector hook {$class} is named by an emitted rule.");
}

$metrics = $plan['quality']['metrics'] ?? array();
$assert(0 === ($metrics['unreferenced_engine_class_count'] ?? null), 'The plan reports no unreferenced engine classes: ' . json_encode($metrics['unreferenced_engine_class_count'] ?? null));
$assert(is_float($metrics['engine_class_token_share'] ?? null) && $metrics['engine_class_token_share'] >= 0.0 && $metrics['engine_class_token_share'] <= 1.0, 'The plan reports the engine class token share.');
// The reference set is every stylesheet and script delivered to either
// surface: a subject class only an editor-only stylesheet names stays, in
// whichever encoding the asset carries, and drops once no asset names it.
$subject = EngineMarker::subjectClassClass('lede');
$editorMarkup = array('<!-- wp:paragraph {"className":"lede ' . $subject . '"} --><p class="lede ' . $subject . '">Lede</p><!-- /wp:paragraph -->');
$editorRule = ':root .editor-styles-wrapper .' . $subject . '{margin:0}';
$frontend = array('kind' => 'css', 'stylesheet_target' => 'frontend', 'content' => '.lede{color:red}');
foreach (array('content' => $editorRule, 'content_base64' => base64_encode($editorRule)) as $field => $value) {
    $editorSheet = array('kind' => 'css', 'stylesheet_target' => 'editor', $field => $value);
    $assert(array() === UnreferencedEngineClasses::unreferenced($editorMarkup, UnreferencedEngineClasses::references(array($frontend, $editorSheet))), "A class only an editor stylesheet ({$field}) names is kept.");
}
$assert(array($subject => true) === UnreferencedEngineClasses::unreferenced($editorMarkup, UnreferencedEngineClasses::references(array($frontend))), 'The same class is dropped once no delivered asset names it.');
$unread = array('kind' => 'css', 'stylesheet_target' => 'editor', 'payload_reference' => array('sha256' => str_repeat('0', 64)));
$assert(array() === UnreferencedEngineClasses::unreferenced($editorMarkup, UnreferencedEngineClasses::references(array($frontend, $unread))), 'Nothing is dropped while a delivered stylesheet cannot be read.');
echo "unreferenced-engine-classes contract passed\n";
