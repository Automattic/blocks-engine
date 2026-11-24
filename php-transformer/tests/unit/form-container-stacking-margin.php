<?php
declare(strict_types=1);

/**
 * A source form box that spaces its controls from the container
 * (`.space-y-4>:not(:last-child)`) and centres itself (`mx-auto`) keeps
 * both once handed to a form provider (#6918).
 *
 * - The provider wraps each data-entry control in a field box that the
 *   projected container rule already spaces, so the container-placed margin
 *   is not the control's own: recording it doubles every gap. A margin the
 *   author puts on the control itself still is.
 * - The source form survives as a Group inside the provider's flex form
 *   block, whose Core flex-item reset would erase its layered `mx-auto`.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$check = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ( ! $ok ) $failures[] = $message . ('' !== $detail ? ' - ' . $detail : '');
};

$form = '<form class="mx-auto max-w-2xl space-y-4 px-5">'
    . '<input required class="field" placeholder="Name">'
    . '<input required type="email" class="field own" placeholder="Email">'
    . '<textarea required class="field" placeholder="Message"></textarea>'
    . '<button class="btn" type="submit">Send</button></form>';
$controlStyles = static function (string $css) use ($form): array {
    $result = (new HtmlTransformer())->transform($form, array( 'static_css' => $css ))->toArray();
    $styles = array();
    foreach ( $result['fallbacks'][0]['presentation_graph']['controls'] ?? array() as $row ) {
        $styles[$row['index']] = $row['control']['styles'] ?? array();
    }
    return $styles;
};
$margins = static fn (array $styles): array => array_filter($styles, static fn (string $key): bool => str_starts_with($key, 'margin'), ARRAY_FILTER_USE_KEY);

foreach ( array(
    'v4 :not(:last-child)' => '@layer utilities { .space-y-4>:not(:last-child){margin-block-end:1rem} .field{margin:0;padding:.75rem} .own{margin-bottom:4px} }',
) as $name => $css ) {
    $styles = $controlStyles($css);
    $check(array( 'margin' => '0' ) === $margins($styles[0] ?? array()), $name . ': a container-placed margin is not recorded on a wrapped control', json_encode($margins($styles[0] ?? array())));
    $check(array( 'margin' => '0' ) === $margins($styles[2] ?? array()), $name . ': nor on a wrapped textarea', json_encode($margins($styles[2] ?? array())));
    $check('4px' === ($styles[1]['margin_bottom'] ?? null), $name . ': a margin authored on the control itself stays', json_encode($margins($styles[1] ?? array())));
}

$compiledStylesheet = static function (string $css) use ($form): string {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<html><head><link rel="stylesheet" href="s.css"></head><body><main>' . $form . '</main></body></html>',
            's.css' => $css,
        ),
    ))->toArray();
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_filter($result['assets'] ?? array(), 'is_array')
    ));
};
$repair = 'body :where(.wp-block-jetpack-contact-form.is-layout-flex)>div.wp-block-group{margin:revert-layer}';
$check(
    str_contains($compiledStylesheet('@layer utilities { .mx-auto{margin-inline:auto} .max-w-2xl{max-width:42rem} .space-y-4>:not(:last-child){margin-block-end:1rem} }'), $repair),
    'the source form box takes its layered margin back from the provider flex-item reset'
);
// `revert-layer` would also roll back an unlayered author margin.
$check(
    ! str_contains($compiledStylesheet('.mx-auto{margin-inline:auto} .max-w-2xl{max-width:42rem}'), $repair),
    'an unlayered form box margin is not reverted'
);
$check(
    ! str_contains($compiledStylesheet('@layer utilities { .mx-auto{margin-inline:auto} } .space-y-4{margin-top:2rem}'), $repair),
    'an unlayered margin on the form box vetoes the layered repair'
);
$check(
    ! str_contains($compiledStylesheet('@layer utilities { .max-w-2xl{max-width:42rem} }'), $repair),
    'a form box without an authored margin needs no repair'
);

if ( array() !== $failures ) {
    fwrite(STDERR, implode(PHP_EOL, array_map(static fn (string $f): string => 'FAIL: ' . $f, $failures)) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Form container stacking margin tests passed\n");
