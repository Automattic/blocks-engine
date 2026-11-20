<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Bare image and universal subjects keep their dimensions on the leaf, even
// at responsive cascade positions. Class subjects instead size the figure.
foreach ( array('.photo img', '.photo *', '.photo img,.photo *', '.photo .picture') as $selector ) {
    foreach ( array('width:100%;height:360px', 'height:420px', 'width:50%;height:50%', 'position:absolute;width:80px;height:90px', 'width:auto;height:auto') as $dimensions ) {
        $result = (new HtmlTransformer())->transform(
            '<style>' . $selector . '{' . $dimensions . ';object-fit:cover}@media (min-width:900px){' . $selector . '{height:420px}}</style>'
            . '<section class="panel"><div class="photo"><img class="picture" src="room.jpg" width="1200" height="788" alt="Room"></div></section>',
            array()
        )->toArray();
        $bridges = array();
        foreach ( $result['assets'] ?? array() as $asset ) {
            if ( 'css' !== ($asset['kind'] ?? '') ) continue;
            (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $prelude, string $body) use (&$bridges): string {
                if ( str_contains($prelude, '.photo') && str_contains($prelude, '.wp-block-image') && str_contains($prelude, '> img') && str_contains($body, 'object-fit:inherit') ) {
                    $bridges[] = $body;
                }
                return $prelude . '{' . $body . '}';
            });
        }
        if ( array() === $bridges ) {
            throw new RuntimeException('Missing image bridges for ' . $selector);
        }
        foreach ( $bridges as $bridge ) {
            $hasDimensions = 1 === preg_match('/(?:^|;)(?:width|height):/', $bridge);
            if ( $hasDimensions !== ('.photo .picture' === $selector || (str_contains($selector, '.photo img') && str_starts_with($dimensions, 'position:absolute'))) ) {
                throw new RuntimeException('Incorrect dimension ownership for ' . $selector . ': ' . $bridge);
            }
        }
        if ( '.photo .picture' === $selector && 'width:50%;height:50%' === $dimensions
            && ! str_contains(implode(';', $bridges), 'width:100%;height:100%') ) {
            throw new RuntimeException('Moved percentage dimensions must fill the figure');
        }
    }
}
echo "Image bridge authored dimensions passed\n";
