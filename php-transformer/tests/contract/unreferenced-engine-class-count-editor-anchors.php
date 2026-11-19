<?php
declare(strict_types=1);

/**
 * `unreferenced_engine_class_count` counts exactly the engine classes the
 * pruner would drop: UnreferencedEngineClasses::metrics() reads the
 * pruner's own markup set and judgement (unreferenced()), so a class the
 * pruner keeps on purpose is never counted, and a pruned plan reports 0.
 *
 * A one-page site whose sections carry source ids keeps their editor
 * anchors (the editor finds each section by its id through them). Its
 * enquiry section holds a search form, whose engine subject marker is not a
 * selector hook: the pruner never drops it, so it is not counted either.
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

$sections = array('about' => 'About', 'stories' => 'Stories', 'approach' => 'Approach', 'collections' => 'Collections');
$body = '<header id="top"><a href="#about">About</a> <a href="#enquire">Enquire</a></header><main>';
foreach ($sections as $id => $title) $body .= '<section id="' . $id . '"><h2>' . $title . '</h2><p>' . $title . ' text.</p></section>';
$body .= '<section id="enquire"><h2>Enquire</h2><form role="search" action="/"><input type="search" name="s"><button>Go</button></form></section></main>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'site.css' => 'body{margin:0}section{padding:20px}',
    'index.html' => '<!doctype html><html><head><title>Home</title><link rel="stylesheet" href="site.css"></head><body>' . $body . '</body></html>',
)))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = '';
foreach (array('pages', 'template_parts', 'templates') as $group) {
    foreach ($plan[$group] ?? array() as $unit) $markup .= ($unit['canonical_block_markup'] ?? '') . "\n";
}
foreach (array_merge(array_keys($sections), array('enquire', 'top')) as $id) {
    $assert(str_contains($markup, ' id="' . $id . '"'), "Section {$id} keeps its source id.");
    $assert(str_contains($markup, EngineMarker::editorAnchorClass($id)), "Section {$id} keeps its editor anchor.");
}
$assert(1 === preg_match('/blocks-engine-search-subject-[a-f0-9]{12}/', $markup), 'The search form carries its subject marker.');
$metrics = $plan['quality']['metrics'] ?? array();
$assert(0 === ($metrics['unreferenced_engine_class_count'] ?? null), 'A page whose sections keep their editor anchors reports no unreferenced engine classes: ' . json_encode($metrics['unreferenced_engine_class_count'] ?? null));

// The counter and the pruner share one judgement on a hand-built plan.
$anchor = EngineMarker::editorAnchorClass('gallery');
$subject = EngineMarker::subjectClassClass('lede');
$instance = 'blocks-engine-navigation-instance-0123456789ab-0';
$page = '<!-- wp:group {"className":"' . $anchor . '"} --><div class="' . $anchor . '"><!-- wp:paragraph {"className":"lede ' . $subject . '"} --><p class="lede ' . $subject . '">Lede</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
    . '<!-- wp:navigation {"className":"' . $instance . '"} /-->';
$counted = static fn (array $assets): int => UnreferencedEngineClasses::metrics(array('pages' => array(array('canonical_block_markup' => $page)), 'assets' => $assets))['unreferenced_engine_class_count'];
$dropped = static fn (array $assets): array => UnreferencedEngineClasses::unreferenced(array($page), UnreferencedEngineClasses::references($assets));
$plain = array(array('kind' => 'css', 'content' => '.lede{color:red}'));
// Dropping the anchor would leave its carrier without a class, and no rule
// names the navigation instance: the pruner keeps both and drops the
// subject class from the comment and the HTML.
$assert(array($subject => true) === $dropped($plain), 'The pruner drops only the unreferenced subject class.');
$assert(2 === $counted($plain), 'The counter counts the occurrences of what the pruner drops, and nothing it keeps.');
$assert(0 === $counted(array(array('kind' => 'css', 'content' => '.lede.' . $subject . '{color:red}'))), 'A named subject class is not counted.');
$assert(0 === UnreferencedEngineClasses::metrics(array('pages' => array(array('canonical_block_markup' => UnreferencedEngineClasses::without($page, $dropped($plain)))), 'assets' => $plain))['unreferenced_engine_class_count'], 'The pruned markup reports 0.');
// An editor anchor whose id only an archive presentation delivers is kept by
// the pruner, and so not counted.
$archive = array('archive' => array('presentation_markup' => '<!-- wp:group {"anchor":"gallery"} --><div id="gallery" class="wp-block-group"></div><!-- /wp:group -->'));
$withArchive = '<!-- wp:group {"className":"' . $anchor . '"} --><div class="wp-block-group ' . $anchor . '"></div><!-- /wp:group -->';
$assert(array() === UnreferencedEngineClasses::unreferenced(UnreferencedEngineClasses::markups(array('pages' => array(array('canonical_block_markup' => $withArchive)), 'taxonomy_entities' => array($archive))), ''), 'The pruner keeps an anchor whose id an archive presentation delivers.');
$assert(0 === UnreferencedEngineClasses::metrics(array('pages' => array(array('canonical_block_markup' => $withArchive)), 'taxonomy_entities' => array($archive)))['unreferenced_engine_class_count'], 'The counter judges that anchor the same way.');
echo "unreferenced-engine-class-count-editor-anchors contract passed\n";
