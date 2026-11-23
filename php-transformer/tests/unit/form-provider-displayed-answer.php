<?php
declare(strict_types=1);

/**
 * A handed-off form keeps the answers the source displays and no provider
 * text the source never painted: a select with no `selected` option carries
 * its displayed first option as the selection (so the provider does not
 * prepend its own "Select an option" prompt), and a date control's provider
 * "YYYY-MM-DD" format hint is hidden by engine support CSS.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static function (string $html): array {
    return (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files'      => array('index.html' => $html),
    ))->toArray();
};
$controls = static function (array $result): array {
    $declarations = array_values(array_filter(
        $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(),
        static fn (array $item): bool => 'forms' === ($item['type'] ?? null)
    ));
    return $declarations[0]['payload']['entities'][0]['controls'] ?? array();
};
$select = static function (array $controls): array {
    foreach ( $controls as $control ) {
        if ( 'select' === ($control['tag'] ?? null) ) {
            return $control['options'] ?? array();
        }
    }
    return array();
};
$css = static fn (array $result): string => implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'] ?? array(), 'is_array')
));

$failures = array();
$check = static function (bool $ok, string $message) use (&$failures): void {
    if ( ! $ok ) $failures[] = $message;
};

$form = '<main><form><input required placeholder="Full name">'
    . '<select aria-label="Service"><option disabled>Retired</option><option>Wheel &amp; tyre fitment</option><option>Alignment</option></select>'
    . '<input required type="date" aria-label="Preferred date"><button type="submit">Request booking</button></form></main>';
$result = $compile($form);
$options = $select($controls($result));
$check(3 === count($options), 'all select options reach the manifest');
$check(empty($options[0]['selected']) && true === ($options[1]['selected'] ?? null) && empty($options[2]['selected']),
    'the first enabled option, which the source displays, is the selection');
$check(! isset($options[1]['placeholder']), 'a valued displayed option is a real answer, not a prompt');
$stylesheet = $css($result);
$check(str_contains($stylesheet, '.grunion-field-wrap .contact-form__field-hints>.contact-form__field-format{display:none}'),
    'the provider date format hint the source never painted is hidden');

$explicit = $select($controls($compile('<main><form><select aria-label="Size"><option>S</option><option selected>M</option></select><button type="submit">Go</button></form></main>')));
$check(2 === count($explicit) && empty($explicit[0]['selected']) && true === ($explicit[1]['selected'] ?? null), 'an authored selection is kept as authored');

$prompt = $select($controls($compile('<main><form><select aria-label="Size"><option value="">Choose a size</option><option>S</option></select><button type="submit">Go</button></form></main>')));
$check(true === ($prompt[0]['selected'] ?? null) && true === ($prompt[0]['placeholder'] ?? null), 'a displayed empty-value option is the field prompt');

$multiple = $select($controls($compile('<main><form><select multiple aria-label="Tags"><option>A</option><option>B</option></select><button type="submit">Go</button></form></main>')));
$check(array() === array_filter(array_column($multiple, 'selected')), 'a multiple select displays no implicit selection');

$noDate = $css($compile('<main><form><input required placeholder="Full name"><button type="submit">Send</button></form></main>'));
$check(! str_contains($noDate, 'contact-form__field-format'), 'sites without a date control get no format-hint rule');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "form provider displayed answer passed\n";
