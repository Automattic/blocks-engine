<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Blogger and older CMS content still writes <strike>, <tt> and <acronym>.
// They are the inline formatting they render as, inside the same paragraph.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><body><main><div style="text-align: justify;">Wir haben uns <strike>gequält</strike> gearbeitet, <tt>mono</tt> und <acronym title="Abc">ABC</acronym>.</div></main></body></html>',
)))->toArray();
$content = preg_replace('/<!--[^>]*-->/', '', (string) $result['serialized_blocks']);
$expected = 'Wir haben uns <s>gequält</s> gearbeitet, <code>mono</code> und <abbr title="Abc">ABC</abbr>.</p>';
$unsupported = array_values(array_filter($result['fallbacks'], static fn(array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? null)));
if (!str_contains($content, $expected) || array() !== $unsupported) {
    fwrite(STDERR, "FAIL: obsolete phrasing elements convert in place\n{$content}\n" . json_encode($unsupported) . "\n");
    exit(1);
}
fwrite(STDOUT, "obsolete phrasing element tests: passed\n");
