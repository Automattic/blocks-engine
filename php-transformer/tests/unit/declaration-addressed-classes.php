<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\DeclarationAddressedClasses;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$identity = new DeclarationAddressedClasses();
$a = 'blocks-engine-control-aaaaaaaaaaaa-7';
$b = 'blocks-engine-control-bbbbbbbbbbbb-19';
$address = static function (string $marker, string $css, array $context = array()) use ($identity): string {
    return $identity->replacements(array(array('content' => str_replace('CONTROL', $marker, $css)) + $context))[$marker];
};
$base = '.CONTROL>.wp-block-button__link{COLOR: red; padding: 2px 4px;}';
$assert($address($a, $base) === $address($b, '.CONTROL>.wp-block-button__link{color:red;padding:2px 4px}'), 'Page bindings and insignificant declaration syntax do not enter the address.');
$assert($address($a, '.CONTROL{color:red;padding:2px;opacity:.8}') === $address($b, '.CONTROL{opacity:.8;padding:2px;color:red}'), 'Independent declaration ordering is normalized.');
$assert($address($a, '.CONTROL{padding:2px;padding-left:4px}') !== $address($b, '.CONTROL{padding-left:4px;padding:2px}'), 'Shorthand and longhand ordering is retained.');
foreach (array(
    '.CONTROL>.wp-block-button__link{color:blue;padding:2px 4px}',
    '.CONTROL>.wp-block-button__link{color:red!important;padding:2px 4px}',
    '@media(min-width:700px){' . $base . '}',
    '@supports(display:grid){' . $base . '}',
    '@container card (width>700px){' . $base . '}',
    '@layer author{' . $base . '}',
    str_replace('>.wp-block-button__link', ':hover>.wp-block-button__link', $base),
    str_replace('>.wp-block-button__link', '', $base),
    $base . '.CONTROL{opacity:.5}',
) as $different) {
    $assert($address($a, $base) !== $address($b, $different), 'Different declarations, selector surfaces, states and conditions must not alias.');
}
$assert($address($a, $base) !== $address($b, $base, array('media' => 'print')), 'Link media conditions enter identity.');
$assert($address($a, $base) !== $address($b, $base, array('stylesheet_target' => 'editor')), 'Editor-only rules cannot alias frontend rules.');
$ab = '.CONTROL{color:red}.CONTROL{color:blue}';
$assert($address($a, $ab) !== $address($b, $ab . '.CONTROL{color:red}'), 'A/B/A cascade order is not deduplicated into A/B.');
$assert($address($a, '.CONTROL{--Case:"A;B";color:var(--Case)}') !== $address($b, '.CONTROL{--case:"A;B";color:var(--case)}'), 'Strings and case-sensitive custom properties survive normalization.');
$map = $identity->replacements(array(array('content' => '.' . $a . '{color:red}')));
$content = '.' . $a . '{color:red}';
$path = 'assets/css/test-' . substr(hash('sha256', $content), 0, 16) . '.css';
$original = array('source' => 'engine-support', 'source_path' => '', 'kind' => 'css', 'path' => $path, 'target_path' => $path, 'content' => $content, 'hash' => hash('sha256', $content), 'source_hash' => hash('sha256', $content), 'bytes' => strlen($content));
$map += $identity->assetPathReplacements(array($path => $original), $map);
$rewritten = $identity->rewrite(array('blocks' => array('stylesheet' => $path), 'stylesheets' => array(array('path' => $path)), 'assets' => array($path => $original)), $map);
$asset = current($rewritten['assets']);
$assert(array_key_first($rewritten['assets']) === $asset['path'] && $rewritten['blocks']['stylesheet'] === $asset['path'] && $rewritten['stylesheets'][0]['path'] === $asset['path'] && $asset['target_path'] === $asset['path'], 'Asset registry keys, block and stylesheet references follow the final path.');
$assert($asset['hash'] === hash('sha256', $asset['content']) && $asset['source_hash'] === $asset['hash'] && $asset['bytes'] === strlen($asset['content']), 'Asset integrity metadata follows rewritten content.');
$assert(str_contains($asset['path'], substr($asset['hash'], 0, 16)), 'Generated stylesheet paths remain content addressed.');
echo "declaration-addressed-classes unit passed\n";
