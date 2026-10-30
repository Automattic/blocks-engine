<?php
declare(strict_types=1);

/**
 * A label whose text is painted by an inner carrier — `<label class="field">
 * <span class="field__label">Name</span><input></label>` — takes that text's
 * case from the carrier, the same way it takes the carrier's font and color.
 * `text-transform` is an inherited text property, so a provider label that
 * renders the caption directly must keep it, and an in-form heading that
 * inherits it from an ancestor keeps it too.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

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

$document = static function (string $body): DOMDocument {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $body . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    return $document;
};
$label = static function (string $css) use ($document): array {
    $form = $document('<form class="f" method="post"><label class="field"><span class="field__label">Name</span><input type="text" name="name"></label><button type="submit">Send</button></form>')->getElementsByTagName('form')->item(0);
    $graph = (new FormPresentationGraphBuilder())->build($form, array(), $css);
    return array( 'styles' => array_column($graph['controls'], null, 'index')[0]['label']['styles'] ?? array(), 'variants' => $graph['variants'] ?? array() );
};

$carrier = $label('.field { display: flex; flex-direction: column; } .field__label { font-size: .72rem; letter-spacing: .14em; text-transform: uppercase; }');
$assert('uppercase' === ($carrier['styles']['text_transform'] ?? null) && '.14em' === ($carrier['styles']['letter_spacing'] ?? null), 'the label text carrier sets the caption case alongside its typography', json_encode($carrier['styles']));

$own = $label('.field { text-transform: lowercase; } .field__label { text-transform: uppercase; }');
$assert('uppercase' === ($own['styles']['text_transform'] ?? null), 'the carrier case wins over the label case for the text it paints', json_encode($own['styles']));

$responsive = $label('.field__label { text-transform: none; } @media (min-width: 600px) { .field__label { text-transform: uppercase; } }');
$patches = array_map(static fn (array $variant): array => $variant['style_patch'] ?? array(), array_filter($responsive['variants'], static fn (array $variant): bool => 0 === ($variant['index'] ?? null) && 'label' === ($variant['role'] ?? null)));
$assert('none' === ($responsive['styles']['text_transform'] ?? null) && in_array('uppercase', array_column($patches, 'text_transform'), true), 'a conditional carrier case becomes a label variant', json_encode(array( $responsive['styles'], $patches )));

$heading = $document('<form class="f" method="post"><div class="eyebrow"><p class="copy">Write to us</p></div><input type="text" name="name"></form>')->getElementsByTagName('p')->item(0);
$inherited = (new FormPresentationGraphBuilder())->buildElement($heading, array(), '.eyebrow { text-transform: uppercase; }');
$assert('uppercase' === ($inherited['styles']['text_transform'] ?? null), 'in-form copy inherits its case from the nearest declaring ancestor', json_encode($inherited));

if ( 0 < $failures ) {
    fwrite(STDERR, "form label carrier case FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form label carrier case passed: {$passes} assertions\n";
