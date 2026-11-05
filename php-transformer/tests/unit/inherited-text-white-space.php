<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/inherited-text-white-space.html');
foreach (array(
    'later longhand' => array('.text{color:#000;white-space:normal}\n.single{text-wrap:nowrap}', 'white-space:normal!important;text-wrap:nowrap!important;'),
    'later shorthand' => array('.single{text-wrap:nowrap}\n.text{color:#000;white-space:normal}', 'white-space:normal!important;'),
    'repeated shorthand' => array('.text{white-space:pre}\n.single{text-wrap:nowrap}\n.text{color:#000;white-space:normal}', 'white-space:normal!important;'),
    'stronger longhand' => array('.text.single{text-wrap:nowrap}\n.text{color:#000;white-space:normal}', 'white-space:normal!important;text-wrap:nowrap!important;'),
    'mode and collapse' => array('.text{color:#000;white-space:normal}\n.single{text-wrap-mode:nowrap;white-space-collapse:preserve}', 'white-space:normal!important;text-wrap-mode:nowrap!important;white-space-collapse:preserve!important;'),
    'important longhand' => array('.single{text-wrap:nowrap!important}\n.text{color:#000;white-space:normal}', 'white-space:normal!important;text-wrap:nowrap!important;'),
    'later shorthand resets mode' => array('.single{text-wrap-mode:nowrap;white-space-collapse:preserve}\n.text{color:#000;white-space:normal}', 'white-space:normal!important;'),
    'text-wrap resets mode' => array('.text{color:#000;white-space:normal;text-wrap-mode:wrap}\n.single{text-wrap:nowrap}', 'white-space:normal!important;text-wrap:nowrap!important;'),
    'wrap style' => array('.text{color:#000;white-space:normal}\n.single{text-wrap-style:balance}', 'white-space:normal!important;text-wrap-style:balance!important;'),
) as $label => [$rules, $expected]) {
    $source = str_replace(".text{color:#000;white-space:normal}\n.single{text-wrap:nowrap}", str_replace('\\n', "\n", $rules), $fixture);
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $markup = $result['serialized_blocks'];
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
    // Inspect only carriers attached to the rendered paragraph or its ancestors,
    // not an unused rule or the ordinary source stylesheet.
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $paragraph = $document->getElementsByTagName('p')->item(0);
    $carriers = array();
    $depth = 0;
    for ($node = $paragraph; $node instanceof DOMElement; $node = $node->parentNode) {
        if ('div' === $node->tagName) ++$depth;
        foreach (explode(' ', $node->getAttribute('class')) as $class) {
            if (str_starts_with($class, 'be-inline-geometry-') && preg_match('/\.' . preg_quote($class, '/') . '\{([^}]+)\}/', $css, $match) && str_contains($match[1], 'white-space:')) {
                $carriers[] = implode(';', array_filter(explode(';', $match[1]), static fn (string $declaration): bool => 1 === preg_match('/^(?:white-space|text-wrap|text-wrap-mode|text-wrap-style|white-space-collapse):/', $declaration))) . ';';
            }
        }
    }
    if (array($expected) !== $carriers) throw new RuntimeException($label . ': expected ' . $expected . ', got ' . json_encode($carriers) . "\n" . $css);
    if ($depth > 2) throw new RuntimeException($label . ': empty-style/text wrappers must still coalesce: ' . $markup);
    if ('pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')) throw new RuntimeException($label . ': invalid blocks');
}
echo "inherited-text-white-space: ok\n";
