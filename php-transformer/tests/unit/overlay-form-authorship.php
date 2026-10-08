<?php
declare(strict_types=1);

/**
 * Closed-overlay render state is not form non-authorship.
 *
 * A native form inside an aria-hidden ancestor, with no modal role, keeps the
 * same authored controls and page-owned generic/forms/v1 binding as its visible
 * twin. Template scaffolding, in-form honeypots, hidden inputs, and non-form
 * accessibility-hidden controls stay excluded.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ( ! $ok ) {
        $failures[] = $message;
    }
};

$fields = static function (string $idPrefix): string {
    return '<input type="hidden" name="recaptchaToken" value="">'
        . '<label for="' . $idPrefix . '-name">Name</label><input id="' . $idPrefix . '-name" name="name" type="text" autocomplete="name" required>'
        . '<label for="' . $idPrefix . '-email">Email</label><input id="' . $idPrefix . '-email" name="email" type="email" autocomplete="email" required>'
        . '<button type="submit">Send</button>';
};

$paired = '<!doctype html><html><body><main>'
    . '<form id="bookingForm">' . $fields('visible') . '</form>'
    . '<div id="bookingOverlay" aria-hidden="true" class="fixed inset-0 hidden">'
    . '<form id="overlayBookingForm">' . $fields('overlay') . '</form>'
    . '</div></main></body></html>';

$compiled = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array( 'index.html' => $paired ),
))->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'] ?? array();
$formFallbacks = array_values(array_filter(
    $compiled['fallbacks'] ?? array(),
    static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? null)
));
$declarations = array_values(array_filter(
    $plan['runtime_declarations'] ?? array(),
    static fn (array $declaration): bool => 'entity_collection' === ($declaration['kind'] ?? null) && 'forms' === ($declaration['type'] ?? null)
));
$entities = $declarations[0]['payload']['entities'] ?? array();
$byFormId = array();
foreach ( $entities as $entity ) {
    $byFormId[(string) ($entity['form']['id'] ?? '')] = $entity;
}
$pageMarkup = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
$controlNames = static function (array $entity): array {
    return array_values(array_map(
        static fn (array $control): string => (string) ($control['name'] ?? $control['type'] ?? ''),
        $entity['controls'] ?? array()
    ));
};
$transformer = (new HtmlTransformer())->transform($paired)->toArray();
$dispatcherFallbacks = array_values(array_filter(
    $transformer['fallbacks'] ?? array(),
    static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? null)
));
$dispatcherById = array();
foreach ( $dispatcherFallbacks as $fallback ) {
    $dispatcherById[(string) ($fallback['form']['id'] ?? '')] = $fallback;
}

$assert(2 === count($dispatcherFallbacks), 'FormDispatcher emits one fallback for the visible form and one for the closed overlay');
foreach ( array( 'bookingForm', 'overlayBookingForm' ) as $formId ) {
    $fallback = $dispatcherById[$formId] ?? array();
    $names = $controlNames($fallback);
    $assert(array( 'name', 'email', 'submit' ) === $names, $formId . ' dispatcher controls keep labelled fields and submit, not the hidden recaptcha token: ' . json_encode($names));
    $assert('generic/block-binding/v1' === ($fallback['binding']['schema'] ?? null) && '' !== trim((string) ($fallback['binding']['search_block_markup'] ?? '')), $formId . ' dispatcher binding is page-resolvable');
}

$assert(1 === count($declarations) && 'generic/forms/v1' === ($declarations[0]['payload']['schema'] ?? null), 'compile projects one generic/forms/v1 collection');
$assert(isset($byFormId['bookingForm'], $byFormId['overlayBookingForm']), 'visible and closed-overlay native forms both reach the site plan: ' . implode(',', array_keys($byFormId)));
$assert(! isset($byFormId['bookingForm']['form']['action'], $byFormId['overlayBookingForm']['form']['action']), 'closed-overlay authorship does not invent a server request');
foreach ( array( 'bookingForm', 'overlayBookingForm' ) as $formId ) {
    $entity = $byFormId[$formId] ?? array();
    $binding = $entity['bindings'][0] ?? array();
    $search = (string) ($binding['search_block_markup'] ?? '');
    $assert(array( 'name', 'email', 'submit' ) === $controlNames($entity), $formId . ' declared controls match the dispatcher manifest');
    $assert('generic/block-binding/v1' === ($binding['schema'] ?? null) && '' !== $search && str_contains($pageMarkup, $search), $formId . ' binding is owned by the compiled page');
}
$declines = array_values(array_filter(
    $compiled['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'runtime_form_declaration_declined' === ($diagnostic['code'] ?? null)
));
$assert(array() === $declines, 'authored overlay controls are declared rather than declined');

$document = new DOMDocument();
$document->loadHTML($paired, LIBXML_NOERROR | LIBXML_NOWARNING);
$visibleForm = $document->getElementById('bookingForm');
$overlayForm = $document->getElementById('overlayBookingForm');
$assert(
    $visibleForm instanceof DOMElement
    && $overlayForm instanceof DOMElement
    && FormControlClassifier::hasDataEntryControls($visibleForm) === FormControlClassifier::hasDataEntryControls($overlayForm)
    && count(FormControlClassifier::controlElements($visibleForm)) === count(FormControlClassifier::controlElements($overlayForm)),
    'closed overlay ancestry does not change native data-entry authorship'
);

$honeypot = '<!doctype html><html><body><main><form id="contact">'
    . '<label for="email">Email</label><input id="email" name="email" type="email">'
    . '<div aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>'
    . '<button type="submit">Send</button></form></main></body></html>';
$honeypotResult = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $honeypot ) ))->toArray();
$honeypotEntity = current(array_filter(
    current(array_filter($honeypotResult['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'forms' === ($declaration['type'] ?? null)))['payload']['entities'] ?? array(),
    static fn (array $entity): bool => 'contact' === ($entity['form']['id'] ?? null)
));
$honeypotNames = $controlNames(is_array($honeypotEntity) ? $honeypotEntity : array());
$assert(array( 'email', 'submit' ) === $honeypotNames, 'in-form aria-hidden honeypots stay bookkeeping: ' . json_encode($honeypotNames));

$pseudo = '<!doctype html><html><body><main><div id="closedShell" aria-hidden="true">'
    . '<label for="ghost">Email</label><input id="ghost" name="email" type="email"><button type="submit">Send</button>'
    . '</div></main></body></html>';
$pseudoResult = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $pseudo ) ))->toArray();
$pseudoDeclarations = array_values(array_filter(
    $pseudoResult['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(),
    static fn (array $declaration): bool => 'forms' === ($declaration['type'] ?? null)
));
$pseudoFallbacks = array_values(array_filter(
    $pseudoResult['fallbacks'] ?? array(),
    static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? null)
));
$assert(array() === $pseudoDeclarations && array() === $pseudoFallbacks, 'a closed non-form control cluster is not forced into a declarable pseudo-form');

$template = '<!doctype html><html><body><main>'
    . '<template><form id="templateForm"><label for="scaffold">Email</label><input id="scaffold" name="scaffold" type="email"><button type="submit">Send</button></form></template>'
    . '<noscript><form id="noscriptForm"><input name="noscript-email" type="email"><button type="submit">Send</button></form></noscript>'
    . '<div aria-hidden="true"><button type="button" id="bookkeeping">Skip</button></div>'
    . '</main></body></html>';
$templateDocument = new DOMDocument();
$templateDocument->loadHTML($template, LIBXML_NOERROR | LIBXML_NOWARNING);
foreach ( array( 'scaffold', 'noscript-email' ) as $name ) {
    $control = null;
    foreach ( $templateDocument->getElementsByTagName('input') as $input ) {
        if ( $input instanceof DOMElement && $name === $input->getAttribute('name') ) {
            $control = $input;
            break;
        }
    }
    $assert($control instanceof DOMElement && FormControlClassifier::isNonAuthoredControl($control), $name . ' template/source scaffolding stays non-authored');
}
$bookkeeping = $templateDocument->getElementById('bookkeeping');
$assert($bookkeeping instanceof DOMElement && FormControlClassifier::isNonAuthoredControl($bookkeeping), 'non-form accessibility-hidden controls stay bookkeeping');
$templateResult = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $template ) ))->toArray();
$templateDeclarations = array_values(array_filter(
    $templateResult['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(),
    static fn (array $declaration): bool => 'forms' === ($declaration['type'] ?? null)
));
$assert(array() === $templateDeclarations, 'template and noscript scaffolding does not declare a form');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "overlay form authorship passed\n";
