<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * A link button inside a device document keeps the source look.
 *
 * Device captures ship each device's author CSS in a head <style> that a
 * script turns on (`media="not all"` plus `data-dla-source-media="all"`), and
 * every rule is scoped to `[data-dla-device-document="desktop"]`. The inline
 * anchor becomes a synthetic paragraph that holds the anchor itself.
 *
 * 1. The `a { text-decoration: none }` reset in that sheet still counts as
 *    unconditional: `media="all"` gates nothing. Otherwise the paragraph
 *    loses its undecorated marker and the link shows an underline.
 * 2. The paragraph does not repeat the anchor's projection classes. The
 *    anchor keeps them in the content; on the paragraph they paint the
 *    button's border and background a second time around it.
 */

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$css = 'a{cursor:pointer;text-decoration:none}'
    . '.btn{display:flex;align-items:center;min-width:100%}'
    . '.box[aria-disabled="false"] .btn{background-color:#ffdc62;border:solid #000 1px}'
    . '.box{position:relative;width:204px;height:40px}';
$scoped = static fn (string $sheet): string => (string) preg_replace_callback(
    '/(^|})([^{}]+)\{/',
    static fn (array $m): string => $m[1] . ':where([data-dla-device-document="desktop"]) ' . trim($m[2]) . '{',
    $sheet
);
$page = static fn (string $sheet): string => '<!doctype html><html lang="en"><head><title>Hero</title>'
    . '<style data-dla-device-visibility="">[data-dla-device-document]{display:none!important}'
    . 'html:not([data-dla-selected-document]) [data-dla-device-document="desktop"]{display:contents!important}</style>'
    . '<style data-dla-device-style="desktop" data-dla-source-media="all" media="not all">' . $sheet . '</style>'
    . '<script data-dla-device-selection="">document.documentElement.setAttribute("data-dla-selected-document","desktop");</script>'
    . '</head><body><div class="data-liberation-desktop-document" data-dla-device-document="desktop" data-dla-document-scope="">'
    . '<section class="hero"><div class="box" aria-disabled="false"><a href="/account/" class="btn"><span>Sign In</span></a></div>'
    . '<div class="box" aria-disabled="false"><a href="/contact/" class="btn"><span>Contact Us</span></a></div></section>'
    . '</div></body></html>';
$compile = static fn (string $html): string => (string) ((new ArtifactCompiler())->compile(
    array('entrypoint' => 'index.html', 'files' => array('index.html' => $html))
)->toArray()['serialized_blocks'] ?? '');
$classes = static fn (string $tag): array => 1 === preg_match('/\sclass="([^"]*)"/', $tag, $m) ? (preg_split('/\s+/', trim($m[1])) ?: array()) : array();

$blocks = $compile($page($scoped($css)));
preg_match_all('/<p\b[^>]*blocks-engine-synthetic-paragraph[^>]*>\s*(<a\b[^>]*>)/', $blocks, $carriers, PREG_SET_ORDER);
$assert(2 === count($carriers), 'Both link buttons convert to synthetic paragraph carriers. Got: ' . $blocks);
foreach ($carriers as [$paragraph, $anchor]) {
    $paragraphClasses = $classes($paragraph);
    $anchorClasses = $classes($anchor);
    $assert(
        in_array('blocks-engine-synthetic-anchor-undecorated', $paragraphClasses, true),
        'The author link reset in a media="all" device sheet still marks the carrier undecorated. Got: ' . $paragraph
    );
    $projection = array_values(array_filter($anchorClasses, static fn (string $class): bool => str_starts_with($class, 'blocks-engine-attribute-')));
    $assert(array() !== $projection, 'The anchor keeps the projection classes its device-scoped rules select. Got: ' . $anchor);
    $assert(
        array() === array_intersect($projection, $paragraphClasses),
        'The carrier paragraph does not repeat the anchor projection classes. Got: ' . $paragraph
    );
}

// Negative: without an authored reset the link keeps its UA underline.
$plain = $compile($page($scoped(str_replace('a{cursor:pointer;text-decoration:none}', 'a{cursor:pointer}', $css))));
$assert(str_contains($plain, 'blocks-engine-synthetic-paragraph'), 'The control still converts to a synthetic paragraph.');
$assert(!str_contains($plain, 'blocks-engine-synthetic-anchor-undecorated'), 'An anchor with no authored reset is not marked undecorated.');

echo "device-style-synthetic-anchor-carrier: ok\n";
