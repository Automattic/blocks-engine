<?php
declare(strict_types=1);

// Legacy bgcolor hints on body, table, rows and cells paint their page beneath author CSS (#4024).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$fail = static function (string $label, string $detail): void {
    fwrite(STDERR, "FAIL: {$label}\n{$detail}\n");
    exit(1);
};
$page = static fn(string $body, string $content = '<p>Text</p>'): string => '<!DOCTYPE html><html><head><title>T</title></head>' . $body . $content . '</body></html>';
$files = array(
    'index.html' => $page('<body bgcolor="ebe7ea">'),
    'green.html' => $page('<body bgcolor="#004821">'),
    'table.html' => $page('<body>', '<center><table width="300" bgcolor="#ffd79f"><tr bgcolor="red"><td bgcolor="navy">Boxed</td></tr></table></center>'),
);
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
$assets = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')));
$css = implode("\n", array_column($assets, 'content'));
$routes = array();
foreach ($plan['pages'] as $planPage) {
    $routes[$planPage['source_path']] = (string) preg_replace('/^be-r /', '', (string) ($planPage['document_metadata']['root_attributes']['class'] ?? ''));
}

// Each page paints its own body colour, at zero added specificity, ahead of every author stylesheet.
foreach (array('index.html' => '#ebe7ea', 'green.html' => '#004821') as $source => $color) {
    $rule = ':where(html.' . $routes[$source] . ') body{background-color:' . $color . '}';
    if (!str_contains($css, $rule)) $fail("body bgcolor on {$source}", $css);
}
$hintIndex = array_key_first(array_filter($assets, static fn(array $asset): bool => str_contains((string) $asset['content'], 'html.' . $routes['index.html'])));
$authorIndex = array_key_first(array_filter($assets, static fn(array $asset): bool => 'engine-support' !== ($asset['source'] ?? '') && !str_contains((string) ($asset['path'] ?? ''), 'engine-support')));
if (null === $hintIndex || (null !== $authorIndex && $hintIndex > $authorIndex)) $fail('body hints load before author CSS', json_encode(array_column($assets, 'path')));

// A painted single-column box stays core/table so its hints have a box to paint.
$tableMarkup = (string) array_values(array_filter($plan['pages'], static fn(array $p): bool => 'table.html' === $p['source_path']))[0]['canonical_block_markup'];
if (!str_contains($tableMarkup, '<!-- wp:table')) $fail('painted layout table stays core/table', $tableMarkup);
foreach (array('>table){width:300px;background-color:#ffd79f}', '>table>tbody>tr:nth-child(1)){background-color:#ff0000}', '>table>tbody>tr:nth-child(1)>:nth-child(1)){background-color:#000080}') as $rule) {
    if (!str_contains($css, $rule)) $fail("table hint {$rule}", $css);
}

// HTML "rules for parsing a legacy colour value".
foreach (array('#fc0' => '#ffcc00', 'White' => '#ffffff', 'chucknorris' => '#c00000', '#ebe7eaff' => '#eb7eff', 'transparent' => null, ' ' => null) as $source => $expected) {
    if ($expected !== CssColor::legacy($source)) $fail("legacy colour {$source}", var_export(CssColor::legacy($source), true));
}
echo "body-presentational-hints: passed\n";
