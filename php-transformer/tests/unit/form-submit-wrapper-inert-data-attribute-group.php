<?php
declare(strict_types=1);

/**
 * #6728: a form's submit wrapper carrying an inline style and a valued data
 * attribute nothing addresses (Squarespace's
 * `<div data-animation-role="button" class="submit-wrapper"
 * style="vertical-align: middle">`) is a native Group, not a generated
 * layout-shell. The inline style still reaches the Group through the
 * presentation projection, so it renders as before.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$compile = static function (): array {
    $source = '<!DOCTYPE html><html><head><style>.submit-wrapper{display:inline-block}</style></head><body><form>'
        . '<div class="field"><label for="email">Email</label><input id="email" type="email" name="email"></div>'
        . '<div data-animation-role="button" class="submit-wrapper" style="vertical-align: middle"><button type="submit">Sign Up</button></div>'
        . '<div class="captcha-container align-left"></div></form></body></html>';
    $result = ( new ArtifactCompiler() )->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
    return array(
        'markup' => (string) ($result['serialized_blocks'] ?? ''),
        'css'    => implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content')),
    );
};

[ 'markup' => $markup, 'css' => $css ] = $compile();
$assert(! str_contains($markup, '/layout-shell'), 'an unaddressed data attribute and an inline style do not force a generated layout-shell: ' . $markup);
$assert(1 === preg_match('/<!-- wp:group \{"className":"submit-wrapper (be-inline-geometry-[a-f0-9]+)/', $markup, $group), 'the submit wrapper is a native Group keeping its class: ' . $markup);
$assert(isset($group[1]) && 1 === preg_match('/\.' . preg_quote($group[1] ?? '', '/') . '[^{]*\{[^}]*vertical-align:\s*middle/', $css), 'the wrapper inline style reaches the Group');

fwrite(STDOUT, sprintf('form-submit-wrapper-inert-data-attribute-group: %d failed%s', $failures, PHP_EOL));
exit($failures > 0 ? 1 : 0);
