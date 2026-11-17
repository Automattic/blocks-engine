<?php
declare(strict_types=1);

// Classless structural boxes must survive even when responsive CSS owns their inset (#6346).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html><html><head><title>Reduced</title><style>
*{box-sizing:border-box}body{margin:0}
section{padding:110px 8%}
@media(max-width:520px){section{padding:78px 6%}}

.inner{max-width:900px;margin:auto}
</style></head><body><main>
<section><div class="inner"><h2>Heading</h2><p>Body copy text.</p></div></section>
<section><div class="inner"><h2>Second</h2><p>More text.</p></div></section>
</main></body></html>
HTML;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$failures = array();
if (2 !== substr_count($markup, '"tagName":"section"') || 2 !== substr_count($markup, '</section><!-- /wp:group -->')) {
    $failures[] = 'both sections must remain editable section-tagged core/group wrappers';
}
if (str_contains($markup, 'padding') || str_contains($markup, '110px') || str_contains($markup, '78px')) {
    $failures[] = 'responsive padding must remain runtime-owned, not frozen in block attributes or inline styles';
}
foreach (array('section{padding:110px 8%}', '') as $rule) {
    if (!str_contains((string) preg_replace('/\s*([{}:;])\s*/', '$1', $css), $rule)) {
        $failures[] = 'missing source rule: ' . $rule;
    }
}
if (array() !== $failures) {
    fwrite(STDERR, "FAIL:\n" . implode("\n", $failures) . "\n" . $markup . "\n" . $css . "\n");
    exit(1);
}
if (in_array('--geometry', $argv, true)) {
    echo json_encode(array('sourceHtml' => $html, 'candidateHtml' => '<!doctype html><html><head><style>' . $css . '</style></head><body>' . $markup . '</body></html>'), JSON_THROW_ON_ERROR) . "\n";
} else {
    echo "responsive-structural-section-inset: passed\n";
}
