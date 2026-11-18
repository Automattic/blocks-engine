<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// ADR 0005 §4: between two captures a carrier's frozen pixels come from the
// wider capture. A block the container sizes at both captures must follow the
// container at the widths in between, or a 1440px page stays 1440px wide (and
// clips its content) at 768px. Evidence: n4 <body>, n5 <main>, n6 <h1>.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);

/** @param array<string, array{0: float, 1: float}> $widths node => [desktop, mobile] border-box width */
function intercapture_output(string $html, array $tree, string $author, array $widths): string
{
    $source = str_replace('</head>', '<style>' . $author . '</style></head>', $html);
    $tree['source_hash'] = hash('sha256', $source);
    $tree['viewports']['mobile']['width'] = 402;
    foreach ($tree['nodes'] as $node => &$entry) {
        foreach (array('desktop' => 0, 'mobile' => 1) as $viewport => $index) {
            $width = $widths[$node][$index] ?? 100.0;
            $entry['viewports'][$viewport]['border_box']['width'] = $width;
            foreach (array('margin-left', 'margin-right', 'padding-left', 'padding-right') as $property) {
                $entry['viewports'][$viewport]['used_styles'][$property] = '0px';
            }
            foreach (array('border-left', 'border-right') as $property) {
                $entry['viewports'][$viewport]['used_styles'][$property] = '0px none rgb(0, 0, 0)';
            }
            $entry['viewports'][$viewport]['used_styles']['width'] = $width . 'px';
        }
    }
    unset($entry);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_column($result['assets'], 'content'));
    preg_match_all('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|[^{}@]*\.be-inline-geometry-[^{}]*\{[^{}]*\}/', $css, $rules);

    return implode("\n", array_values(array_filter($rules[0], static fn(string $rule): bool => str_contains($rule, 'be-inline-geometry-'))));
}

function intercapture_assert(bool $condition, string $message, string $output): void
{
    if (!$condition) throw new RuntimeException($message . "\n" . $output);
    echo 'PASS: ' . $message . "\n";
}

$between = '@media (min-width:403px) and (max-width:1439px){';
/** The carrier rule groups (base rule plus its @media rules) that pin a border-box width at the desktop capture. */
function intercapture_carriers(string $css, string $desktopWidth): array
{
    preg_match_all('/\.(be-inline-geometry-[0-9a-f]+)\.\1\{[^}]*\bwidth:' . preg_quote($desktopWidth, '/') . '[;}]/', $css, $matches);
    $carriers = array();
    foreach (array_unique($matches[1]) as $class) {
        preg_match_all('/^.*\.' . $class . '\.' . $class . '\{.*$/m', $css, $rules);
        $carriers[$class] = implode("\n", $rules[0]);
    }
    return $carriers;
}

// The page root (n5 <main>) is re-homed under the transform's own <body>, so
// its captured parent comes from the tree, not the DOM. It and the <h1>
// inside it fill their containers at 1440 and 402.
$filling = intercapture_output($html, $tree, '', array('n4' => array(1440, 402), 'n5' => array(1440, 402), 'n6' => array(1440, 402)));
$carriers = intercapture_carriers($filling, '1440px');
intercapture_assert(2 === count($carriers), 'the page root and heading each carry a 1440px width', $filling);
foreach ($carriers as $rules) {
    intercapture_assert(str_contains($rules, 'width:1440px') && str_contains($rules, '@media (max-width:402px){') && str_contains($rules, 'width:402px'), 'each capture keeps its exact pixels', $rules);
    intercapture_assert(str_contains($rules, $between) && 1 === preg_match('/' . preg_quote($between, '/') . '[^{]*\{width:auto\}\}/', $rules), 'between the captures the width follows the container', $rules);
}

// A heading narrower than its container at a capture sized itself: it keeps
// its pixels in between, while the page root still follows the viewport.
$fixed = intercapture_output($html, $tree, '', array('n4' => array(1440, 402), 'n5' => array(1440, 402), 'n6' => array(1000, 402)));
$heading = intercapture_carriers($fixed, '1000px');
intercapture_assert(1 === count($heading) && !str_contains(reset($heading), $between), 'a box that does not fill its container keeps its pixels between captures', $fixed);
$root = intercapture_carriers($fixed, '1440px');
intercapture_assert(1 === count($root) && str_contains(reset($root), $between), 'its page root still follows the viewport', $fixed);

// An author pixel width is the author's, not the container's.
$authored = intercapture_output($html, $tree, 'h1{width:1440px}@media (max-width:402px){h1{width:402px}}', array('n4' => array(1440, 402), 'n5' => array(1440, 402), 'n6' => array(1440, 402)));
intercapture_assert(1 === count(array_filter(intercapture_carriers($authored, '1440px'), static fn(string $rules): bool => str_contains($rules, $between))), 'an author pixel width keeps its pixels between captures', $authored);
