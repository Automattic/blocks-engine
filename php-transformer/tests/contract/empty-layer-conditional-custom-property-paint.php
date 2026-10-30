<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$paint = '.bottom .shape{background-image:var(--x,none)}';
$definition = <<<'CSS'
#band1{--x:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 10'%3E%3Cpath d='M0 0h100v10z' fill='%23fff'/%3E%3C/svg%3E")}
CSS;
$root = ':root{--y:14px}';
$cases = array(
    'style media' => array(
        'css' => '<style media="(max-width:1000px)">' . $paint . '</style><style media="(max-width:1000px)">' . $definition . '</style><style media="(max-width:1000px)">' . $root . '</style>',
        'keep' => true,
    ),
    '@media' => array(
        'css' => '<style>@media (max-width:1000px){' . $paint . $definition . $root . '}</style>',
        'keep' => true,
    ),
    'undefined property' => array(
        'css' => '<style>@media (max-width:1000px){' . $paint . $root . '}</style>',
        'keep' => false,
    ),
    'no paint' => array(
        'css' => '<style>' . $root . '</style>',
        'keep' => false,
    ),
);

$failed = false;
foreach ( $cases as $name => $case ) {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>t</title>' . $case['css'] . '</head><body><div class="wrap"><section id="band1"><div></div><div class="edge bottom"><div class="shape"></div></div></section></div></body></html>';
    $compiled = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
    $markup = (string) ($compiled['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '');
    foreach ( array('shape', 'edge') as $class ) {
        $present = 1 === preg_match('/class="[^"]*\b' . $class . '\b[^"]*"/', $markup);
        if ( $present !== $case['keep'] ) {
            fwrite(STDERR, 'FAIL: ' . $name . ': canonical block markup must ' . ($case['keep'] ? 'keep' : 'drop') . ' .' . $class . ".\n");
            $failed = true;
        }
    }
}
if ( $failed ) {
    exit(1);
}
fwrite(STDOUT, "Empty layer conditional custom property paint contracts passed.\n");
