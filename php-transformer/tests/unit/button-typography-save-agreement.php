<?php
declare(strict_types=1);

/**
 * core/button save() inlines every typography key the block supports on its
 * link element. A key the attributes carry but the stored markup omits makes
 * the editor report "This block contains unexpected or invalid content"
 * (issue #6382: imported product-page buttons carried
 * `style.typography.fontStyle: "normal"` without `font-style:normal`).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
    }
};

$linkStyle = static function (string $html): string {
    return preg_match('/<(?:a|button)\b[^>]*class="wp-block-button__link[^"]*"[^>]*\bstyle="([^"]*)"/', $html, $m) ? $m[1] : '';
};

foreach ( array( 'a', 'button' ) as $tagName ) {
    $block = ( new BlockFactory() )->create('core/button', array(
        'tagName' => $tagName,
        'text'    => 'Add to cart',
        'url'     => 'a' === $tagName ? '/fitment/' : null,
        'style'   => array(
            'typography' => array(
                'fontSize'       => '14px',
                'fontStyle'      => 'normal',
                'fontWeight'     => '600',
                'letterSpacing'  => '2.4px',
                'lineHeight'     => '18px',
                'textDecoration' => 'none',
                'textTransform'  => 'uppercase',
            ),
        ),
    ));
    $html = (string) $block['innerHTML'];
    $style = $linkStyle($html);
    foreach ( array( 'font-size:14px', 'font-style:normal', 'font-weight:600', 'letter-spacing:2.4px', 'line-height:18px', 'text-decoration:none', 'text-transform:uppercase' ) as $declaration ) {
        $assert(
            in_array($declaration, explode(';', $style), true),
            "<{$tagName}> core/button link inlines {$declaration} as save() does",
            $html
        );
    }
}

if ( $failures > 0 ) {
    exit(1);
}

echo "button typography save agreement passed\n";
