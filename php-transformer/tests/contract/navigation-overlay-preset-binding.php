<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$html = <<<'HTML'
<!doctype html><html><head><style>
body{background:#fff;color:#111}
.nav{display:flex;align-items:center;position:relative}
.nav button{display:none}
.nav nav{display:flex;gap:24px}
.nav a{color:inherit}
@media(max-width:760px){
.nav button{display:block}
.nav nav{display:none;position:absolute;top:76px;left:0;right:0;background:#111;padding:25px;flex-direction:column}
.nav nav.open{display:flex}
}
</style></head><body><header class="nav"><a href="/">Studio</a><button aria-controls="navlinks" aria-expanded="false">Menu</button><nav id="navlinks"><a href="/about/">About</a><a href="/contact/">Contact</a></nav></header><main><h1>Welcome</h1><p>A neutral page.</p></main></body></html>
HTML;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$markups = array_column($plan['template_parts'], 'canonical_block_markup');
$markups[] = $plan['pages'][0]['canonical_block_markup'];
$markup = implode("\n", $markups);
$count = $plan['theme']['design_system']['metrics']['literal_preset_color_count'];
echo 'literal_preset_color_count: ' . $count . PHP_EOL;
$assert(1 === preg_match('/<!-- wp:navigation (\{.*?\}) \/-->/s', $markup, $match), 'The header has a native responsive navigation.');
$attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
$assert('mobile' === ($attrs['overlayMenu'] ?? null), 'The collapsed source panel becomes a mobile overlay.');
$assert('contrast' === ($attrs['overlayBackgroundColor'] ?? null) && !isset($attrs['customOverlayBackgroundColor']), 'The exact panel background binds to the contrast preset instead of a custom literal.');
$assert(0 === $count, 'No exact palette literal remains.');
foreach ($plan['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    $palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    $assert('#111111' === ($palette[$attrs['overlayBackgroundColor']] ?? null), 'The bound preset paints the same color as source #111.');
}
// Exercise both attribute families without adding another fixture or test.
foreach (array('#ffffff' => 'base', '#121212' => null) as $text => $expected) {
    $variant = str_replace('background:#111;padding', 'background:#111;color:' . $text . ';padding', $html);
    $variantPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $variant)))->toArray()['source_reports']['wordpress_site_plan'];
    $variantMarkup = implode("\n", array_column($variantPlan['template_parts'], 'canonical_block_markup'));
    $assert(1 === preg_match('/<!-- wp:navigation (\{.*?\}) \/-->/s', $variantMarkup, $variantMatch), 'The variant retains its navigation.');
    $variantAttrs = json_decode($variantMatch[1], true, 512, JSON_THROW_ON_ERROR);
    if (null === $expected) {
        $assert($text === ($variantAttrs['customOverlayTextColor'] ?? null) && !isset($variantAttrs['overlayTextColor']), 'A near shade keeps its literal overlay text paint.');
    } else {
        $assert($expected === ($variantAttrs['overlayTextColor'] ?? null) && !isset($variantAttrs['customOverlayTextColor']), 'Exact overlay text paint binds to its palette preset.');
    }
}
echo "Navigation overlay preset binding contract passed.\n";
