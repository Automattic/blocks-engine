<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$css = '@font-face{font-family:ContractIcons;src:url(icons.woff2)}'
    . '.fas{font-family:ContractIcons;display:inline-block;font-weight:900}'
    . '.fa-x:before{content:"\\f023"}.fa-5x{font-size:5em;color:#123456}.tile{text-align:center}';
$icon = '<i class="fas fa-x fa-5x"></i>';
$failures = array();
foreach (array(
    'standalone' => '<div class="tile">' . $icon . '<p>VPN</p></div>',
    'inline' => '<p>Open ' . $icon . ' VPN</p>',
    'non-vendor' => '<div class="tile"><i class="custom"></i><p>VPN</p></div>',
) as $case => $body) {
    $source = '<html><head><style>' . $css . '.custom{font-family:ContractIcons;font-size:5em;color:#123456;display:inline-block}.custom:before{content:"\\f023"}' . '</style></head><body>' . $body . '</body></html>';
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $markup = $result['serialized_blocks'];
    foreach (array(
        'paragraph' => str_contains($markup, '<!-- wp:paragraph'),
        'glyph mark' => 1 === preg_match('/<mark[^>]*class="(?:fas fa-x fa-5x|custom) has-inline-color"/', $markup),
        'presentation role' => str_contains($markup, 'role="none"'),
        'font' => str_contains($markup, 'font-family:ContractIcons'),
        'size' => str_contains($markup, 'font-size:5em'),
        'color' => str_contains($markup, '#123456'),
        'alignment' => 'inline' === $case || str_contains($markup, 'has-text-align-center'),
        'label' => str_contains($markup, 'VPN'),
        'inline text' => 'inline' !== $case || str_contains($markup, 'Open '),
    ) as $contract => $ok) {
        if (!$ok) $failures[] = "$case: $contract\n$markup";
    }
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Standalone and inline font glyph contracts passed.\n";
