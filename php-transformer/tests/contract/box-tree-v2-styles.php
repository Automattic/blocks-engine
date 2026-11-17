<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$parent = $tree['nodes']['n1']['viewports'];
$observations = $parent;
$observations['desktop']['used_styles']['color'] = 'rgb(255, 0, 0)';
$observations['desktop']['used_styles']['position'] = 'relative';
$observations['desktop']['used_styles']['top'] = '12px';
$pseudo = $observations['desktop'];
$pseudo['content'] = '"hello"';
$observations['desktop']['pseudo']['before'] = $pseudo;
$state = new LayoutGeometryState();
$attrs = (new BoxTreeStyleProjector())->project(array(), $observations, $tree['viewports'], $state, $parent);
$css = $state->cssForSerializedBlocks($attrs['className']);
$assert(!str_contains($css, 'font-family:'), 'Equal inherited typography is not emitted.');
$assert(str_contains($css, 'top:12px'), 'Placement is projected.');
$assert(str_contains($css, '::before'), 'Rendering pseudo gets its own selector.');
$assert(str_contains($css, 'content:none'), 'Missing mobile pseudo resets desktop content.');
$assert(str_contains($css, 'color:inherit'), 'Mobile value equal to parent resets desktop override to inheritance.');
$assert(isset($attrs['style']['color']['text']), 'Differing inherited color is mapped on the originating node.');
