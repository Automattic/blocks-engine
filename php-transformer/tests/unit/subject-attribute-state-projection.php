<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) $failures[] = $message;
};

$source = file_get_contents(dirname(__DIR__) . '/fixtures/issue-4165-article-role-selector.html');
if (!is_string($source)) {
    throw new RuntimeException('Could not read issue-4165 regression fixture.');
}
$result = (new HtmlTransformer())->transform($source, array())->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? ''))
));

$assert(1 === preg_match('/<article class="([^"]*)"/', $markup, $articleMatch), 'the source article is rendered as a core/group article element');
$articleClasses = preg_split('/\s+/', trim((string) ($articleMatch[1] ?? ''))) ?: array();
$stateMarkers = array_values(array_filter($articleClasses, static fn (string $class): bool => 1 === preg_match('/^blocks-engine-attribute-state-[a-f0-9]{12}-\d+$/', $class)));
$marker = $stateMarkers[0] ?? '';
$assert('' !== $marker, 'the grouped article carries a class identity for its dropped role predicate');
$assert(!str_contains($markup, 'role="article"'), 'the test exercises the core/group serialization that drops the source role attribute');

$markerSelector = '.c-rich-content article.' . preg_quote($marker, '/');
$assert('' !== $marker && 1 === preg_match('/' . $markerSelector . '\s*\{[^}]*padding-top:24px[^}]*padding-bottom:112px/s', $css), 'the base rule projects its 24px top and 112px bottom padding onto the persistent class');
$assert('' !== $marker && 1 === preg_match('/@media\s*\(min-width:\s*768px\)\s*\{[^}]*' . $markerSelector . '\s*\{[^}]*padding-top:64px/s', $css), 'the responsive rule projects its 64px top padding onto the persistent class');

// The static cascade uses the desktop reference viewport (1440px), so this
// proves the emitted public stylesheet applies the expected desktop values.
$candidate = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup);
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $candidate);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$article = (new DOMXPath($dom))->query('//article')->item(0);
$assert($article instanceof DOMElement, 'the transformed public output retains an article element');
if ($article instanceof DOMElement) {
    $desktop = (new StaticCssCascade($dom, $css))->resolve($article, array('padding-top', 'padding-bottom'), array());
    $assert('64px' === ($desktop['padding-top'] ?? ''), 'the desktop cascade applies 64px top padding');
    $assert('112px' === ($desktop['padding-bottom'] ?? ''), 'the desktop cascade applies 112px bottom padding');
    $baseCss = preg_replace('/@media\\s*\\(min-width:\\s*768px\\)\\s*\\{[^{}]*\\{[^{}]*\\}[^{}]*\\}/', '', $css, 1, $removedMedia);
    $assert(1 === $removedMedia, 'the reduced page has one desktop-only media rule');
    $base = (new StaticCssCascade($dom, (string) $baseCss))->resolve($article, array('padding-top', 'padding-bottom'), array());
    $assert('24px' === ($base['padding-top'] ?? ''), 'below the breakpoint the cascade applies 24px top padding');
    $assert('112px' === ($base['padding-bottom'] ?? ''), 'below the breakpoint the cascade applies 112px bottom padding');
}

if ($failures) {
    fwrite(STDERR, "subject attribute-state projection: " . count($failures) . " of {$assertions} assertions failed\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "subject attribute-state projection: {$assertions} passed\n";
