<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$find = static function (array $blocks, string $name) use (&$find): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $found = $find($block['innerBlocks'] ?? array(), $name);
        if ( null !== $found ) {
            return $found;
        }
    }
    return null;
};
$words = '<span>“We suffer more often in imagination than in reality.”</span>';
$author = '<strong>— Seneca</strong>';
foreach ( array(
    'paragraphs' => '<p>' . $words . '</p><p>' . $author . '</p>',
    'inline' => $words . '<br>' . $author,
    // Flow content inside a phrasing wrapper must still reach quote lowering.
    'nested-rich-text' => '<span><div><span><em>' . $words . '</em>' . $author . '</span></div></span>',
    // Not a paragraphs/inline-only quote: retain the normal child conversion
    // and typography on its carrier rather than flattening structural content.
    'structural' => '<div><h2>Reflection</h2><p>' . $words . '</p><p>' . $author . '</p></div>',
) as $label => $content ) {
    $result = (new HtmlTransformer())->transform(
        '<style>.q{font-style:italic;font-size:24px;line-height:1.4;max-width:722px}</style>'
        . '<article><p>Before the quotation.</p><div class="prose"><blockquote class="q">'
        . $content . '</blockquote></div><p>After the quotation.</p></article>'
    )->toArray();
    $quote = $find($result['blocks'], 'core/quote');
    $typography = $quote['attrs']['style']['typography'] ?? array();
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $markup = $result['serialized_blocks'];
    if ( null === $quote
        || '24px' !== ($typography['fontSize'] ?? '')
        || '1.4' !== ($typography['lineHeight'] ?? '')
        || 'italic' !== ($typography['fontStyle'] ?? '')
        || ! str_contains($css, 'max-width:722px')
        || ! str_contains($markup, $author)
        || null === $find($quote['innerBlocks'], 'core/paragraph')
        || ('paragraphs' === $label && array('core/paragraph', 'core/paragraph') !== array_column($quote['innerBlocks'], 'blockName'))
        || ('structural' === $label && null === $find($quote['innerBlocks'], 'core/heading'))
        || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
    ) {
        throw new RuntimeException($label . ': editable quote, bold attribution, typography and box must survive: ' . $markup);
    }
}
echo "Article blockquote typography tests passed\n";
