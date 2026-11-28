<?php
declare(strict_types=1);

// Template assignments share metadata.post_meta with bound listing fields.
// Only the bound fields may be registered for REST: a protected key registered
// without an auth callback makes WordPress refuse editor saves that include it.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ListingFieldProjection;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$page = static fn(string $type, array $meta): array => array('post_type' => $type, 'metadata' => array('post_meta' => $meta));

$assert('' === ListingFieldProjection::bootstrap(array($page('page', array('_wp_page_template' => 'page-shared')))), 'a template assignment alone registers nothing');
$mixed = ListingFieldProjection::bootstrap(array(
    $page('page', array('_wp_page_template' => 'page-shared')),
    $page('post', array('_wp_page_template' => 'single-event', 'blocks_engine_listing_labels_0123456789ab' => 'Dec')),
));
$assert(!str_contains($mixed, '_wp_page_template'), 'a protected key is never registered beside a bound field');
$assert(1 === substr_count($mixed, 'register_post_meta( ') && str_contains($mixed, "register_post_meta( 'post', 'blocks_engine_listing_labels_0123456789ab'"), 'the bound field is still registered for its post type');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "listing-field-protected-meta: passed\n");
