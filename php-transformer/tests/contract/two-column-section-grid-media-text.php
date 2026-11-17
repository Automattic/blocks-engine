<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #6328's neutral reduced page. Also consumed by the browser geometry test.
$html = <<<'HTML'
<!doctype html><html><head><title>Reduced</title><style>
*{box-sizing:border-box}body{margin:0}
section{padding:110px 8%}
.split{display:grid;grid-template-columns:1fr 1fr;gap:12%;align-items:center}
.split-image img{width:100%;height:680px;object-fit:cover;display:block}
@media(max-width:520px){section{padding:78px 6%}}
@media(max-width:850px){.split{grid-template-columns:1fr;gap:34px}.split-image img{height:450px}}
</style></head><body><main>
<section class="split"><div class="split-image"><img src="photo.jpg" alt="Photo"></div><div><h2>Heading</h2><p>Body copy text.</p></div></section>
</main></body></html>
HTML;

$compile = static fn (string $source): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html', 'files' => array('index.html' => $source),
))->toArray();
$result = $compile($html);
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $html, 'result' => $result), JSON_THROW_ON_ERROR);
    exit;
}
$failures = array();
// Test each independent reason to decline, not just a page with both triggers.
foreach (array(
    'reduced page' => $html,
    'breakpoint without gap' => str_replace(array('gap:12%;', ';gap:34px'), '', $html),
    'gap without track breakpoint' => str_replace('grid-template-columns:1fr;gap:34px', 'gap:34px', $html),
) as $label => $source) {
    $artifact = $compile($source);
    $markup = (string) ($artifact['serialized_blocks'] ?? '');
    foreach (array('<section ', 'split', '<!-- wp:image', '<!-- wp:heading', '<!-- wp:paragraph') as $needle) {
        if (! str_contains($markup, $needle)) {
            $failures[] = $label . ': missing editable source structure: ' . $needle;
        }
    }
    if (str_contains($markup, '<!-- wp:media-text') || str_contains($markup, '<!-- wp:html')) {
        $failures[] = $label . ': source layout was replaced or made opaque';
    }
    if (preg_match('/<section[^>]*style="[^"]*(?:padding|grid-template|gap)/', $markup)) {
        $failures[] = $label . ': inline geometry freezes responsive CSS';
    }
    $css = implode("\n", array_column(array_filter($artifact['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    // The selectors still match the preserved section and wrappers; source
    // declarations remain runtime-owned, including both media-query branches.
    preg_match('/<style>(.*?)<\/style>/s', $source, $matches);
    foreach (array_filter(explode("\n", trim($matches[1])), static fn (string $rule): bool => ! str_starts_with($rule, '*')) as $rule) {
        // Image lowering may append selector repairs inside a media query.
        $rule = str_starts_with($rule, '@media') ? substr($rule, 0, -1) : $rule;
        if (! str_contains($css, $rule)) {
            $failures[] = $label . ': missing runtime rule: ' . $rule;
        }
    }
}
if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "two-column-section-grid-media-text: passed\n";
