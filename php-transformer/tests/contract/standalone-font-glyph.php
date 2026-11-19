<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$css = '@font-face{font-family:ContractIcons;src:url(icons.woff2)}'
    . '.fas{font-family:ContractIcons;display:inline-block;font-weight:900}'
    . '.fa-x:before{content:"\\f023"}.fa-5x{font-size:5em;color:#123456}.tile{text-align:center}';
$css .= '.custom{font-family:ContractIcons;font-size:5em;color:#123456;display:inline-block}.custom:before{content:"\\f023"}';
// The family is declared only on the pseudo-element, through attribute-prefix
// selectors with `!important`; the element paints an inline-block chip.
$chipCss = 'i[class^=mark-]:before,i[class*=" mark-"]:before{font-family:ContractIcons!important;line-height:1}'
    . '.mark-lock:before{content:"\\f023"}.chip i{display:inline-block;font-size:5em;color:#123456;padding:14px;background-color:#f3f6fb;border-radius:5px;margin:-30px 0 20px}';
$icon = '<i class="fas fa-x fa-5x"></i>';
$failures = array();
foreach (array(
    'standalone' => array('<div class="tile">' . $icon . '<p>VPN</p></div>', '', array()),
    'inline' => array('<p>Open ' . $icon . ' VPN</p>', '', array()),
    'non-vendor' => array('<div class="tile"><i class="custom"></i><p>VPN</p></div>', '', array()),
    'pseudo-declared font chip' => array('<div class="tile"><div class="chip"><i class="mark-lock"></i></div><p>VPN</p></div>', $chipCss, array(
        'no empty visual group' => static fn (string $m): bool => !str_contains($m, 'blocks-engine-empty-visual-group'),
        'chip box' => static fn (string $m): bool => 1 === preg_match('/<mark class="mark-lock[^"]*" style="([^"]*)"/', $m, $style)
            && array() === array_filter(array('display:inline-block', 'padding:14px', 'background-color:#f3f6fb', 'border-radius:5px', 'margin:-30px 0 20px'), static fn (string $d): bool => !str_contains($style[1], $d)),
        'centered glyph' => static fn (string $m): bool => 1 === preg_match('/<p class="has-text-align-center[^"]*"><mark class="mark-lock/', $m),
    )),
    'single chip painter and pseudo typography' => array('<div class="tile"><div class="chip"><i class="mark-lock"></i></div><p>VPN</p></div>', $chipCss . '.mark-lock:before{line-height:2}i.mark-lock:before{line-height:1!important;font-style:normal}', array(
        'one box painter' => static function (string $m): bool {
            $document = new DOMDocument();
            @$document->loadHTML('<html><body>' . $m . '</body></html>');
            $painters = 0;
            foreach ((new DOMXPath($document))->query('//body//*') as $node) {
                $authorBox = CssSelectorMatcher::matches($node, CssSelectorMatcher::parse('.chip i'))['matches'];
                $inlineBox = str_contains($node->getAttribute('style'), 'padding') || str_contains($node->getAttribute('style'), 'background-color:#f3f6fb');
                if ($authorBox || $inlineBox) ++$painters;
            }
            return 1 === $painters;
        },
        'winning pseudo typography' => static fn (string $m): bool => 1 === preg_match('/<mark class="mark-lock[^"]*" style="[^"]*line-height:1(?:;|")/', $m)
            && str_contains($m, 'font-style:normal'),
    )),
) as $case => [$body, $caseCss, $extra]) {
    $source = '<html><head><style>' . $css . $caseCss . '</style></head><body>' . $body . '</body></html>';
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $markup = $result['serialized_blocks'];
    foreach (array_merge(array(
        'paragraph' => str_contains($markup, '<!-- wp:paragraph'),
        'glyph mark' => 1 === preg_match('/<mark[^>]*class="(?:fas fa-x fa-5x|custom|mark-lock) has-inline-color"/', $markup),
        'presentation role' => str_contains($markup, 'role="none"'),
        'font' => str_contains($markup, 'font-family:ContractIcons'),
        'size' => str_contains($markup, 'font-size:5em'),
        'color' => str_contains($markup, '#123456'),
        'alignment' => 'inline' === $case || str_contains($markup, 'has-text-align-center'),
        'label' => str_contains($markup, 'VPN'),
        'inline text' => 'inline' !== $case || str_contains($markup, 'Open '),
    ), array_map(static fn (callable $check): bool => $check($markup), $extra)) as $contract => $ok) {
        if (!$ok) $failures[] = "$case: $contract\n$markup";
    }
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Standalone and inline font glyph contracts passed.\n";
