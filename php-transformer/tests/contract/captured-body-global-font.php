<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// The captured body is not a block. Its engine-owned box rule must keep
// layout without pinning the font to a preset over Global Styles typography.
$fixture = __DIR__ . '/../fixtures/box-tree-body-layout';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => file_get_contents($fixture . '/index.html'),
        'box-tree/index.json' => file_get_contents($fixture . '/index.json'),
    ),
))->toArray()['source_reports']['wordpress_site_plan'];

$theme = array();
$bodyRules = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
if ('var:preset|font-family|body' !== ($theme['styles']['typography']['fontFamily'] ?? null)) {
    throw new RuntimeException('The captured body font must be owned by theme.json.');
}
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $selector, string $body, array $ancestors) use (&$bodyRules): void {
        if (array() === $ancestors && in_array(trim($selector), array('body', ':root body'), true)) $bodyRules .= $body . ';';
    });
}
if (str_contains($bodyRules, 'font-family:')) throw new RuntimeException('Captured body font must yield to Global Styles, not restate a preset: ' . $bodyRules);
if (!str_contains($bodyRules, 'max-width:26em') || !str_contains($bodyRules, 'padding:25vh 2em 2em')) throw new RuntimeException('Yielding body typography must preserve its captured box.');
echo "Captured body Global Styles font contract passed.\n";
