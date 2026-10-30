<?php
/**
 * A binding's projected source anchor identifies its source block without
 * repeating that block's markup: the binding already carries the markup as
 * `search_block_markup`, so a large region bound by a runtime entity costs its
 * markup once, not twice, against the aggregate runtime declaration budget.
 */
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$markup = "<!-- wp:group -->\n<div class=\"wp-block-group\">" . str_repeat('<p>Card</p>', 20000) . "</div>\n<!-- /wp:group -->";
$anchor = WordPressSitePlan::projectedBindingAnchor(array('search_block_markup' => $markup, 'occurrence' => 2));
$encoded = (string) json_encode($anchor);

$assert(strlen($encoded) < 512, 'The projected anchor stays small for a large bound block: ' . strlen($encoded) . ' bytes.');
$assert(! str_contains($encoded, '<p>Card</p>'), 'The projected anchor does not repeat the bound block markup.');
$assert(hash('sha256', $markup) === ($anchor['source_block_hash'] ?? null) && strlen($markup) === ($anchor['source_block_length'] ?? null), 'The projected anchor identifies the source block by digest and length.');
$assert(2 === ($anchor['source_occurrence'] ?? null), 'The projected anchor keeps the source occurrence.');

echo "projected-binding-anchor-size: ok\n";
