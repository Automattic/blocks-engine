<?php
declare(strict_types=1);

/**
 * Option copy inside a listbox popup owned by a field control is that
 * control's auxiliary option set (a country-code selector, for one), not
 * free form context. It must not be reported as form_field_context_unrepresented.
 * Copy that no control owns is still named. See blocks-engine#2195.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$optionRows = static function (int $count, bool $selectFirst = false): string {
    $html = '';
    for ( $index = 0; $index < $count; ++$index ) {
        $name = 'Country name ' . $index . ' region';
        $dial = '+' . ( 90 + $index );
        $selected = $selectFirst && 0 === $index ? 'true' : 'false';
        $html .= '<div role="option" aria-selected="' . $selected . '"><span>' . $name . '</span><span>' . $dial . '</span></div>';
    }

    return $html;
};

$diagnostics = static function (array $result): array {
    return array_values(array_filter(
        $result['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'form_field_context_unrepresented' === ( $diagnostic['code'] ?? null )
    ));
};

$compile = static function (string $html): array {
    return ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html ),
    ))->toArray();
};

$controlsBy = static function (array $result, string $key): array {
    $fallback = current(array_filter(
        $result['fallbacks'] ?? array(),
        static fn (array $fallback): bool => 'html_form_fallback' === ( $fallback['diagnostic_code'] ?? null )
    ));

    return is_array($fallback) ? array_column($fallback['controls'] ?? array(), null, $key) : array();
};

// Enough option rows that the panel's combined text exceeds a field-description
// bound, so each row would otherwise be an ambiguous description candidate.
$phone = '<main><form method="post" action="/preorder"><div><span>'
    . '<button type="button" aria-haspopup="listbox" aria-expanded="false" aria-label="Phone. Select a country code" data-dla-listbox-trigger="0"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"></svg></button>'
    . '<div hidden data-dla-listbox-panel="0"><div role="listbox" id="country-codes">' . $optionRows(18, true) . '</div></div>'
    . '</span><input type="tel" name="phone" aria-label="Phone"></div><button type="submit">Send</button></form></main>';
$phoneResult = $compile($phone);
$phoneControls = $controlsBy($phoneResult, 'name');
$phoneTrigger = current(array_filter(
    current(array_filter($phoneResult['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_form_fallback' === ( $fallback['diagnostic_code'] ?? null )))['controls'] ?? array(),
    static fn (array $control): bool => 'listbox' === ( $control['aria_haspopup'] ?? null )
));
$phoneOptions = is_array($phoneTrigger) ? ( $phoneTrigger['options'] ?? array() ) : array();
$assert(
    is_array($phoneTrigger) && 18 === count($phoneOptions) && 'Country name 0 region+90' === ( $phoneOptions[0]['label'] ?? null ) && true === ( $phoneOptions[0]['selected'] ?? null ) && 'Country name 1 region+91' === ( $phoneOptions[1]['label'] ?? null ),
    'listbox option copy owned by an aria-haspopup=listbox trigger is attributed to that control',
    json_encode($phoneTrigger)
);
$assert(
    ! isset($phoneTrigger['_unresolved_description_candidates']) && ! isset($phoneControls['phone']['description']) && ! isset($phoneControls['phone']['_unresolved_description_candidates']),
    'owned listbox option copy is not an unresolved field description',
    json_encode(array( 'trigger' => $phoneTrigger, 'phone' => $phoneControls['phone'] ?? null ))
);
$assert(
    array() === $diagnostics($phoneResult),
    'a country-code listbox does not fail the import as form_field_context_unrepresented',
    json_encode($diagnostics($phoneResult))
);
$phoneDeclaration = current(array_filter(
    $phoneResult['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(),
    static fn (array $declaration): bool => 'forms' === ( $declaration['type'] ?? null )
));
$phoneEntityTrigger = current(array_filter(
    $phoneDeclaration['payload']['entities'][0]['controls'] ?? array(),
    static fn (array $control): bool => 'listbox' === ( $control['aria_haspopup'] ?? null )
));
$assert(
    is_array($phoneEntityTrigger) && 18 === count($phoneEntityTrigger['options'] ?? array()) && ! array_key_exists('_unresolved_description_candidates', $phoneEntityTrigger),
    'owned listbox options reach the declared generic/forms/v1 entity',
    json_encode($phoneEntityTrigger)
);

// The same ownership holds when the trigger names the listbox with aria-controls
// and the capture attributes are absent.
$controlled = '<main><form method="post" action="/preorder"><div><span>'
    . '<button type="button" aria-haspopup="listbox" aria-controls="country-codes" aria-label="Select a country code"></button>'
    . '<div hidden><div role="listbox" id="country-codes">' . $optionRows(18) . '</div></div>'
    . '</span><input type="tel" name="phone"></div><button type="submit">Send</button></form></main>';
$controlledResult = $compile($controlled);
$controlledTrigger = current(array_filter(
    current(array_filter($controlledResult['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_form_fallback' === ( $fallback['diagnostic_code'] ?? null )))['controls'] ?? array(),
    static fn (array $control): bool => 'listbox' === ( $control['aria_haspopup'] ?? null )
));
$assert(
    is_array($controlledTrigger) && 18 === count($controlledTrigger['options'] ?? array()) && array() === $diagnostics($controlledResult),
    'aria-controls listbox ownership attributes option copy and does not report it as unrepresented',
    json_encode(array( 'trigger' => $controlledTrigger, 'diagnostics' => $diagnostics($controlledResult) ))
);

// A listbox no field control owns is still unrepresented copy.
$orphan = '<main><form method="post" action="/apply"><div class="field"><label>Notes</label><input type="text" name="notes">'
    . '<div role="listbox">' . $optionRows(18) . '</div></div><button type="submit">Send</button></form></main>';
$orphanResult = $compile($orphan);
$orphanDiagnostics = $diagnostics($orphanResult);
$assert(
    1 === count($orphanDiagnostics) && str_contains((string) ( $orphanDiagnostics[0]['unrepresented_text'][0] ?? '' ), 'Country name 0'),
    'option copy that no listbox trigger owns is still reported',
    json_encode($orphanDiagnostics)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "form listbox option context FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form listbox option context passed: {$passes} assertions\n";
