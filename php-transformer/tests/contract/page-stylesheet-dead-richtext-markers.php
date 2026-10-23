<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Every page carries the same header, whose call to action hides part of its
// label. Each route's copy of the header gets its own context-seeded rich-text
// marker, but only one copy is emitted, as the shared part. A universal reset
// used to keep each route's own stylesheet naming its copy's marker (to
// exclude it), so every other copy's marker was named by page CSS and carried
// by nothing. The reset now excludes RichText carriers through their role
// class, so no page stylesheet names a marker at all.
$css = '*{margin:0}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}.cta{border:1px solid}'
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
// Everything the plan emits apart from the stylesheets themselves (and their
// write payloads): markup, runtime declarations, entity records.
$emitted = $plan;
unset($emitted['assets'], $emitted['writes'], $emitted['diagnostics']);
$live = $markers((string) json_encode($emitted));
$assert(1 === count($live), 'The shared header carries the one live rich-text marker.');

$stylesheets = array_values(array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)));
$pageSheets = array_values(array_filter($stylesheets, static fn (array $asset): bool => !str_contains((string) $asset['target_path'], 'shared-chrome-') && str_starts_with(basename((string) $asset['target_path']), 'site.')));
$assert(2 === count($pageSheets), 'Every route shares one projection of the site stylesheet, for visitors and the editor.');
foreach ($stylesheets as $asset) {
    $dead = array_diff($markers((string) $asset['content']), $live);
    $assert(array() === $dead, $asset['target_path'] . ' names no rich-text marker absent from the emitted plan: ' . implode(', ', $dead));
    $assert(strlen((string) $asset['content']) === $asset['bytes'], 'Asset bytes match its content: ' . $asset['target_path']);
    $assert(hash('sha256', (string) $asset['content']) === $asset['content_hash'], 'Asset hash matches its content: ' . $asset['target_path']);
}

// The reset still reaches every element, and the authored rules are unchanged.
$all = implode("\n", array_map(static fn (array $asset): string => (string) $asset['content'], $stylesheets));
$assert(str_contains($all, '*:not(:where(.blocks-engine-role-richtext)):not(.blocks-engine-specificity-class-site-0),:where(.blocks-engine-role-richtext.blocks-engine-subject):not(.blocks-engine-specificity-class-site-0){margin:0}'), 'The reset excludes the hidden label through the richtext role class and reaches it through the subject arm.');
$assert(str_contains($all, '.cta{border:1px solid}'), 'Authored chrome rules are unchanged.');
foreach (array('padding:2em', 'padding:1em') as $body) {
    $assert(str_contains($all, $body), 'The route rule `' . $body . '` is kept.');
}

echo "page-stylesheet-dead-richtext-markers: ok\n";
