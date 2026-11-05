<?php
declare(strict_types=1);

// Media-scoped font substitutions reset inherited families, even when the
// substituted shorthand cannot be expanded into a plain family stack.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$cases = array(
    'literal' => array('.hero{font:normal 400 64px/1em Georgia, serif}', 'Georgia, serif'),
    'plain var' => array(':root{--h:normal normal 400 64px/1em Georgia}.hero{font:var(--h)}', 'Georgia'),
    'runtime var' => array(':root{--h:normal normal 400 max(0.5px, 0.06 * var(--sf))/1em Georgia}.hero{font:var(--h)}', null),
    'missing var' => array('.hero{font:var(--nope)}', null),
    'later longhand' => array(':root{--h:400 64px Georgia}.hero{font:var(--h);font-family:Verdana, serif}', 'Verdana, serif'),
    'later shorthand' => array(':root{--h:400 64px Georgia}.hero{font-family:Verdana, serif;font:var(--h)}', 'Georgia'),
);
foreach (array('style media', '@media', 'unconditional') as $scope) {
    foreach ($cases as $name => [$css, $expected]) {
        $sheet = match ($scope) {
            'style media' => '<style media="(min-width:1024px)">' . $css . '</style>',
            '@media' => '<style>@media (min-width:1024px){' . $css . '}</style>',
            default => '<style>' . $css . '</style>',
        };
        $html = '<!doctype html><html><head><style>body{font-family:Arial, Helvetica, sans-serif}</style>' . $sheet
            . '</head><body><main><div class="hero"><h1>A prominent heading</h1></div>'
            . '<p>Body copy using the body stack, with enough text to establish the body preset.</p></main></body></html>';
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
        $rows = $result['source_reports']['compiled_site']['pages'][0]['design_usage']['text'] ?? array();
        $headings = array_values(array_filter($rows, static fn(array $row): bool => 'heading' === $row['role']));
        $label = $scope . ': ' . $name;
        $assert(1 === count($headings), $label . ': one heading observation');
        foreach ($headings as $row) {
            $assert($expected === $row['font_family'], $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($row['font_family'], true));
        }
        $theme = null;
        foreach ($result['source_reports']['wordpress_site_plan']['writes'] ?? array() as $write) {
            if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        }
        $assert(is_array($theme), $label . ': site plan includes theme');
        if (str_contains($css, 'var(')) {
            $family = $theme['styles']['elements']['h1']['typography']['fontFamily'] ?? null;
            $assert(!in_array($family, array('Arial, Helvetica, sans-serif', 'var:preset|font-family|body'), true), $label . ': no body stack projected onto h1');
        }
    }
}

if ($failures > 0) exit(1);
echo "Design usage media font shorthand passed\n";
