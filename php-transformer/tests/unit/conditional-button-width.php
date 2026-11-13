<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

// A flattened button whose width the author states only on phones
// (`.card-foot .btn{width:100%}`) rests shrink-to-fit. The resting reset
// must not be the !important one, or the projected phone width never wins.
$card = static fn (string $name): string => '<article class="card"><h3>' . $name . '</h3><div class="card-foot"><span class="price">$5.00</span><button type="button" class="btn">Agregar</button></div></article>';
$html = '<style>.btn{display:inline-flex;padding:10px 18px;border:1px solid #333}.card-foot{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}'
    . '@media (max-width:600px){.card-foot .btn{width:100%}}</style><main><div class="grid">' . $card('Acondicionador') . $card('Champú') . '</div></main>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = stripslashes((string) json_encode($result['assets'] ?? array()));
$modifier = 'blocks-engine-layout-child-button-conditional-width';

$assert(2 === preg_match_all('/wp:button \{"className":"[^"]*' . $modifier . '/', $markup), 'each flattened card button records its conditional width');
$assert(1 === preg_match('/@media \(max-width:600px\)\{[^@]*wp-block-button__link[^{}]*\{width:100%\}/', $css), 'the phone width is projected onto the button link');
$assert(1 === preg_match('/:where\(\.blocks-engine-layout-child-button:not\([^)]*\.' . $modifier . '[^)]*\)\)>\.wp-block-button__link\{width:fit-content!important\}/', $css), 'the important shrink-to-fit reset skips it');
$assert(str_contains($css, ':where(.blocks-engine-layout-child-button.' . $modifier . ')>.wp-block-button__link.wp-block-button__link{width:fit-content}'), 'it rests shrink-to-fit through a default the author rule outranks');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'the cards stay editor-valid');

// A width stated at rest keeps the existing author modifier only.
$resting = (new HtmlTransformer())->transform(str_replace('@media (max-width:600px){.card-foot .btn{width:100%}}', '.card-foot .btn{width:100%}', $html), array())->toArray();
$assert(! str_contains((string) $resting['serialized_blocks'], $modifier), 'a resting width is not conditional');

fwrite(STDOUT, "Conditional button width tests passed\n");
