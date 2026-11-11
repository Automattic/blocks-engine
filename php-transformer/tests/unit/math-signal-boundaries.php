<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$rows = array(
    array('<h2 id="keep-the-math-traceable">Keep the math traceable</h2>', 'core/heading'),
    array('<p>Body.</p>', 'core/paragraph'),
    array('<h3 id="latex-paint-tips">Latex paint tips</h3>', 'core/heading'),
    array('<p class="math-note">Show your work.</p>', 'core/paragraph'),
    array('<span class="katex">x^2</span>', 'core/math'),
    array('<math><mi>x</mi></math>', 'core/math'),
    array('<p id="math">An authored anchor.</p>', 'core/paragraph'),
    array('<p class="note_tex">A compound class.</p>', 'core/paragraph'),
    array('<p class="latex-paint-tips">Paint tips.</p>', 'core/paragraph'),
);
foreach ( array('math', 'katex-display', 'katex-html', 'mathjax', 'mjx-container', 'latex', 'tex', 'arithmatex') as $class ) {
    $rows[] = array('<span class="other ' . $class . "\t" . ' extra">x^2</span>', 'core/math');
}
foreach ( array('data-math', 'data-latex', 'data-tex') as $attribute ) {
    $rows[] = array('<span ' . $attribute . '="x^2">x^2</span>', 'core/math');
    $rows[] = array('<span ' . $attribute . '>x^2</span>', 'core/math');
}
foreach ( array('div', 'p', 'span') as $tag ) {
    $rows[] = array('<' . $tag . '>\\(x^2\\)</' . $tag . '>', 'core/math');
}
foreach ( range(1, 6) as $level ) {
    $rows[] = array('<h' . $level . ' class="math" data-latex="x^2">$x^2$</h' . $level . '>', 'core/heading');
}
$rows[] = array('<h2>With <span class="katex">x^2</span></h2>', 'core/heading');
$rows[] = array('<h3>With <math><mi>x</mi></math></h3>', 'core/heading');

$html = '<main>' . implode('', array_column($rows, 0)) . '</main>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array('website/index.html' => $html),
))->toArray();
$names = array();
$visit = static function (array $blocks) use (&$visit, &$names): void {
    foreach ( $blocks as $block ) {
        if ( 'core/group' !== $block['blockName'] ) {
            $names[] = $block['blockName'];
        }
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);
if ( array_column($rows, 1) !== $names ) {
    throw new RuntimeException('Math signal boundaries: expected ' . json_encode(array_column($rows, 1)) . ', got ' . json_encode($names));
}

echo "Math signal boundaries passed\n";
