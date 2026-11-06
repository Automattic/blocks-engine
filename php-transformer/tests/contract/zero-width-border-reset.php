<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

// Verbatim reduced fixture from issue #4455.
$fixture = <<<'HTML'
<!doctype html><html><head><style>*,::before,::after{border-width:0;border-style:solid;border-color:#e5e7eb;box-sizing:border-box}.css-fz71is { display: flex; flex-direction: column; height: 100%; width: 100%; align-items: var(--flex-align); } .css-ud2snp { width: calc(717.3 * var(--font-size) / 16); max-width: calc(100% * var(--media-scale)); position: relative; overflow: hidden; border-radius: var(--box-border-radius); mask: none !important; } .css-p4s2y9 { inset: 0px; width: 100%; height: 100%; position: absolute; z-index: 1; cursor: zoom-in; opacity: 0; } .css-11lrhya { max-height: var(--media-maxH); max-width: var(--media-maxW); overflow: hidden; width: 100%; display: block; } .css-zec7a { object-fit: contain; width: 100%; }</style></head><body><div style="width:600px"><div class="react-renderer node-image block block-image" style=""><div style="white-space:normal" data-node-view-wrapper=""><div class="gamma-moveable-wrapper css-fz71is"><div class="css-ud2snp"><div class="css-p4s2y9"></div><div class="image cropped-image css-11lrhya"><img alt="" src="/media/57_tsw_sa.avif" class="chakra-image css-zec7a"></div></div></div></div></div></div></body></html>
HTML;
$reset = '*,::before,::after{border-width:0;border-style:solid;border-color:#e5e7eb;box-sizing:border-box}';
$compile = static function (string $html): string {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
    return (string) ($result['serialized_blocks'] ?? '');
};
$structure = static function (string $markup): array {
    preg_match_all('/<!-- wp:([^\s]+)(?:\s|-->)/', $markup, $matches);
    return $matches[1];
};

$cases = array(
    'image wrapper reset' => array('html' => $fixture, 'baseline' => str_replace($reset, '', $fixture)),
    // Empty inline styles are removed before lowering. An editor node-view
    // wrapper must fold identically without that syntactic transparency hint.
    'image wrapper without inline style' => array(
        'html' => str_replace(' style=""', '', $fixture),
        'baseline' => str_replace(array($reset, ' style=""'), '', $fixture),
    ),
    'image wrapper with empty declarations' => array(
        'html' => str_replace('style=""', 'style=" ; "', $fixture),
        'baseline' => str_replace(array($reset, 'style=""'), array('', 'style=" ; "'), $fixture),
    ),
    'bordered card, reset first' => array('css' => $reset . '.card{border-width:2px;padding:8px}'),
    'bordered card, reset last' => array('css' => '.card{border-width:2px;padding:8px}' . $reset),
    'bordered card, layered reset' => array('css' => '@layer reset{' . $reset . '}.card{border-width:2px;padding:8px}'),
    'bordered card, one side' => array('css' => $reset . '.card{border-left-width:2px;padding:8px}'),
);
foreach ($cases as $label => $case) {
    if (isset($case['html'])) {
        $markup = $compile($case['html']);
        $baseline = $structure($compile($case['baseline']));
        $assert($structure($markup) === $baseline, $label . ': reset must not change block structure');
        $counts = array_count_values($baseline);
        // The editor wrappers fold into one layout shell around the image.
        $assert(($counts['image'] ?? 0) === 1 && ($counts['custom/layout-shell'] ?? 0) === 1 && array_sum($counts) === 2, $label . ': layout-shell and image');
        $assert(! str_contains($markup, '"border":{"style":"solid"}'), $label . ': no inert solid border support');
        continue;
    }
    $markup = $compile('<!doctype html><html><head><style>' . $case['css'] . '</style></head><body><div class="card"><p>Bordered card</p></div></body></html>');
    preg_match_all('/<!-- wp:[^\s]+ (\{.*?\}) -->/', $markup, $comments);
    $borders = array();
    foreach ($comments[1] as $json) {
        $attrs = json_decode($json, true);
        if (isset($attrs['style']['border'])) {
            $borders[] = $attrs['style']['border'];
        }
    }
    $assert(count($borders) === 1, $label . ': one block owns border support');
    $border = $borders[0];
    $assert(($border['width'] ?? $border['left']['width'] ?? '') === '2px', $label . ': positive width survives cascade');
    $assert(($border['style'] ?? '') === 'solid', $label . ': reset style survives');
    $assert(($border['color'] ?? '') === '#e5e7eb', $label . ': reset color survives');
}

fwrite(STDOUT, "Zero-width border reset contract passed\n");
