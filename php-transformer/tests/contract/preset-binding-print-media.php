<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A print-only !important reset never renders on screen, so it does not stop
// screen colours from binding to the palette preset they equal.
$html = '<html><head><link rel="stylesheet" href="s.css"%s></head><body><main><h1>Title</h1><p style="color:black">One.</p><p style="color:black">Two.</p><p><a href="/x">Link</a></p></main></body></html>';
$reset = '*,*::before,*::after{background:transparent!important;color:#000!important}';
foreach (array(
    'no print rules' => array('', ''),
    '@media print reset' => array('@media print{' . $reset . '}', ''),
    'print stylesheet' => array($reset, ' media="print"'),
) as $label => [$extra, $media]) {
    $css = 'body{background:#fff;color:#333;font-family:Georgia}h1{color:#000}a{color:#0077b5}' . $extra;
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        array('path' => 'index.html', 'content' => sprintf($html, $media)),
        array('path' => 's.css', 'content' => $css),
    )))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $markup = implode('', array_map(static fn(array $page): string => (string) ($page['canonical_block_markup'] ?? ''), $plan['pages']));
    if (2 !== substr_count($markup, '"textColor":"contrast"')) throw new RuntimeException($label . ': both black paragraphs bind to the contrast preset.');
    if (str_contains($markup, '"text":"black"')) throw new RuntimeException($label . ': no paragraph keeps the literal black.');
}
fwrite(STDOUT, "preset-binding-print-media: ok\n");
