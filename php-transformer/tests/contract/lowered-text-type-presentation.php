<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$cases = array(
    'address type' => array('address', '', 'address'),
    'address class' => array('address', ' class="addr"', '.addr'),
    'address id' => array('address', ' id="contact"', '#contact'),
    'marquee type' => array('marquee', '', 'marquee'),
    'blink type' => array('blink', '', 'blink'),
);
foreach ($cases as $name => [$tag, $attributes, $selector]) {
    $result = (new HtmlTransformer())->transform(
        '<style>' . $selector . '{line-height:1.42857;font-style:normal;margin-bottom:20px}</style>'
        . '<' . $tag . $attributes . '>Rua A, 524<br>Campus B</' . $tag . '>'
    )->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    if (!str_contains($markup, '<!-- wp:paragraph')) {
        throw new RuntimeException($name . ': expected paragraph lowering');
    }
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $paragraph = $document->getElementsByTagName('p')->item(0);
    if (!$paragraph instanceof DOMElement) throw new RuntimeException($name . ': missing paragraph');
    if ('.addr' === $selector && !$paragraph->hasAttribute('class')) throw new RuntimeException($name . ': lost class');
    if ('.addr' === $selector && !in_array('addr', explode(' ', $paragraph->getAttribute('class')), true)) throw new RuntimeException($name . ': lost addr class');
    if ('#contact' === $selector && 'contact' !== $paragraph->getAttribute('id')) throw new RuntimeException($name . ': lost id');

    $found = array();
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->transform((string) $asset['content'], static function (string $prelude, string $body) use ($paragraph, &$found): string {
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $ruleSelector) {
                if (!CssSelectorMatcher::matches($paragraph, CssSelectorMatcher::parse($ruleSelector), true)['matches']) continue;
                foreach (array('line-height:1.42857', 'font-style:normal', 'margin-bottom:20px') as $declaration) {
                    if (str_contains(preg_replace('/\s+/', '', $body), $declaration)) $found[$declaration] = true;
                }
            }
            return $prelude;
        });
    }
    if (3 !== count($found)) throw new RuntimeException($name . ': type/class/id presentation must match the lowered paragraph');
}
echo "Lowered text type presentation contract passed\n";
