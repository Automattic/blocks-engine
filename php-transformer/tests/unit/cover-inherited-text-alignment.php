<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$fixture = static function (string $rules = '', string $headingAttributes = ''): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Centered hero</title>'
        . '<style>.hero{padding:96px 0;text-align:center;background:#eee url(\'bg.png\') center/cover no-repeat}'
        . '.container{max-width:960px;margin:0 auto;padding:0 24px}' . $rules . '</style></head><body><main>'
        . '<section class="hero"><div class="container"><h1' . $headingAttributes . '>Centered title</h1>'
        . '<p>Centered lead paragraph.</p></div></section></main></body></html>';
};

foreach ( array(
    'inherited center' => array( '', '', 'center', 'center' ),
    'nearest ancestor wins' => array( '.container{text-align:right}', '', 'right', 'right' ),
    'own cascade wins' => array( 'h1{text-align:right}', '', 'right', 'center' ),
    'editor default needs no lift' => array( '.container{text-align:left}', '', '', '' ),
    'inline override wins' => array( '', ' style="text-align:right"', 'right', 'center' ),
) as $case => [$rules, $headingAttributes, $headingAlignment, $paragraphAlignment] ) {
    $out = ( new HtmlTransformer() )->transform($fixture($rules, $headingAttributes))->toArray();
    $markup = (string) ($out['serialized_blocks'] ?? '');
    $check(str_contains($markup, '<!-- wp:cover'), $case . ': background becomes a cover');
    foreach ( array( 'heading' => $headingAlignment, 'paragraph' => $paragraphAlignment ) as $block => $expected ) {
        $matched = preg_match('/<!-- wp:' . $block . '(?: (\{.*?\}))? -->\s*<(?:h1|p)\b([^>]*)>/', $markup, $match);
        $check(1 === $matched, $case . ': ' . $block . ' is present');
        $attrs = json_decode($match[1] ?? '{}', true) ?? array();
        $actual = $attrs['style']['typography']['textAlign'] ?? '';
        $check($expected === $actual, $case . ': ' . $block . ' typography.textAlign matches the cascade');
        $check(! isset($attrs['align']), $case . ': ' . $block . ' does not use block alignment');
        if ( '' !== $expected ) {
            $check(str_contains($match[2] ?? '', 'has-text-align-' . $expected), $case . ': ' . $block . ' serializes text alignment');
        }
    }
}

exit($failures > 0 ? 1 : 0);
