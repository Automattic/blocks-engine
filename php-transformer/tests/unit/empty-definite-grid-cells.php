<?php
declare(strict_types=1);

/**
 * An empty child of a grid whose rows and columns are both definite lengths is
 * a grid item: the tracks size it, and it holds its place in auto-placement.
 * Small "pixel" icons are built exactly this way, as empty painted spans with
 * no size of their own. They must survive conversion, including the
 * transparent spacer cells that keep the coloured cells in their slots.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$compile = static function (string $html, string $css): string {
    $plan = ( new ArtifactCompiler() )->compile(array('entrypoint' => 'website/index.html', 'files' => array(
        'website/index.html' => '<link rel="stylesheet" href="css/site.css"><main>' . $html . '</main>',
        'website/css/site.css' => $css,
    )))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
    return (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
};

$cells = static function (string $markup, string $container): array {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><body>' . preg_replace('/<!--.*?-->/s', '', $markup) . '</body>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    foreach ( ( new DOMXPath($document) )->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $container . ' ")]') ?: array() as $node ) {
        $colors = array();
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $colors[] = preg_match('/background(?:-color)?:\s*([^;]+)/i', $child->getAttribute('style'), $match) ? strtolower(trim($match[1])) : '';
            }
        }
        return $colors;
    }
    return array();
};

$row = '<div class="row"><span class="cluster" aria-hidden="true"><span style="background:#2B6BFF"></span><span style="background:transparent"></span><span style="background:#35C9F0"></span></span><span>Label</span></div>';
$base = '.row{display:inline-flex;align-items:center;gap:12px}';

$markup = $compile($row, $base . '.cluster{display:inline-grid;grid-template-columns:repeat(3,6px);grid-template-rows:repeat(1,6px);gap:3px}');
$assert(array( '#2b6bff', 'transparent', '#35c9f0' ) === $cells($markup, 'cluster'), 'every empty cell of a definite-track grid is kept, in source order, with its paint');

$markup = $compile($row, $base . '.cluster{display:grid;grid-template-columns:6px 6px 6px;grid-template-rows:6px}');
$assert(3 === count($cells($markup, 'cluster')), 'explicit (non-repeat) track lists count as definite tracks');

$markup = $compile($row, $base . '.cluster{display:inline-grid;grid-template-columns:repeat(3,auto);grid-template-rows:6px}');
$assert(array() === $cells($markup, 'cluster'), 'auto column tracks give an empty item no width, so its cells are not kept');

$markup = $compile($row, $base . '.cluster{display:inline-flex;gap:3px}');
$assert(array() === $cells($markup, 'cluster'), 'empty children of a non-grid container are not kept by this rule');

$markup = $compile(
    '<div class="row"><span class="cluster"><span style="background:#2B6BFF"></span><span style="display:none;background:#35C9F0"></span></span><span>Label</span></div>',
    $base . '.cluster{display:inline-grid;grid-template-columns:repeat(2,6px);grid-template-rows:6px}'
);
$assert(array( '#2b6bff' ) === $cells($markup, 'cluster'), 'a display:none cell takes no grid slot and is still dropped');

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('empty definite grid cells: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('empty definite grid cells tests: %d passed%s', $passes, PHP_EOL));
