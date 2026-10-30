<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

foreach ( array(
    'media-only none' => array('none', '@media (min-width:576px){.d-sm-none{display:none!important}}', true),
    'unconditional agreement' => array('none', '.d-sm-none{display:none!important}', false),
    'media-only flex' => array('flex', '@media (min-width:576px){.d-sm-none{display:flex!important}}', true),
    'no stylesheet' => array('none', '', true),
) as $label => list($display, $css, $needsCarrier) ) {
    $html = ( '' !== $css ? '<style>' . $css . '</style>' : '' )
        . '<header><div id="banner" class="d-sm-none" style="display: ' . $display . ';"><p>App</p></div><p>Site</p></header>';
    $result = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $result['serialized_blocks']);
    $banner = ( new DOMXPath($dom) )->query('//*[@id="banner"]')->item(0);
    $assert($banner instanceof DOMElement, $label . ': banner survives compilation');
    $classes = $banner instanceof DOMElement ? $banner->getAttribute('class') : '';
    preg_match('/\bbe-inline-geometry-[\w-]+\b/', $classes, $carrier);
    $assert($needsCarrier === isset($carrier[0]), $label . ': inline display carrier presence');

    if ( $needsCarrier ) {
        $emittedCss = implode("\n", array_column($result['assets'] ?? array(), 'content'));
        $assert(
            isset($carrier[0]) && 1 === preg_match(
                '/(?:^|[}\s])(?::root )?\.' . preg_quote($carrier[0], '/') . '\{[^}]*\bdisplay:\s*' . $display . '\s*(?:!important)?[;}]/',
                $emittedCss
            ),
            $label . ': banner carrier supplies unconditional display:' . $display
        );
    }
}

if ( 0 < $failures ) {
    fwrite(STDERR, "artifact inline display FAILED: {$failures} failures\n");
    exit(1);
}
echo "artifact inline display passed\n";
