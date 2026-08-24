<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$items = '';
foreach ( array( 100, 110, 120, 130, 143 ) as $index => $width ) {
    $items .= '<div style="display:inline-block;width:' . $width . 'px;height:49px"><a href="/' . $index . '">Item ' . $index . '</a></div>';
}
$result = ( new HtmlTransformer() )->transform(
    '<style>@media(max-width:700px){.exact-menu{display:none}}</style>'
        . '<div class="exact-menu" style="width:603px;height:49px;overflow:hidden">' . $items . '</div>'
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$supportCss = implode("\n", array_map(
    static fn (array $asset): string => 'engine-support' === ($asset['source'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    $result['assets'] ?? array()
));
$authorCss = implode("\n", array_map(
    static fn (array $asset): string => 'author-css' === ($asset['source'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    $result['assets'] ?? array()
));

$assert(
    str_contains($markup, 'exact-menu') && str_contains($markup, 'blocks-engine-css-owned-inline-flow'),
    'An exact-fit atomic inline parent receives the renderer-owned flow marker.'
);
$assert(
    str_contains($supportCss, ':where(.blocks-engine-css-owned-inline-flow){display:flex;flex-wrap:wrap;align-items:baseline;gap:0}')
        && str_contains($supportCss, ':where(.blocks-engine-css-owned-inline-flow)>*{flex:none}'),
    'Engine support removes serialization whitespace and default gaps without shrinking exact child widths.'
);
$assert(
    str_contains($authorCss, '@media(max-width:700px){.exact-menu{display:none}}'),
    'The later authored mobile display rule remains authoritative over inline-flow support.'
);
$assert(
    603 === array_sum(array( 100, 110, 120, 130, 143 ))
        && 5 === substr_count($supportCss, 'display:inline-block'),
    'The deterministic fixture is an exact 603px fit across five atomic inline children.'
);
$assert(
    'pass' === (( new BlockValidityValidator() )->validateBlocks($result['blocks'] ?? array())['status'] ?? ''),
    'The inline-flow marker remains editor-valid native block output.'
);

$mixed = ( new HtmlTransformer() )->transform(
    '<div><div style="display:inline-block;width:100px">Inline</div><div style="width:100px">Block</div></div>'
)->toArray();
$assert(
    ! str_contains((string) ($mixed['serialized_blocks'] ?? ''), 'blocks-engine-css-owned-inline-flow'),
    'Mixed block and inline children retain normal flow semantics.'
);

fwrite(STDOUT, "Exact-fit inline flow contract passed\n");
