<?php
declare(strict_types=1);

/**
 * Contract that the two entry points agree.
 *
 * `HtmlTransformer` and `ArtifactCompiler` both turn a captured document into
 * blocks. Every test in this suite drives the first; every real import drives
 * the second. That left the boundary the product ships across untested, and a
 * change proven against one could be inert in the other with no signal: a
 * navigation band verified through `HtmlTransformer` reached trunk and never
 * appeared in a single compiled document.
 *
 * The two cannot be compared byte for byte — the compiler resolves asset
 * references to tokens and carries provenance the direct call has no reason to.
 * What has to hold is that the same document yields the same *kinds* of output:
 * the same block types, and the same families of generated marker class. Those
 * are what a conversion rule adds, so a rule that fires in one entry point and
 * not the other shows up here.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

/** Generated marker families, with the per-document hash folded away. */
$markerFamilies = static function (string $markup): array {
    preg_match_all('/blocks-engine-[a-z-]+(?:-[0-9a-f]{12})?(?:-[a-z0-9]+)*/', $markup, $matches);
    $families = array();
    foreach ($matches[0] as $class) {
        $families[(string) preg_replace('/-[0-9a-f]{12}/', '-<document>', $class)] = true;
    }
    ksort($families);
    return array_keys($families);
};

$blockNames = static function (string $markup): array {
    preg_match_all('/<!-- wp:([a-z0-9\/-]+)/', $markup, $matches);
    $names = array_values(array_unique($matches[1]));
    sort($names);
    return $names;
};

$compiledDocument = static function (array $files, string $sourcePath): string {
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    foreach (($compiled['source_reports']['compiled_site']['pages'] ?? array()) as $page) {
        if (is_array($page) && ($page['source_path'] ?? '') === $sourcePath) {
            return (string) ($page['block_markup'] ?? '');
        }
    }
    throw new RuntimeException('The compiler produced no document for ' . $sourcePath . '.');
};

$menu = static function (): string {
    $item = static fn (string $id, string $href, string $label): string =>
        '<div class="row"><div role="listitem" class="gutter"><div id="' . $id . '" class="cell">'
        . '<h1><a href="' . $href . '">' . $label . '</a></h1></div></div></div>';
    return '<header id="SITE_HEADER"><h1><a href="/">Studio</a></h1>'
        . '<div id="rep" class="wixui-repeater"><div role="list" class="menu">'
        . $item('i1', '/work', 'Work')
        . $item('i2', '/about', 'About')
        . $item('i3', '/resume', 'Resume')
        . $item('i4', '/contact', 'Contact')
        . '</div></div></header>';
};

$css = '.menu{display:flex;position:relative}.row{display:flex}.gutter{margin:3px 0}'
    . '.cell{margin:28px 0 30px;position:relative}.hero{display:grid;grid-template-columns:1fr 1fr}';

$cases = array(
    // Styles authored in the document.
    'inline stylesheet' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><style>' . $css . '</style></head><body>'
                . $menu() . '<main class="hero"><h2>Home</h2><p>Body</p></main></body></html>',
        ),
        'options' => array(),
    ),
    // Styles in a linked file, which the compiler supplies as a payload.
    'linked stylesheet' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="assets/site.css"></head><body>'
                . $menu() . '<main class="hero"><h2>Home</h2><p>Body</p></main></body></html>',
            'assets/site.css' => $css,
        ),
        'options' => array('static_css' => $css),
    ),
    // Two viewport documents in one file, the shape a responsive capture takes.
    'responsive document variants' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><style>' . $css . '</style></head><body>'
                . '<div class="data-liberation-desktop-document">' . $menu() . '<main class="hero"><h2>Home</h2></main></div>'
                . '<div class="data-liberation-mobile-document">' . $menu() . '<main class="hero"><h2>Home</h2></main></div>'
                . '</body></html>',
        ),
        'options' => array(),
    ),
    // A stylesheet the document admits only at a viewport. The compiler derives
    // that condition from the link; a caller that concatenates file contents
    // loses it and resolves rules the page never applies unconditionally. This
    // case exists because that difference is what made a merged change look
    // verified while being inert in every import.
    'media-scoped stylesheet' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="assets/site.css" media="(min-width:769px)"></head><body>'
                . $menu() . '<main class="hero"><h2>Home</h2><p>Body</p></main></body></html>',
            'assets/site.css' => $css,
        ),
        'options' => array('static_css' => '@media (min-width:769px){' . $css . '}'),
    ),
    // More than one document, so shared chrome is in play.
    'sibling documents' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><style>' . $css . '</style></head><body>'
                . $menu() . '<main class="hero"><h2>Home</h2></main></body></html>',
            'about/index.html' => '<!doctype html><html><head><style>' . $css . '</style></head><body>'
                . $menu() . '<main class="hero"><h2>About</h2></main></body></html>',
        ),
        'options' => array(),
    ),
);

foreach ($cases as $label => $case) {
    $direct = (new HtmlTransformer())->transform(
        $case['files']['index.html'],
        array_merge(array('source' => 'index.html'), $case['options'])
    )->serializedBlocks;
    $compiled = $compiledDocument($case['files'], 'index.html');

    $directBlocks = $blockNames($direct);
    $compiledBlocks = $blockNames($compiled);
    $assert(
        $directBlocks === $compiledBlocks,
        $label . ': the entry points emit different block types. Only in HtmlTransformer: '
            . implode(', ', array_diff($directBlocks, $compiledBlocks))
            . '. Only in ArtifactCompiler: ' . implode(', ', array_diff($compiledBlocks, $directBlocks)) . '.'
    );

    $directMarkers = $markerFamilies($direct);
    $compiledMarkers = $markerFamilies($compiled);
    $missing = array_values(array_diff($directMarkers, $compiledMarkers));
    $assert(
        array() === $missing,
        $label . ': a conversion rule fired in HtmlTransformer and not in ArtifactCompiler, so it would be inert in an import. '
            . 'Missing marker families: ' . implode(', ', $missing) . '.'
    );
}

fwrite(STDOUT, sprintf('entry-point transform parity contract passed (%d documents)%s', count($cases), PHP_EOL));
