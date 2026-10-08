<?php
/**
 * Prints one source-root HTML document with its canonical artifact includes
 * resolved by HtmlFragmentIncludes, the same step compilation applies.
 *
 * Usage: php resolve-html-includes.php <source root> <root-relative .html path>
 *
 * Includes resolve against the source root ("/parts/<name>.html"). Missing
 * fragments, cycles, malformed directives and exceeded budgets exit non-zero,
 * so callers never mistake unresolved source for the captured document.
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\HtmlFragmentIncludes;

$root = realpath((string) ($argv[1] ?? ''));
$page = (string) ($argv[2] ?? '');
if (false === $root || !is_dir($root) || 1 !== preg_match('/\.html?$/i', $page)) {
    fwrite(STDERR, "Usage: resolve-html-includes.php <source root> <root-relative .html path>\n");
    exit(2);
}
$read = static function (string $relative) use ($root): array {
    $file = realpath($root . '/' . $relative);
    if (false === $file || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) throw new InvalidArgumentException('source_document_outside_root: ' . $relative);
    return array('path' => $relative, 'content' => (string) file_get_contents($file));
};
try {
    $files = array($read($page));
    foreach (glob($root . '/parts/*.html') ?: array() as $fragment) {
        $relative = 'parts/' . basename($fragment);
        if ($relative !== $page) $files[] = $read($relative);
    }
    $resolved = (new HtmlFragmentIncludes())->expand(
        $files,
        array('index.html'),
        array('max_file_bytes' => ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES, 'max_total_bytes' => ArtifactNormalizer::DEFAULT_MAX_TOTAL_BYTES),
        static fn(array $file): array => array('content' => $file['content'], 'bytes' => strlen($file['content']))
    );
    fwrite(STDOUT, $resolved[0]['content']);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
