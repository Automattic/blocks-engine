<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced fixture from #4622: an auto-width `aspect-ratio` card whose inline
// size is still definite (a growing row-flex item, or a block-flow box)
// derives a definite block size, so `.col{height:100%}` under it must not be
// rewritten to `height:auto` (which collapsed the image frames to 1px).
// Shrink-to-fit cards and cards with no definite size stay `height:auto`.
$base = 'body{margin:0}'
    . '.fill{width:100%;height:100%}'
    . '.col{display:flex;flex-direction:column;align-items:flex-start;position:relative}'
    . '.grow{position:relative;flex:1 0 0px;min-height:1px;width:100%}'
    . '.frame{display:block;position:relative;overflow:clip;width:100%;height:100%}'
    . '.frame img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}';
$media = static fn (string $row, string $card): string => '@media (min-width:403px){'
    . '.row{' . $row . '}'
    . '.card{' . $card . '}'
    . '.col{min-width:inherit;min-height:inherit;width:100%;height:100%}'
    . '}';
$issueRow = 'position:relative;display:flex;align-items:flex-start;flex-wrap:wrap';
$issueCard = 'position:relative;flex:1 0 0px;min-width:400px;min-height:400px;aspect-ratio:640/628';

$cases = array(
    'growing row-flex card (issue fixture)' => array( $media($issueRow, $issueCard), '100%' ),
    'block-flow card' => array( $media('position:relative', 'position:relative;aspect-ratio:640/628'), '100%' ),
    'control: card without a definite size' => array( $media($issueRow, 'position:relative;flex:1 0 0px;min-width:400px'), 'auto' ),
    'control: non-growing row-flex card' => array( $media($issueRow, 'position:relative;flex:0 0 auto;aspect-ratio:640/628'), 'auto' ),
    'control: floated card' => array( $media('position:relative', 'position:relative;float:left;aspect-ratio:640/628'), 'auto' ),
    'control: inline-block card' => array( $media('position:relative', 'position:relative;display:inline-block;aspect-ratio:640/628'), 'auto' ),
);

$failures = 0;
$card = '<div class="card"><div class="fill"><div class="col"><div class="grow"><div class="frame"><img src="photo.png" alt="Photo"></div></div></div></div></div>';
foreach ( $cases as $name => $case ) {
    [ $rules, $expected ] = $case;
    $html = '<!doctype html><html><head><style>' . $base . $rules . '</style></head><body>'
        . '<section><div class="row">' . $card . $card . '</div></section>'
        . '</body></html>';
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }
    // The media-conditioned `.col` rule is the one carrying `min-height:inherit`.
    preg_match_all('/(?<![\w-])\.col\{([^}]*min-height:inherit[^}]*)\}/', $css, $matches);
    $actual = implode(' | ', $matches[1]);
    if ( 1 !== count($matches[1])
        || ! str_contains($actual, 'height:' . $expected)
        || ('100%' === $expected && str_contains($actual, 'height:auto')) ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$name}: expected .col height:{$expected}, got {$actual}\n");
    }
}
if ( $failures > 0 ) {
    exit(1);
}
echo "Auto-width aspect-ratio ancestor percentage height passed\n";
