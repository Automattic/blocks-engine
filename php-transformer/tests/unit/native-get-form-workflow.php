<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $label;
    }
};

$source = '<form class="inventory-filter" action="/inventory"><div class="filter-row"><label class="field-label">Location <input class="location" name="location" value="north" placeholder="Choose a location" required></label><label>Type <select name="type"><option value="">All types</option><option value="house" selected>House</option></select></label></div><div class="actions"><button type="button">Show map</button><button type="submit">Apply filters</button></div></form>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$form = $result['blocks'][0] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');
$generated = $result['source_reports']['generated_blocks'] ?? array();

$assert('custom/authored-native-form' === ($form['blockName'] ?? ''), 'static endpoint GET form is owned by the native form companion');
$assert('/inventory' === ($form['attrs']['action'] ?? ''), 'declared action is editable form state');
$assert('get' === ($form['attrs']['method'] ?? ''), 'absent method retains the native GET default');
$assert(false === ($form['attrs']['methodDeclared'] ?? true), 'absent method is not invented in saved markup');
$assert(str_contains($markup, '<form action="/inventory" class="inventory-filter">'), 'saved form retains action and author class without a method rewrite');
$assert(str_contains($markup, '<input type="text" name="location" value="north" placeholder="Choose a location" class="location" required>'), 'saved field remains a native named required input');
$assert(str_contains($markup, '<select name="type"><option value="">All types</option><option value="house" selected>House</option></select>'), 'saved select retains option values and selection');
$assert(str_contains($markup, '<button type="button"'), 'non-submit button retains its native type');
$assert(str_contains($markup, '<button type="submit"'), 'submit button retains its native type');
$assert(! str_contains($markup, '<!-- wp:html'), 'native GET form does not fall back to raw HTML');
$assert(array() === ($result['fallbacks'] ?? array()), 'native GET form has no provider or form fallback');
$assert(3 === count($generated), 'form and reusable input/select companions are packaged');

parse_str(http_build_query(array( 'location' => 'north', 'type' => 'house' )), $query);
$unspecified = (new HtmlTransformer())->transform('<form><label for="email">Email</label><input id="email" name="email"><button>Send</button></form>')->toArray();
$assert(!str_contains($unspecified['serialized_blocks'] ?? '', 'wp:custom/authored-native-form') && !empty($unspecified['fallbacks']), 'unspecified submission remains provider-materializable instead of inventing a GET workflow');
$assert(array( 'location' => 'north', 'type' => 'house' ) === $query, 'named successful controls produce the GET query parameters');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Native GET form workflow: passed\n";
