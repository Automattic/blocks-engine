<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A rule still targeting img must not inherit its crop from the inserted
// figure. Mixed selector lists must inherit only on their moved subjects.
foreach (array('.frame img', '.frame img,.frame .picture') as $selector) {
    $result = (new HtmlTransformer())->transform(
        '<style>' . $selector . '{width:100%;height:100%;object-fit:cover;object-position:center 25%}'
        . '@media(max-width:600px){.frame img{object-position:20% 70%}}</style>'
        . '<div class="frame"><img class="picture" src="portrait.jpg" alt="Portrait"></div>'
    )->toArray();
    $bridges = 0;
    foreach ($result['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $prelude, string $body) use (&$bridges): string {
            if (str_contains($prelude, '.wp-block-image') && str_contains($prelude, '> img') && str_contains($body, 'display:block')) {
                ++$bridges;
                if (str_contains($body, 'object-fit:inherit') || str_contains($body, 'object-position:inherit')) {
                    throw new RuntimeException('Leaf crop overridden by synthetic figure: ' . $prelude . '{' . $body . '}');
                }
            }
            return $prelude . '{' . $body . '}';
        });
    }
    if (0 === $bridges) throw new RuntimeException('Expected native image projection bridge');
}
echo "Image bridge leaf crop passed\n";
