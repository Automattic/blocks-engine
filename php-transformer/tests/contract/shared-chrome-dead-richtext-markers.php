<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Every page carries the same header, whose call to action hides part of its
// label with a class used nowhere else. The pages' main regions carry
// different styled classes, so each route's copy of the header gets its own
// context-seeded rich-text marker, and the shared stylesheet projects into one
// variant per route. Only one copy of the header is emitted, as the shared
// part, so every other copy's marker is named by CSS and carried by nothing.
// The hidden-label rule names the label in context (`header .sr-only`): a
// context-free `.sr-only` reaches every copy through the constant richtext
// subject arm and names no marker at all.
$css = 'header .sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}.cta{border:1px solid}'
    . '.home{padding:2em}.post{padding:1em}.legal{padding:0}.note{margin:0}';
$nav = static fn (string $current): string => implode('', array_map(
    static fn (string $slug, string $label): string => '<a href="' . $slug . '.html"' . ($slug === $current ? ' aria-current="page"' : '') . '>' . $label . '</a>',
    array('index', 'a', 'b', 'c'),
    array('Home', 'A', 'B', 'C')
));
$page = static fn (string $slug, string $title, string $main): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<header><a href="index.html">Acme</a><nav>' . $nav($slug) . '</nav><a class="cta" href="c.html">Start<span class="sr-only"> a project</span></a></header>'
    . '<main class="' . $main . '"><h1>' . $title . '</h1><p>' . $title . ' page.</p></main><footer><p>Acme Ltd</p></footer></body></html>';

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'site.css' => $css,
        'index.html' => $page('index', 'Home', 'home'),
        'a.html' => $page('a', 'Alpha', 'post'),
        'b.html' => $page('b', 'Beta', 'legal'),
        'c.html' => $page('c', 'Gamma', 'note'),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$markers = static fn (string $text): array => preg_match_all('/blocks-engine-richtext-[a-f0-9]+-\d+/', $text, $matches) ? array_values(array_unique($matches[0])) : array();
$markup = '';
foreach (array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array(), $plan['templates'] ?? array()) as $unit) {
    $markup .= (string) ($unit['canonical_block_markup'] ?? '');
}
$live = array_fill_keys($markers($markup), true);
$headerMarkers = array();
foreach ($plan['template_parts'] ?? array() as $part) {
    if ('header' === ($part['area'] ?? null)) $headerMarkers = array_merge($headerMarkers, $markers((string) ($part['canonical_block_markup'] ?? '')));
}
$assert(1 === count($headerMarkers), 'The shared header carries one rich-text marker for its hidden label.');

// What visitors load; an editor-only copy of a stylesheet holds the same rules.
$chrome = array_values(array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => str_contains((string) ($asset['target_path'] ?? ''), 'shared-chrome-') && 'editor' !== ($asset['stylesheet_target'] ?? 'both')));
$chromeCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $chrome));
$dead = array_diff($markers($chromeCss), array_keys($live));
$assert(array() === $dead, 'Global chrome stylesheets name no rich-text marker absent from emitted markup: ' . implode(', ', $dead));
$assert(2 === count($chrome), 'Route copies of the header no longer each ship their own global chrome stylesheet; got ' . count($chrome) . '.');

// The marked header label keeps its hidden-text rule, and `.sr-only` still
// reaches any other element carrying the class.
$assert(str_contains($chromeCss, 'span[data-blocks-engine-richtext-marker="' . $headerMarkers[0] . '"]'), 'The live header marker keeps its projected rule.');
$assert(1 === preg_match('/(?:^|}|,)header \.sr-only\{position:absolute/', $chromeCss), 'A rule whose only marker was dead keeps its plain class selector.');
$assert(str_contains($chromeCss, '.cta{border:1px solid}'), 'Authored chrome rules are unchanged.');

// Pruning keeps what each selector matches and its specificity.
$prune = (new ReflectionMethod(WordPressSitePlan::class, 'withoutDeadRichTextMarkers'))->getClosure(null);
$live = array('blocks-engine-richtext-aaaaaaaaaaaa-0' => true);
$cases = array(
    '.sr-only:not(:where(.blocks-engine-richtext-bbbbbbbbbbbb-0))' => '.sr-only',
    '.sr-only:not(:where(.blocks-engine-richtext-aaaaaaaaaaaa-0,.blocks-engine-richtext-bbbbbbbbbbbb-0))' => '.sr-only:not(:where(.blocks-engine-richtext-aaaaaaaaaaaa-0))',
    '.a :not(:where(.blocks-engine-richtext-bbbbbbbbbbbb-0))' => '.a *',
    '.a>:not(:where(.blocks-engine-richtext-bbbbbbbbbbbb-0)):hover' => '.a>:hover',
    ':where(span[data-blocks-engine-richtext-marker="blocks-engine-richtext-bbbbbbbbbbbb-0"]):not(.x)' => null,
    ':where(.blocks-engine-role-control,span[data-blocks-engine-richtext-marker="blocks-engine-richtext-bbbbbbbbbbbb-0"])' => ':where(.blocks-engine-role-control)',
    '.blocks-engine-richtext-bbbbbbbbbbbb-0 .x' => null,
    '.x:not(.blocks-engine-richtext-bbbbbbbbbbbb-0)' => '.x:not(.blocks-engine-richtext-bbbbbbbbbbbb-0)',
    '.blocks-engine-richtext-aaaaaaaaaaaa-0' => '.blocks-engine-richtext-aaaaaaaaaaaa-0',
);
foreach ($cases as $selector => $expected) {
    $actual = $prune($selector, $live);
    $assert($expected === $actual, 'Pruned ' . $selector . ' to ' . var_export($actual, true) . ', expected ' . var_export($expected, true) . '.');
}

echo "shared-chrome-dead-richtext-markers: ok\n";
