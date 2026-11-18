<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A narrower capture's delta applies from the author's own breakpoint, not
// from the capture width: a value the author declares inside
// `@media (max-width:1023px)` and the capture observed at 402px holds at
// 768px too. Evidence: n6 is the <h1>.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);

/** @param array<string, array{0: string, 1: string}> $observed node.property => [desktop, mobile] */
function switch_width_carriers(string $html, array $tree, string $author, array $observed): string
{
    $source = str_replace('</head>', '<style>' . $author . '</style></head>', $html);
    $tree['source_hash'] = hash('sha256', $source);
    $tree['viewports']['mobile']['width'] = 402;
    foreach ($observed as $target => [$desktop, $mobile]) {
        [$node, $property] = explode('.', $target, 2);
        $tree['nodes'][$node]['viewports']['desktop']['used_styles'][$property] = $desktop;
        $tree['nodes'][$node]['viewports']['mobile']['used_styles'][$property] = $mobile;
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_column($result['assets'], 'content'));
    preg_match_all('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|[^{}@]*\.be-inline-geometry-[^{}]*\{[^{}]*\}/', $css, $rules);

    return implode("\n", array_values(array_filter($rules[0], static fn(string $rule): bool => str_contains($rule, 'be-inline-geometry-'))));
}

/** The media query a carrier delta declaring `$declaration` sits in, or null. */
function switch_width_media(string $carriers, string $declaration): ?string
{
    foreach (explode("\n", $carriers) as $rule) {
        if (str_contains($rule, $declaration) && preg_match('/^@media\s*(\([^{]*\))\{/', $rule, $match)) return $match[1];
    }
    return null;
}

function switch_width_assert(bool $condition, string $message, string $output): void
{
    if (!$condition) throw new RuntimeException($message . "\n" . $output);
    echo 'PASS: ' . $message . "\n";
}

$cases = array(
    'a value the author switches at 1023px applies from 1023px' => array(
        'h1{text-align:left}@media screen and (max-width:1023px){h1{text-align:center}}',
        array('n6.text-align' => array('left', 'center')),
        'text-align:center',
        '(max-width:1023px)',
    ),
    'a min-width switch ends the narrow band below it' => array(
        'h1{opacity:.5}@media (min-width:1024px){h1{opacity:1}}',
        array('n6.opacity' => array('1', '0.5')),
        'opacity:0.5',
        '(max-width:1023px)',
    ),
    'a grid placement set through grid-area follows the author breakpoint' => array(
        'main{display:grid}h1{grid-area:1/2/2/3}@media screen and (max-width:1023px){h1{grid-area:2/1/3/2}}',
        array('n6.grid-row-start' => array('1', '2'), 'n6.grid-column-start' => array('2', '1')),
        'grid-row-start:2',
        '(max-width:1023px)',
    ),
    'the narrow value holds only up to the first author switch above it' => array(
        'h1{opacity:1}@media (max-width:1023px){h1{opacity:.7}}@media (max-width:766px){h1{opacity:.5}}',
        array('n6.opacity' => array('1', '0.5')),
        'opacity:0.5',
        '(max-width:766px)',
    ),
    'a layout-dependent length stays at the capture width' => array(
        'h1{width:500px}@media (max-width:1023px){h1{width:auto}}',
        array('n6.width' => array('500px', '300px')),
        'width:300px',
        '(max-width:402px)',
    ),
    'a difference the author never switches stays at the capture width' => array(
        'h1{opacity:1}',
        array('n6.opacity' => array('1', '0.5')),
        'opacity:0.5',
        '(max-width:402px)',
    ),
);
foreach ($cases as $name => [$author, $observed, $declaration, $media]) {
    $carriers = switch_width_carriers($html, $tree, $author, $observed);
    switch_width_assert($media === switch_width_media($carriers, $declaration), $name, $carriers);
}
