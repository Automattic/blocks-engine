<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
// Conditions on either the ancestor or the subject use the same captured-state contract.
foreach (array('ancestor', 'subject') as $owner) {
    foreach (array(
        'positive' => array('[data-mode=wide][data-align=center]', true),
        'positive false' => array('[data-mode=narrow]', false),
        'negated true' => array('[data-mode=wide][data-align=center]:not([data-design=stacked])', true),
        'negated false' => array('[data-mode=wide][data-align=center]:not([data-design=slides])', false),
        'negated presence true' => array('[data-mode=wide]:not([data-missing])', true),
        'negated presence false' => array('[data-mode=wide]:not([data-design])', false),

        'multiple negations' => array('[data-mode=wide]:not([data-design=stacked]):not([data-missing])', true),
    ) as $name => [$condition, $expected]) {
        $base = 'ancestor' === $owner ? '.layout>[data-mode=wide] .content' : '.content[data-mode=wide]';
        $selector = 'ancestor' === $owner ? '.layout>' . $condition . ' .content' : '.content' . $condition;
        $attributes = ' data-mode="wide" data-align="center" data-design="slides"';
        $html = '<style>' . $base . '{display:flex;align-items:start}' . $selector . '{align-items:center}</style>'
            . '<main><div class="layout"><div' . ('ancestor' === $owner ? $attributes : '') . '>'
            . '<div class="content"' . ('subject' === $owner ? $attributes : '') . '><p>Product details</p></div></div></div></main>';
        $result = (new HtmlTransformer())->transform($html, array())->toArray();
        $css = implode("\n", array_map(
            static fn (array $asset): string => (string) ($asset['content'] ?? ''),
            array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? ''))
        ));
        preg_match_all('/:where\(\.(blocks-engine-attribute-[a-f0-9]{12}-\d+)\)[^{]*\{[^}]*align-items:center/', $css, $projected);
        if ($expected !== (array() !== $projected[1])) {
            $failures[] = "$owner / $name: unexpected projected center rule";
        }
        foreach ($projected[1] as $marker) {
            if (!str_contains($result['serialized_blocks'], $marker)) {
                $failures[] = "$owner / $name: projected marker missing from blocks";
            }
        }
        if (!str_contains($css, 'align-items:start')) {
            $failures[] = "$owner / $name: competing base rule lost";
        }
    }
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Attribute projection negated conditions passed\n";
