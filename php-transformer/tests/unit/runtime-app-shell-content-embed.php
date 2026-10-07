<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

// A script that addresses the sections by data attribute makes them runtime targets.
$script = 'document.querySelectorAll("[data-reveal]").forEach(function(el){el.setAttribute("data-reveal","done");});';
$transform = static fn (string $body): array => ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array( 'index.html' => '<!doctype html><html><head><title>Article</title></head><body>' . $body . '<script>' . $script . '</script></body></html>' ),
))->toArray();
$appShellIslands = static fn (array $result): array => array_values(array_filter(
    $result['source_reports']['runtime_islands'] ?? array(),
    static fn (array $island): bool => 'app_shell' === ( $island['kind'] ?? '' )
));
$page = static fn (string $embed, string $rootAttrs = 'id="root"'): string => '<div ' . $rootAttrs . '><main>'
    . '<section><h1>Article</h1><p>Intro copy.</p></section>'
    . '<section data-reveal="pending"><p>Video below</p>' . $embed . '</section>'
    . '<section data-reveal="pending"><p>More copy</p></section>'
    . '</main></div>';

// An ordinary third-party content embed inside an article wrapper is not a workspace surface.
foreach ( array( 'id="root"', 'class="article-body"' ) as $rootAttrs ) {
    $result = $transform($page('<iframe src="https://media.example.test/embed/abc" title="Explainer" width="560" height="315"></iframe>', $rootAttrs));
    $markup = (string) ( $result['serialized_blocks'] ?? '' );
    $assert(array() === $appShellIslands($result), "content embed does not make a {$rootAttrs} wrapper an app shell: " . json_encode($appShellIslands($result)));
    $assert('success' === ( $result['status'] ?? '' ) || 'success_with_warnings' === ( $result['status'] ?? '' ), "content embed wrapper {$rootAttrs} compiles");
    $assert(! str_starts_with($markup, '<!-- wp:html'), "content embed wrapper {$rootAttrs} is not preserved whole as core/html");
    $assert(str_contains($markup, '<!-- wp:paragraph --><p>Intro copy.</p>'), "content outside the runtime targets stays a native block for {$rootAttrs}");
    $assert(str_contains($markup, '<iframe') && str_contains($markup, 'Intro copy.') && str_contains($markup, 'More copy'), "content and embed survive for {$rootAttrs}");
}

// An iframe with inline document content or no external destination is an app-like surface.
foreach ( array(
    'srcdoc'      => '<iframe srcdoc="&lt;p&gt;preview&lt;/p&gt;" title="Preview"></iframe>',
    'no src'      => '<iframe title="Preview"></iframe>',
    'same origin' => '<iframe src="/preview.html" title="Preview"></iframe>',
) as $label => $embed ) {
    $result = $transform($page($embed, 'class="article-body"'));
    $assert(array() !== $appShellIslands($result), "an iframe with {$label} still marks the wrapper as an app shell");
}

// Other workspace surfaces and root tokens keep their shell treatment next to a content embed.
$embed = '<iframe src="https://media.example.test/embed/abc" title="Explainer"></iframe>';
$assert(array() !== $appShellIslands($transform($page($embed . '<canvas></canvas>', 'class="article-body"'))), 'a canvas still marks the wrapper as an app shell');
$assert(array() !== $appShellIslands($transform($page($embed, 'id="workspace"'))), 'an app-root token still marks the wrapper as an app shell');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "runtime app shell content embed tests passed\n";
