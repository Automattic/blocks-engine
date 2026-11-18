<?php
declare(strict_types=1);

// Squarespace scopes a section's type scale to its container with a data
// attribute rule that only sets custom properties, and descendants read them
// through var(). The container keeps a marker for that rule, so the
// variables still reach the text that sizes itself with them.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$html = '<!DOCTYPE html><html><head><style>'
    . '.box[data-unit="rem"] .desc{font-size:calc(var(--body-v) * 1rem)}'
    . '@supports (--test-custom-property: true) { .box[data-sid="a1"] { --body-v: 0.9; } }'
    . '</style></head><body><main><section><div class="box" data-sid="a1" data-unit="rem"><div class="desc"><p>Award text.</p></div></div></section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
$css = implode('', array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets']));

$assert(1 === preg_match('/\.(blocks-engine-attribute-[a-z0-9-]+)\.box\)[^{]*\{\s*--body-v:\s*0\.9;?\s*\}/', $css, $rule), 'the custom-property rule targets a container marker');
$assert(1 === preg_match('/class="wp-block-group box [^"]*\b' . preg_quote($rule[1], '/') . '\b/', $result['serialized_blocks']), 'the container block carries that marker');
