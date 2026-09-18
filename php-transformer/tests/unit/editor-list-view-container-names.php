<?php
declare(strict_types=1);

/**
 * Contract for projecting a List View label onto recognised container blocks.
 *
 * Gutenberg's List View shows metadata.name when supports.renaming is true
 * (core/group, core/cover) and otherwise falls back to the block title
 * ("Group"). The projection is comment-only: WordPress serializes metadata
 * through serialize_block_attributes() into the delimiter and save() never
 * renders it, so wrapper validity is unchanged.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EditorListViewContainerNamer;

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

$named = static function (array $blocks): array {
    $found = array();
    $walk = static function (array $nodes) use (&$walk, &$found): void {
        foreach ( $nodes as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            $name = $block['attrs']['metadata']['name'] ?? null;
            if ( is_string($name) && '' !== $name ) {
                $found[] = array(
                    'blockName' => (string) ($block['blockName'] ?? ''),
                    'name'      => $name,
                    'tagName'   => (string) ($block['attrs']['tagName'] ?? ''),
                    'className' => (string) ($block['attrs']['className'] ?? ''),
                );
            }
            $walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
        }
    };
    $walk($blocks);

    return $found;
};

$names = static function (array $entries): array {
    return array_column($entries, 'name');
};

$transform = static function (string $html, array $options = array()): array {
    return ( new HtmlTransformer() )->transform($html, $options)->toArray();
};

$page = $transform(
    '<!doctype html><html><body>'
    . '<header><p>Brand</p><nav><a href="/">Home</a><a href="/about">About</a></nav></header>'
    . '<main>'
    . '<div class="incidental"><p>Wrapper copy</p></div>'
    . '<section><h1>Welcome</h1><p>Intro</p></section>'
    . '<section><h2>Values</h2><div class="card"><h3>Quality</h3><p>Card copy</p></div></section>'
    . '</main>'
    . '<footer><p>Copyright</p><p>Address</p></footer>'
    . '</body></html>'
);

$labels = $named($page['blocks'] ?? array());
$labelNames = $names($labels);

$assert(in_array('Header', $labelNames, true), 'The header landmark is named Header.');
$assert(in_array('Main', $labelNames, true), 'The main landmark is named Main.');
$assert(in_array('Footer', $labelNames, true), 'The footer landmark is named Footer.');
$assert(in_array('Welcome', $labelNames, true), 'A section that owns an h1 is named from that heading.');
$assert(in_array('Values', $labelNames, true), 'A section that owns an h2 is named from that heading.');
$assert(! in_array('Quality', $labelNames, true), 'An incidental card wrapper around an h3 is not named.');
$assert(! in_array('Wrapper copy', $labelNames, true), 'An incidental div wrapper is not named.');
$assert(! in_array('Brand', $labelNames, true), 'Landmark labels win over descendant text.');
$assert(! in_array('Copyright', $labelNames, true), 'A footer is named as the landmark, not its paragraph text.');
$assert('pass' === ($page['source_reports']['wp_block_validity']['status'] ?? ''), 'Named landmarks remain editor-valid.');
$unnamedNavigation = static function (array $blocks) use (&$unnamedNavigation): bool {
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( 'core/navigation' === ($block['blockName'] ?? '') && isset($block['attrs']['metadata']['name']) ) {
            return false;
        }
        if ( ! $unnamedNavigation(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()) ) {
            return false;
        }
    }
    return true;
};
$assert($unnamedNavigation($page['blocks'] ?? array()), 'core/navigation keeps renaming disabled, so it is not named via metadata.name.');

$serialized = (string) ($page['serialized_blocks'] ?? '');
$assert(str_contains($serialized, '"metadata":{"name":"Header"}'), 'metadata.name serializes into the block comment delimiter.');
$assert(! str_contains($serialized, 'metadata.name') && ! preg_match('/<(?:header|main|footer|section|div)[^>]*name="Header"/', $serialized), 'metadata.name is not rendered onto the saved wrapper.');

$roleLandmarks = $transform(
    '<div role="banner"><p>Top</p><p>Bar</p></div>'
    . '<div role="main"><p>Body</p><p>More</p></div>'
    . '<div role="contentinfo"><p>Bottom</p><p>Legal</p></div>'
    . '<aside><p>Note</p><p>Copy</p></aside>'
);
$roleNames = $names($named($roleLandmarks['blocks'] ?? array()));
$assert(in_array('Header', $roleNames, true), 'role=banner maps to Header.');
$assert(in_array('Main', $roleNames, true), 'role=main maps to Main.');
$assert(in_array('Footer', $roleNames, true), 'role=contentinfo maps to Footer.');
$assert(in_array('Aside', $roleNames, true), 'An aside landmark is named Aside.');

$nested = $transform(
    '<section><h2>Outer</h2><section><h3>Inner</h3><p>Nested copy</p></section></section>'
);
$nestedNames = $names($named($nested['blocks'] ?? array()));
$assert(in_array('Outer', $nestedNames, true) && in_array('Inner', $nestedNames, true), 'Nested sections each keep the heading they own.');

$aria = $transform('<section aria-label="Team"><p>People</p><p>More</p></section>');
$assert(in_array('Team', $names($named($aria['blocks'] ?? array())), true), 'A heading-less section falls back to aria-label.');

$lineBreak = $transform('<section><h1>SUPERBLY<br>CRAFTED<br>CANNABIS</h1><p>Sub</p></section>');
$assert(in_array('SUPERBLY CRAFTED CANNABIS', $names($named($lineBreak['blocks'] ?? array())), true), 'A heading with br tags collapses to spaced visible text.');

$emptyVisual = $transform(
    '<style>.page-bg{position:fixed;inset:0;z-index:-1;background:linear-gradient(180deg,#211,#000)}</style>'
    . '<main><div class="page-bg" aria-hidden="true"></div><section><h1>Hero</h1><p>Copy</p></section></main>'
);
$emptyVisualNames = $named($emptyVisual['blocks'] ?? array());
$assert(str_contains((string) ($emptyVisual['serialized_blocks'] ?? ''), 'blocks-engine-empty-visual-group'), 'The painted empty layer is classified as an empty visual group.');
foreach ( $emptyVisualNames as $entry ) {
    $assert(
        ! str_contains($entry['className'], 'blocks-engine-empty-visual-group'),
        'Empty visual groups stay unnamed so decoration does not compete in List View.'
    );
}
$assert(in_array('Hero', $names($emptyVisualNames), true), 'The neighbouring heading section is still named.');
$assert(in_array('Main', $names($emptyVisualNames), true), 'The main landmark around the painted layer is still named.');
$assert('pass' === ($emptyVisual['source_reports']['wp_block_validity']['status'] ?? ''), 'Empty visual groups remain editor-valid beside named landmarks.');

$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML('<!doctype html><html><body><section><h2>Ignored</h2></section><div class="wrap"><h2>Not a section</h2></div></body></html>');
libxml_clear_errors();
$section = $document->getElementsByTagName('section')->item(0);
$div = $document->getElementsByTagName('div')->item(0);
$assert($section instanceof DOMElement && $div instanceof DOMElement, 'The namer fixture loaded section and div elements.');
if ( $section instanceof DOMElement && $div instanceof DOMElement ) {
    $namer = new EditorListViewContainerNamer();
    $assert(
        null === $namer->name('core/group', array( 'metadata' => array( 'name' => 'Keep me' ) ), $section),
        'An already-named container is left alone.'
    );
    $assert(
        null === $namer->name('core/paragraph', array(), $section),
        'Non-container blocks are not named.'
    );
    $assert(
        'Ignored' === $namer->name('core/cover', array(), $section),
        'A recognised cover pattern container is named from its owned heading.'
    );
    $assert(null === $namer->name('core/group', array(), $div), 'An incidental heading wrapper is not named.');
}

$article = $transform('<article><h2>Story</h2><p>Body</p></article>');
$assert(in_array('Story', $names($named($article['blocks'] ?? array())), true), 'An article that owns a heading is named from that heading.');

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('editor list view container names: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('editor list view container names tests: %d passed%s', $passes, PHP_EOL));
