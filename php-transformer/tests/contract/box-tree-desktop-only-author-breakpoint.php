<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A route captured at the desktop viewport only (its producer found no
// phone-specific document) still ships the author's mobile-first grid. Below
// the author's 768px breakpoint the source stacks two grid items in an
// 8-track grid; from 768px up it places them side by side in 24 tracks. The
// box tree observes only the 1440px placement, but the carriers must keep the
// author's narrower variant: lifted author intent is not limited to the range
// between captures. Evidence: n5 is <main> (the grid), n6 the <h1>, n7 the <p>.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);

$assert = static function (bool $ok, string $message, string $output): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $output);
    echo 'PASS: ' . $message . "\n";
};

$compile = static function (string $author) use ($html, $tree): string {
    $source = str_replace('</head>', '<style>' . $author . '</style></head>', $html);
    $tree['source_hash'] = hash('sha256', $source);
    unset($tree['viewports']['mobile']);
    $observed = array(
        'n5' => array('display' => 'grid', 'width' => '1440px', 'grid-template-columns' => implode(' ', array_fill(0, 24, '60px'))),
        'n6' => array('width' => '720px', 'grid-row-start' => '1', 'grid-column-start' => '1', 'grid-row-end' => '2', 'grid-column-end' => '13'),
        'n7' => array('width' => '720px', 'grid-row-start' => '1', 'grid-column-start' => '13', 'grid-row-end' => '2', 'grid-column-end' => '25'),
    );
    foreach ($tree['nodes'] as $node => $entry) {
        unset($tree['nodes'][$node]['viewports']['mobile']);
        if (isset($observed[$node])) $tree['nodes'][$node]['viewports']['desktop']['used_styles'] = array_replace($entry['viewports']['desktop']['used_styles'], $observed[$node]);
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_column($result['assets'], 'content'));
    preg_match_all('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|[^{}@]*\.be-inline-geometry-[^{}]*\{[^{}]*\}/', $css, $rules);
    return implode("\n", array_values(array_filter($rules[0], static fn(string $rule): bool => str_contains($rule, 'be-inline-geometry-'))));
};

$css = $compile('main{display:grid;grid-template-columns:repeat(8,minmax(0,1fr))}h1{grid-area:1/1/2/9}p{grid-area:2/1/3/9}'
    . '@media (min-width:768px){main{grid-template-columns:repeat(24,minmax(0,1fr))}h1{grid-area:1/1/2/13}p{grid-area:1/13/2/25}}');
$assert(1 === preg_match('/@media \(max-width:767px\)\{[^@]*grid-template-columns:repeat\(8,\s*minmax\(0(px)?,\s*1fr\)\)/', $css), 'The 8-track grid holds below the author\'s 768px breakpoint.', $css);
$assert(1 === preg_match('/@media \(max-width:767px\)\{[^@]*grid-row:2 \/ 3/', $css), 'The second item stacks below the author\'s breakpoint.', $css);
$assert(1 === preg_match('/grid-column:13 \/ 25/', $css), 'The captured desktop placement is kept.', $css);

// Without an author variant outside the capture the desktop values stand alone.
$css = $compile('main{display:grid;grid-template-columns:repeat(24,minmax(0,1fr))}h1{grid-area:1/1/2/13}p{grid-area:1/13/2/25}');
$assert(!str_contains($css, '@media'), 'No band is invented without an author breakpoint.', $css);

echo "Box-tree desktop-only author breakpoint passed\n";
