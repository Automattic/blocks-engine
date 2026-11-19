<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A menu bar the author hides below its own 1023px breakpoint (a Wix menu that
// turns into a hamburger) is captured visible at 1440px and hidden at 402px.
// Its carrier must hide it from 1023px down, not only from the 402px capture
// down: at a tablet width the source shows no menu labels, so the import must
// not either. Captured values of a box that is not rendered at a capture (the
// hidden bar, or a child of it) are no layout evidence and are not restated.
// Evidence: n5 is <main> (the bar), n6 the <h1> inside it.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);

$assert = static function (bool $ok, string $message, string $output): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $output);
    echo 'PASS: ' . $message . "\n";
};

$compile = static function (string $author) use ($html, $tree): string {
    $source = str_replace('</head>', '<style>' . $author . '</style></head>', $html);
    $tree['source_hash'] = hash('sha256', $source);
    $tree['viewports']['mobile']['width'] = 402;
    $observed = array(
        'n5' => array(array('display' => 'block', 'width' => '1200px'), array('display' => 'none', 'width' => '100%')),
        'n6' => array(array('width' => '1200px'), array('width' => '100%')),
    );
    foreach ($observed as $node => [$desktop, $mobile]) {
        $tree['nodes'][$node]['viewports']['desktop']['used_styles'] = array_replace($tree['nodes'][$node]['viewports']['desktop']['used_styles'], $desktop);
        $tree['nodes'][$node]['viewports']['mobile']['used_styles'] = array_replace($tree['nodes'][$node]['viewports']['mobile']['used_styles'], $mobile);
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_column($result['assets'], 'content'));
    preg_match_all('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|[^{}@]*\.be-inline-geometry-[^{}]*\{[^{}]*\}/', $css, $rules);
    return implode("\n", array_values(array_filter($rules[0], static fn(string $rule): bool => str_contains($rule, 'be-inline-geometry-'))));
};

// The author's switch, reached through a custom property as Wix writes it.
$css = $compile('main{display:var(--bar-display,block)}@media screen and (max-width: 1023px){body{--bar-display:none}}');
$assert(1 === preg_match('/@media \(max-width:1023px\)\{\.be-inline-geometry-[a-f0-9]{16}\.be-inline-geometry-[a-f0-9]{16}\{display:none\}/', $css), 'The bar is hidden from the author\'s 1023px breakpoint down.', $css);
$assert(!preg_match('/@media \(max-width:402px\)\{[^}]*display:none/', $css), 'The hide is not frozen at the 402px capture width.', $css);
$assert(!str_contains($css, 'width:100%'), 'Neither the hidden bar nor its child restates values captured while not rendered.', $css);

// Without an author switch between the captures the capture delta stands.
$css = $compile('main{display:block}');
$assert(1 === preg_match('/@media \(max-width:402px\)\{[^}]*display:none/', $css), 'A display the author does not switch keeps the capture delta.', $css);
$assert(!str_contains($css, 'max-width:1023px'), 'No band is invented without an author breakpoint.', $css);

echo "Box-tree display breakpoint passed\n";
