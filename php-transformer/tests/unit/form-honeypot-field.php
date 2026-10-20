<?php
declare(strict_types=1);

/**
 * A form honeypot — a decoy field bots fill and no reader sees — carries all
 * three of `aria-hidden="true"`, `tabindex="-1"`, and an inline style that
 * hides it (display:none, visibility:hidden, or an absolute box moved 999px
 * or further off-screen), together on the control itself or together on the
 * label that wraps it. It is left out of form extraction: no field in the
 * generic/forms/v1 entity, and no unrepresented label text for a form
 * provider to decline the whole form over (blocks-engine#3108). A field
 * carrying only some of the signals stays a normal field. (The aria-hidden
 * wrapping label without the off-screen style is already held out of the
 * control manifest by the standing accessibility-trap rule in
 * FormControlClassifier::isNonAuthoredControl(), independent of the
 * honeypot predicate; this change must not re-admit it either.)
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

$honeypot = '<label style="position:absolute;left:-9999px" aria-hidden="true" tabindex="-1">'
    . '<span>Website</span><input type="text" tabindex="-1" autocomplete="off" name="website"></label>';

$compile = static function (string $html): array {
    return ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html ),
    ))->toArray();
};

$unrepresentedDiagnostics = static function (array $result): array {
    return array_values(array_filter(
        $result['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'form_field_context_unrepresented' === ( $diagnostic['code'] ?? null )
    ));
};

$formEntity = static function (array $result): ?array {
    $declaration = current(array_filter(
        $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(),
        static fn (array $declaration): bool => 'forms' === ( $declaration['type'] ?? null )
    ));
    return is_array($declaration) ? ( $declaration['payload']['entities'][0] ?? null ) : null;
};

$fieldNames = static fn (array $entity): array => array_values(array_filter(array_column($entity['controls'] ?? array(), 'name')));

// The extracted metadata the entity declares: the binding anchor deliberately
// preserves the source form markup, so honeypot copy is judged on what form
// extraction carried out of it.
$extractedJson = static fn (array $entity): string => (string) json_encode(array(
    'form' => $entity['form'] ?? array(),
    'controls' => $entity['controls'] ?? array(),
    'choice_groups' => $entity['choice_groups'] ?? array(),
    'steps' => $entity['steps'] ?? array(),
));

$contactForm = static fn (string $honeypotMarkup, string $position): string => '<main><form method="post" action="/contact">'
    . '<p><label for="name">Name</label><input type="text" id="name" name="name"></p>'
    . ( 'between' === $position ? $honeypotMarkup : '' )
    . '<p><label for="email">Email</label><input type="email" id="email" name="email"></p>'
    . '<p><label for="message">Message</label><textarea id="message" name="message"></textarea></p>'
    . '<button type="submit">Send</button>'
    . ( 'end' === $position ? $honeypotMarkup : '' )
    . '</form></main>';

// The captured storefront's contact form: the honeypot sits among the real
// fields, so its label copy lands between two controls with no positional
// anchor and would otherwise be reported as unrepresented.
$between = $compile($contactForm($honeypot, 'between'));
$betweenEntity = $formEntity($between);
$betweenNames = is_array($betweenEntity) ? $fieldNames($betweenEntity) : array();
$assert(
    is_array($betweenEntity) && array( 'name', 'email', 'message' ) === $betweenNames,
    'the honeypot contributes no field and the real fields stay in the form entity',
    json_encode($betweenNames)
);
$assert(
    array() === $unrepresentedDiagnostics($between),
    'a honeypot label is not reported as form_field_context_unrepresented',
    json_encode($unrepresentedDiagnostics($between))
);
$assert(
    ! str_contains($extractedJson($betweenEntity), 'Website'),
    'no honeypot label copy reaches the form entity',
    $extractedJson($betweenEntity)
);

// The same form with the honeypot after the fields, the position the source
// ships: the decoy copy must not surface as form context either.
$end = $compile($contactForm($honeypot, 'end'));
$endEntity = $formEntity($end);
$assert(
    is_array($endEntity) && ! in_array('website', $fieldNames($endEntity), true)
        && ! str_contains($extractedJson($endEntity), 'Website')
        && array() === $unrepresentedDiagnostics($end),
    'a honeypot placed after the fields contributes no field and no copy',
    $extractedJson($endEntity)
);

// A value carrier no one wraps can be a honeypot too, when the control
// itself carries all three signals: hidden from assistive technology, out of
// the tab order, and moved off-screen.
$selectResult = $compile('<main><form method="post" action="/contact">'
    . '<p><label for="name">Name</label><input type="text" id="name" name="name"></p>'
    . '<select name="website" aria-hidden="true" tabindex="-1" style="position:absolute;left:-9999px"><option value=""></option></select>'
    . '<button type="submit">Send</button></form></main>');
$selectEntity = $formEntity($selectResult);
$selectNames = is_array($selectEntity) ? $fieldNames($selectEntity) : array();
$assert(
    is_array($selectEntity) && array( 'name' ) === $selectNames,
    'a select carrying all three honeypot signals itself is not an authored field',
    json_encode($selectNames)
);
$assert(
    array() === $unrepresentedDiagnostics($selectResult),
    'an unwrapped select honeypot raises no unrepresented-copy diagnostic',
    json_encode($unrepresentedDiagnostics($selectResult))
);

// A honeypot inside a field wrapper: the wrapper holds nothing but the
// decoy, so its copy must not surface as form context either.
$wrapped = $compile('<main><form method="post" action="/contact">'
    . '<p><label for="name">Name</label><input type="text" id="name" name="name"></p>'
    . '<p><label style="position:absolute;left:-9999px" aria-hidden="true" tabindex="-1"><span>Website</span><input type="text" tabindex="-1" autocomplete="off" name="website"></label></p>'
    . '<p><label for="email">Email</label><input type="email" id="email" name="email"></p>'
    . '<button type="submit">Send</button></form></main>');
$wrappedEntity = $formEntity($wrapped);
$assert(
    is_array($wrappedEntity) && ! in_array('website', $fieldNames($wrappedEntity), true)
        && ! str_contains($extractedJson($wrappedEntity), 'Website')
        && array() === $unrepresentedDiagnostics($wrapped),
    'a field wrapper holding only a honeypot contributes no field and no copy',
    $extractedJson($wrappedEntity)
);

// A field carrying only some of the signals stays a normal field. The same
// label without the off-screen style has two of the three signals, so the
// honeypot predicate does not fire for it; its aria-hidden wrapping label is
// held out of the manifest by the standing accessibility-trap rule (as on
// trunk), and this change must not re-admit it either.
$partial = $compile('<main><form method="post" action="/contact">'
    . '<input type="text" name="company" aria-hidden="true">'
    . '<input type="text" name="fax" tabindex="-1">'
    . '<label tabindex="-1"><span>Website</span><input type="text" tabindex="-1" autocomplete="off" name="website"></label>'
    . '<label aria-hidden="true" tabindex="-1"><span>Bot field</span><input type="text" tabindex="-1" autocomplete="off" name="botfield"></label>'
    . '<button type="submit">Send</button></form></main>');
$partialEntity = $formEntity($partial);
$partialNames = is_array($partialEntity) ? $fieldNames($partialEntity) : array();
$assert(
    is_array($partialEntity) && array( 'company', 'fax', 'website' ) === $partialNames,
    'a field that is only aria-hidden, or only tabindex=-1, alone or on its label, stays a normal field',
    json_encode($partialNames)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "form honeypot field FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form honeypot field passed: {$passes} assertions\n";
