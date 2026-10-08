<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\HtmlFragmentIncludes;

// Disable automatic collection to verify ownership, independent of GC's
// adaptive threshold or other work happening in the importing host.
$gcEnabled = gc_enabled();
gc_collect_cycles();
gc_disable();
$expander = new HtmlFragmentIncludes();
$limits = array('max_file_bytes' => 16 * 1024 * 1024, 'max_total_bytes' => 32 * 1024 * 1024);
$payload = static function (array $file, string $path): array {
    $content = $file['content'] ?? str_repeat('neutral fragment ', 262144);
    return array('content' => $content, 'bytes' => strlen($content));
};
$start = memory_get_usage();
$hashes = array();
try {
    foreach (array(false, true) as $fail) {
        for ($i = 0; $i < 25; ++$i) {
            $files = array(
                array('path' => 'website/parts/header.html'),
                array('path' => 'website/index.html', 'content' => '<main><!--#include virtual="/parts/header.html" -->' . ($fail ? '<!--#include virtual="/parts/missing.html" -->' : '') . '</main>'),
            );
            try {
                $result = $expander->expand($files, array('website/index.html'), $limits, $payload);
                if ($fail) throw new RuntimeException('Missing includes must still fail.');
                $expected = '<main>' . str_repeat('neutral fragment ', 262144) . '</main>';
                if ($result[1]['content'] !== $expected || 'template-part' !== $result[0]['role'] || empty($result[1]['metadata']['compilation']['resolved_html_includes'])) {
                    throw new RuntimeException('Expansion must preserve content and component ownership.');
                }
                $hashes[] = hash('sha256', $result[1]['content']);
                unset($result, $expected);
            } catch (InvalidArgumentException $error) {
                if (!$fail || 'html_include_missing_path: website/parts/missing.html' !== $error->getMessage()) throw $error;
                unset($error);
            }
            unset($files);
            $retained = memory_get_usage() - $start;
            if ($retained > 2 * 1024 * 1024) {
                throw new RuntimeException(sprintf('Fragment expansion retained %d bytes after call %d (%s); source payloads must be released on return and throw.', $retained, $i + 1, $fail ? 'failure' : 'success'));
            }
        }
    }
    if (1 !== count(array_unique($hashes))) throw new RuntimeException('Repeated expansion changed output identity.');
    print json_encode(array('calls' => 50, 'retained_bytes' => memory_get_usage() - $start, 'peak_bytes' => memory_get_peak_usage(true), 'output_sha256' => $hashes[0]), JSON_PRETTY_PRINT) . "\n";
} finally {
    if ($gcEnabled) gc_enable();
    gc_collect_cycles();
}
