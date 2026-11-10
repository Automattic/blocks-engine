<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A device document's inline value replaces the element's style at runtime.
// A core preset class is !important, so a property another device overrides
// stays inline; the same value without device evidence still binds a preset.
$heading = static fn (string $text, string $evidence): string => '<h2 style="line-height:0.9em;font-size:80px"' . $evidence . '>' . $text . '</h2>';
$device = ' data-blocks-engine-capture-device-inline="' . htmlspecialchars('{"mobile":"line-height:0.9em;font-size:55px"}', ENT_QUOTES) . '"';
$body = '';
foreach (range(1, 4) as $index) $body .= $heading('Plain ' . $index, '') . '<p style="font-size:80px">Copy ' . $index . '</p>';
$body .= $heading('Scoped', $device);
$html = '<!doctype html><html><head><title>Sizes</title></head><body><main>' . $body . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$markup = '';
foreach ($plan['pages'] as $page) $markup .= (string) ($page['canonical_block_markup'] ?? '');
$tag = static function (string $text) use ($markup): string {
    if (1 !== preg_match('/<h2\b([^>]*)>' . preg_quote($text, '/') . '<\/h2>/', $markup, $match)) throw new RuntimeException("Heading $text is missing.");
    return $match[1];
};
if (1 !== preg_match('/has-[a-z0-9-]+-font-size/', $tag('Plain 1'))) throw new RuntimeException('Control: a repeated size binds its font-size preset. ' . $tag('Plain 1'));
$scoped = $tag('Scoped');
if (1 === preg_match('/has-[a-z0-9-]+-font-size/', $scoped) || !str_contains($scoped, 'font-size:80px')) throw new RuntimeException('A device-overridden size stays an inline declaration: ' . $scoped);
echo "Device-inline preset binding passed.\n";
