<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><style>
body{color:#212529;background:#fff;font-family:Arial,sans-serif}
p{color:#c80000} .state:hover{color:#000!important}
button{background:#c80000;color:#fff;font-size:18px;padding:20px;border-radius:4px}
.ring{border:7px solid #e9ecef}
</style></head><body><main><p>Palette red text</p><p class="state" style="color:#c80000">Stateful inline paint</p><div class="ring"><p>Border paint</p></div><button>Send message</button></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$markup = implode('\n', array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(str_contains($markup, 'Stateful inline paint'), 'The stateful paragraph is emitted.');
$assert(!str_contains($markup, '"text":"#c80000"'), 'Stateful inline text reads an exact preset without a forced preset class.');
$assert(1 === preg_match('/<p\b([^>]*)>Stateful inline paint<\/p>/', $markup, $state), 'The stateful paragraph keeps its own opening tag.');
$assert(str_contains($state[1], 'color:var(--wp--preset--color--'), 'Stateful paint retains an inline preset declaration.');
$assert(1 !== preg_match('/has-[a-z0-9-]+-color\b/', str_replace('has-text-color', '', $state[1])), 'No important palette class masks the hover color.');
$assert(!str_contains($markup, '"fontSize":"18px"'), 'Button size is supplied by the theme rather than left literal.');
$assert(!str_contains($markup, '"color":"#e9ecef"'), 'Border-only paint is included in the palette and bound.');
$assert(in_array('#e9ecef', array_column($plan['theme']['design_system']['palette'], 'color'), true), 'The border color has an exact editable token.');
$assert(1 === preg_match('/color:var\(--wp--preset--color--([a-z0-9-]+)\)/', $state[1], $token), 'The inline paint names a palette token.');
$palette = array_column($plan['theme']['design_system']['palette'], 'color', 'slug');
$assert('#c80000' === ($palette[$token[1]] ?? null), 'The stateful inline token preserves the exact authored resting paint, not a nearby palette role.');
echo "Exact inline preset binding passed.\n";
