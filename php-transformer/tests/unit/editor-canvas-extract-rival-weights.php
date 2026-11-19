<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EditorCanvasStylesheets;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// An editor-only arm stays in the sheet visitors download when a later rule
// could tie with it in the editor. A later selector list ties only through
// an arm of equal specificity, and a `::before`/`::after` arm styles its
// generated box, never an element. Weighing the list as a whole, or the
// pseudo-element arm as unreadable, kept every editor arm declaring the same
// property in the front-end sheet.
$css = ':root .editor-styles-wrapper .panel{position:relative}'
    . ':root .editor-styles-wrapper .rail::before{position:absolute}'
    . '#gallery .item .link,.card{position:relative;display:block}'
    . '.rail:before{position:absolute;top:0}'
    . '.rail{position:relative}';
$parts = EditorCanvasStylesheets::separate($css);
$assert(null !== $parts, 'Editor arms no later rule can tie with leave the front-end sheet.');
[$frontend, $editor] = $parts;
$assert(! str_contains($frontend, 'editor-styles-wrapper'), 'The front-end sheet keeps no editor arm: ' . $frontend);
$assert(str_contains($frontend, '#gallery .item .link,.card{position:relative;display:block}') && str_contains($frontend, '.rail:before{position:absolute;top:0}'), 'Front-end rules are kept: ' . $frontend);
$assert(':root .editor-styles-wrapper .panel{position:relative}:root .editor-styles-wrapper .rail::before{position:absolute}' === $editor, 'Both editor arms move to the extract: ' . $editor);

// Real ties still keep the arm in place: an arm of the list, or a rule for
// the same generated box, at the editor arm's specificity.
$tied = EditorCanvasStylesheets::separate(
    ':root .editor-styles-wrapper .panel{position:relative}'
    . ':root .editor-styles-wrapper .rail::before{position:absolute}'
    . '.card,.a .b .c{position:static}'
    . '.x .y .z::after{position:static}.x .y .z:before{position:static}'
);
$assert(null === $tied, 'Arms tied by a list arm or the same generated box stay in place.');

echo "editor-canvas-extract-rival-weights: ok\n";
