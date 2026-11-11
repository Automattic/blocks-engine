<?php
declare(strict_types=1);

/** A button caption composed from sibling text spans keeps each span. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<form method="post" action="/book">'
    . '<button name="day" type="button" class="day"><span class="day__month">Nov</span><span class="day__weekday">Thu</span><span class="day__number">12</span></button>'
    . '<button name="today" type="button" class="day"><span class="day__today">Today</span></button>'
    . '<button name="mixed" type="button">Pick <span class="hint">a day</span></button>'
    . '<input name="email" type="email"><button type="submit">Book</button></form>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$fallbacks = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? null)));
$controls = array_column($fallbacks[0]['controls'] ?? array(), null, 'name');
$parts = static fn (string $name): array => array_map(static fn (array $part): string => $part['classes'] . ':' . $part['text'], $controls[$name]['label_parts'] ?? array());

$failures = 0;
foreach ( array(
    'sibling spans become label parts' => array( 'day__month:Nov', 'day__weekday:Thu', 'day__number:12' ) === $parts('day'),
    'a single span stays the label element' => 'day__today' === ($controls['today']['label_classes'] ?? null) && array() === $parts('today'),
    'loose caption text is not split into parts' => array() === $parts('mixed'),
) as $message => $passed ) {
    if ( ! $passed ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
}

fwrite(STDOUT, sprintf('form-button-label-parts: %d failed%s', $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
