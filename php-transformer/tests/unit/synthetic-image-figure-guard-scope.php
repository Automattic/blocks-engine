<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A reset whose margin moves to the image leaf keeps the synthetic-figure
// guard only on arms that could select that figure, so typed arms such as
// `body` project identically on pages with and without images.
$result = (new HtmlTransformer())->transform('<style>body,section,.photo,img{margin:4px}</style><section><img class="photo" src="a.png" width="10" height="10" alt=""></section>')->toArray();
if (!str_contains($result['serialized_blocks'], 'blocks-engine-synthetic-image-figure')) throw new RuntimeException('The image is wrapped in a synthetic figure.');
$guarded = $unguarded = array();
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use (&$guarded, &$unguarded): void {
        if ('margin:4px' !== $body) return;
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $arm) {
            $arm = trim($arm);
            if (str_contains($arm, 'blocks-engine-synthetic-image-figure')) $guarded[] = $arm; else $unguarded[] = $arm;
        }
    });
}
$typed = static fn (array $arms, string $type): bool => array() !== preg_grep('/^' . $type . '\b/', $arms);
if (!$typed($unguarded, 'body') || $typed($guarded, 'body') || !$typed($unguarded, 'section') || $typed($guarded, 'section')) {
    throw new RuntimeException('Typed body/section arms carry no synthetic-figure guard: ' . json_encode(compact('guarded', 'unguarded')));
}
if (array() === preg_grep('/^(?!body|section)/', $guarded)) throw new RuntimeException('Arms that can select the figure keep the guard: ' . json_encode($guarded));
echo "Synthetic image figure guard scope passed\n";
