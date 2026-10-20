<?php
declare(strict_types=1);

/**
 * An all-digit class token of three or more characters is stored as an int
 * array key in the component-fact classes map (PHP normalizes numeric string
 * keys), so `finalizeComponentFacts()` passed an int to `preg_match()` and
 * threw `TypeError: preg_match(): Argument #2 ($subject) must be of type
 * string, int given` during staged composition. `mergeComponentFacts()`
 * carried the same int keys, and the finalized component `name` became an
 * int. Class tokens must stay strings through detection, merge and
 * finalization. See blocks-engine#3098.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$fixture = '<!doctype html><html><head><meta charset="utf-8"><title>Numeric class</title><style>.box{padding:16px}</style></head><body><main><div class="box 222222222225"><p>Hello</p></div></main></body></html>';

/** Compile every page through the staged artifact path and compose the receipts. */
$stagedCompose = static function (array $files): array {
    $artifact = array('entrypoints' => array('index.html'), 'files' => $files);
    $compiler = new ArtifactCompiler();
    $shared = $compiler->prepareShared($artifact);
    $pageIds = array_values(array_filter(array_map(static fn (array $file): string => (string) $file['path'], $files), static fn (string $path): bool => str_ends_with($path, '.html')));
    $compiled = array();
    foreach ($pageIds as $pageId) $compiled[$pageId] = $compiler->compilePage($artifact, $shared, $pageId);
    return $compiler->compose($shared, $compiled)->toArray();
};

$componentNames = static fn (array $result): array => array_map(
    static fn ($component): string => (string) ($component['name'] ?? ''),
    is_array($result['components'] ?? null) ? $result['components'] : array()
);

// The issue's reduced page: the numeric token occurs once, so finalization
// runs preg_match() over its (int) class map key. Compose must complete, and
// every finalized component fact name must be a string.
$result = $stagedCompose(array(array('path' => 'index.html', 'content' => $fixture)));
$assert(str_starts_with((string) ($result['status'] ?? ''), 'success'), 'The staged compile+compose of the numeric-class page completes without a TypeError.', (string) ($result['status'] ?? ''));
$intNames = array_filter(
    is_array($result['components'] ?? null) ? $result['components'] : array(),
    static fn ($component): bool => is_array($component) && !is_string($component['name'] ?? null)
);
$assert(array() === $intNames, 'Every finalized component fact name is a string.', json_encode($intNames));

// With the numeric token occurring twice across the site, the class-token
// component is retained (occurrences >= 2). Its finalized name must be the
// string the source authored, not the int key PHP normalized it to.
$secondPage = '<!doctype html><html><head><meta charset="utf-8"><title>Numeric class again</title></head><body><main><div class="222222222225"><p>Again</p></div></main></body></html>';
$result = $stagedCompose(array(
    array('path' => 'index.html', 'content' => $fixture),
    array('path' => 'numeric.html', 'content' => $secondPage),
));
$assert(str_starts_with((string) ($result['status'] ?? ''), 'success'), 'The two-page staged compile+compose completes.', (string) ($result['status'] ?? ''));
$matching = array_values(array_filter(
    is_array($result['components'] ?? null) ? $result['components'] : array(),
    static fn ($component): bool => is_array($component) && '222222222225' === ($component['name'] ?? null)
));
$assert(array() !== $matching, "The finalized component facts contain a component named '222222222225'.", json_encode($componentNames($result)));
$component = $matching[0] ?? array();
$assert(is_string($component['name'] ?? null) && '222222222225' === $component['name'], "The finalized component name is the string '222222222225'.", var_export($component['name'] ?? null, true));
$assert('class-token' === ($component['signal'] ?? null), 'The component is detected from the class token signal.', (string) ($component['signal'] ?? ''));
$assert(2 === ($component['occurrences'] ?? null), 'Occurrence counts merge across the page receipts.', (string) ($component['occurrences'] ?? ''));

if ( 0 < $failures ) {
    fwrite(STDERR, "numeric class token component facts FAILED: {$failures} failed\n");
    exit(1);
}
echo "numeric class token component facts passed\n";
