<?php
declare(strict_types=1);

/**
 * Label content is RichText. Framework text-split comments (React's `<!-- -->`)
 * show as non-editable chips in the editor, so a label keeps their text-run
 * boundaries with the engine's neutral carrier instead, as paragraphs and
 * headings do.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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
$carrier = '<mark style="background-color:transparent;color:inherit" role="none"> *</mark>';
$labelBlock = static function (array $blocks): ?array {
    foreach ( $blocks as $block ) {
        if ( str_ends_with((string) ($block['blockName'] ?? ''), '/authored-label') ) {
            return $block;
        }
    }
    return null;
};

foreach ( array(
    'associated' => '<input id="email" type="checkbox"><label for="email">Adresse Email<!-- --> *</label>',
    'external' => '<label for="outside">Adresse Email<!-- --> *</label>',
) as $case => $html ) {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $label = $labelBlock($result['blocks'] ?? array());
    $content = (string) ($label['attrs']['content'] ?? '');
    $assert(null !== $label, "{$case}: the label is an authored label", (string) ($result['serialized_blocks'] ?? ''));
    $assert(! str_contains($content, '<!--'), "{$case}: label RichText content carries no comment", $content);
    $assert('Adresse Email' . $carrier === $content, "{$case}: the text-run boundary survives as the neutral carrier", $content);
    $assert(str_contains((string) $result['serialized_blocks'], '<label for="' . ( 'associated' === $case ? 'email' : 'outside' ) . '">Adresse Email' . $carrier . '</label>'), "{$case}: saved label markup matches its content", (string) $result['serialized_blocks']);
}

$plain = ( new HtmlTransformer() )->transform('<label for="o">Rich <strong>bold</strong> text</label>')->toArray();
$assert('Rich <strong>bold</strong> text' === (string) ($labelBlock($plain['blocks'] ?? array())['attrs']['content'] ?? ''), 'labels without comments keep their content unchanged');

if ( 0 < $failures ) {
    fwrite(STDERR, "label comment text-run boundaries FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "label comment text-run boundaries passed: {$passes} assertions\n";
