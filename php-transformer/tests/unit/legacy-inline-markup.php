<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Blogger and older CMS content still writes <strike>, <tt> and <acronym>:
// they stay inline formatting, with their source tag, inside the paragraph.
// An image link whose image was deleted (<a href="photo.jpg"><br></a>) is the
// blank line it renders as; a fragment target keeps its anchor.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><body><main><div style="text-align: justify;">Wir haben uns <strike>gequält</strike> gearbeitet, <tt>mono</tt> und <acronym title="Abc">ABC</acronym>.</div><div class="separator" style="text-align: center;"><a href="/img/deleted.jpg" imageanchor="1"><br></a></div><p>Ende.<a id="ende"><br></a></p></main></body></html>',
)))->toArray();
$content = preg_replace('/<!--[^>]*-->/', '', (string) $result['serialized_blocks']);
$expected = 'Wir haben uns <strike>gequält</strike> gearbeitet, <tt>mono</tt> und <acronym title="Abc">ABC</acronym>.</p>';
$blankLine = '<p class="has-text-align-center separator blocks-engine-synthetic-paragraph blocks-engine-source-box-paragraph">&nbsp;</p>';
$unsupported = array_values(array_filter($result['fallbacks'], static fn(array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? null)));
if (!str_contains($content, $expected) || !str_contains($content, $blankLine) || str_contains($content, 'deleted.jpg') || !str_contains($content, 'id="ende"') || array() !== $unsupported) {
    fwrite(STDERR, "FAIL: legacy inline elements and line-break-only links convert in place\n{$content}\n" . json_encode($unsupported) . "\n");
    exit(1);
}
fwrite(STDOUT, "legacy inline markup tests: passed\n");
