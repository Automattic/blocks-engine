<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SourceShells;

$resting = '<header><nav><ul><li><div class="item" data-part="menu-item-content" data-interactive="true"><a href="/">Home</a></div></li><li><div class="item" data-part="menu-item-content" data-interactive="true"><a href="/about/">About</a></div></li></ul></nav></header>';
$selected = str_replace('<div class="item" data-part="menu-item-content" data-interactive="true"><a href="/about/">', '<div class="item menu__item--selected" data-selected="true" data-part="menu-item-content" data-interactive="false"><a href="/about/">', $resting);
if (SourceShells::identity($resting) !== SourceShells::identity($selected)) {
    throw new RuntimeException('Current menu wrapper state must not split shared source shells.');
}
// Page and fragment links may both be current. A selected peer is not
// evidence of the resting classes or interaction attributes.
$multipleResting = str_replace('</ul>', '<li><div class="item" data-part="menu-item-content" data-interactive="true"><a href="/about/#projects">Projects</a></div></li></ul>', $resting);
$multipleSelected = preg_replace('#<div class="item" data-part="menu-item-content" data-interactive="true">(<a href="/about/[^\"]*">)#', '<div class="item menu__item--selected" data-selected="true" data-part="menu-item-content" data-interactive="false">$1', $multipleResting);
if (SourceShells::identity($multipleResting) !== SourceShells::identity($multipleSelected)) {
    throw new RuntimeException('Multiple current menu links must normalize against resting peers only.');
}
// Selected controls outside navigation are content, not route-current state.
$control = '<header><div data-selected="true" class="choice selected">Choice</div></header>';
if (SourceShells::identity($control) === SourceShells::identity(str_replace('true', 'false', $control))) {
    throw new RuntimeException('Non-navigation selected state must remain structural.');
}
if (SourceShells::identity($resting) === SourceShells::identity(str_replace('About', 'Contact', $resting))) {
    throw new RuntimeException('Different navigation content must remain distinct.');
}
echo "Source shell selected menu state: passed\n";
