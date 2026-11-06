<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$rules = '@layer base{svg{display:block}}@layer utilities{.row{display:flex;flex-wrap:wrap;gap:12px;margin-top:32px}.inline-flex{display:inline-flex}.items-center{align-items:center}.gap-1{gap:4px}.btn{border:2px solid #1f2a44;padding:12px 24px;font-weight:700;color:#1f2a44;text-decoration:none}}';
$html = '<!DOCTYPE html><html><head><title>Icon link</title><style>' . $rules . '</style></head><body><main><h1>Icon link</h1><div class="row"><a href="https://example.com/a" class="btn">Inquire</a><a href="https://example.com/b" class="btn inline-flex items-center gap-1">View the original page <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg></a></div></main></body></html>';
$transform = static function (string $source): array {
    $result = (new HtmlTransformer())->transform($source, array())->toArray();
    return array('source' => $source, 'blocks' => $result['serialized_blocks'], 'css' => implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content')));
};
$fixture = $transform($html);
if ( ! preg_match('/:root:root:root :is\([^{}]*wp-block-button__link[^{}]*\)\{display:revert-layer\}/', $fixture['css']) ) {
    throw new RuntimeException('Layered source display must escape Core without copying its value');
}
$plain = $transform(str_replace(array('@layer base{svg{display:block}}', '@layer utilities{'), array('svg{display:block}', ''), str_replace('}}</style>', '}</style>', $html)));
if ( str_contains($plain['css'], ':root:root:root :is(') ) throw new RuntimeException('Unlayered author styles must remain unchanged');
$contested = $transform(str_replace('</style>', '@layer overrides{.inline-flex{display:grid}} .inline-flex:hover{display:block}</style>', $html));
if ( preg_match('/:root:root:root :is\([^{}]*wp-block-button__link[^{}]*\)\{display:revert-layer\}/', $contested['css']) ) {
    throw new RuntimeException('Unlayered state declarations must veto the bridge');
}
if ( in_array('--json', $argv, true) ) {
    echo json_encode(array(
        'layered' => $fixture,
        'plain' => $plain,
        'hover' => $transform(str_replace('</style>', '@layer utilities{.inline-flex:hover{gap:10px}}</style>', $html)),
        'higher' => $transform(str_replace('<style>', '<style>@layer base,utilities,overrides;@layer overrides{.inline-flex{gap:10px}}', $html)),
        // A later lower-priority layer must not win just because its bridge
        // was emitted last. The value is still resolved inside author layers.
        'ordered' => $transform(str_replace('<style>', '<style>@layer overrides,base,utilities;@layer overrides{.inline-flex{display:block}}', $html)),
    ));
} else {
    echo "Projected Core layer cascade contract passed\n";
}
