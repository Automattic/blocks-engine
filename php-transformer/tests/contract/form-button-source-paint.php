<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;

/**
 * Contract coverage for the buttons-inside-a-form reduction: a submit button
 * (`background:#3a6e9e`, white text) and a ghost button (`background:#f3efe9`,
 * dark text) inside a posted form, plus a fixed bottom bar whose anchor
 * converts to `core/button`.
 *
 * The form declares its controls for a form provider. A provider materializes
 * each control into a block that carries its paint as style attributes — the
 * class rules that painted the source control stay behind with the source
 * stylesheet, so the declaration must state what the source paints. Both
 * buttons' declared presentation therefore carries the source background and
 * text colours. The palette still names each colour a token, and the shipped
 * stylesheet paints the same colours through that preset with the source
 * literal as fallback.
 *
 * The element-level button style in theme.json is never a transparent
 * background: a framework reset (`button{background-color:transparent}`) would
 * reach every button block's link, which WordPress paints with white text, and
 * hide that text behind nothing. The reset stays source-owned while an opaque
 * element button default still projects.
 */

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/form-button-source-paint.html');
$compile = static fn (string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();

$controls = array();
foreach ($compile($source)['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] ?? array() as $entity) {
        foreach ($entity['controls'] ?? array() as $control) {
            if (in_array($control['type'] ?? '', array('button', 'reset', 'submit'), true)) $controls[] = $control;
        }
    }
}
$byType = array_column($controls, null, 'type');
$assert(isset($byType['submit'], $byType['button']), 'Both form buttons are declared controls: ' . json_encode(array_keys($byType)));
$expected = array(
    'submit' => array('background' => '#3a6e9e', 'text' => '#fff'),
    'button' => array('background' => '#f3efe9', 'text' => '#111'),
);
foreach ($expected as $type => $paint) {
    $color = $byType[$type]['presentation']['style']['color'] ?? array();
    $assert($paint['background'] === ($color['background'] ?? null), "The declared $type control carries its source background: " . json_encode($color));
    $assert($paint['text'] === ($color['text'] ?? null), "The declared $type control carries its source text colour: " . json_encode($color));
}

$plan = $compile($source)['source_reports']['wordpress_site_plan'];
$css = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null)) $css .= "\n" . ($asset['content'] ?? '');
}
$assert(1 === preg_match('/background:var\(--wp--preset--color--[a-z0-9-]+,#3a6e9e\)/', $css), 'The submit paint ships through a palette preset with the source literal as fallback.');
$assert(1 === preg_match('/background:var\(--wp--preset--color--[a-z0-9-]+,#f3efe9\)/', $css), 'The ghost paint ships through a palette preset with the source literal as fallback.');

$preflight = (string) preg_replace('/<\/style>/u', "button,[type=\"button\"],[type=\"submit\"]{-webkit-appearance:button;background-color:transparent;background-image:none}\nbutton{color:#004488}\n</style>", $source, 1);
$themeJson = null;
foreach ($compile($preflight)['source_reports']['wordpress_site_plan']['writes'] ?? array() as $write) {
    if ('theme.json' === ($write['target_path'] ?? null)) {
        $themeJson = json_decode((string) ($write['payload']['data'] ?? ''), true);
    }
}
$assert(is_array($themeJson), 'The reset variant still produces a theme.json.');
$buttonElement = $themeJson['styles']['elements']['button'] ?? array();
$background = $buttonElement['color']['background'] ?? null;
$assert(null === $background || (is_string($background) && CssColor::opaque($background)), 'The element button style never states a transparent background: ' . json_encode($background));
$assert('#004488' === ($buttonElement['color']['text'] ?? null), 'An opaque element button default still projects: ' . json_encode($buttonElement));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Form buttons keep their source paint; the element button style is never transparent.\n";
