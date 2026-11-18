<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Buttons whose inline text colours equal palette tokens (`#fff` is the
// `#ffffff` token) while hover rules restyle them, next to painted groups. A
// preset class would mask the hover rules, so each colour must still name
// its token, and no colour attribute may keep a literal equal to a preset.
$html = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main>'
    . '<section class="band" style="background-color:#1a1a1a;color:#f3efe8"><h2>Collection</h2><p>Copy on the dark band.</p>'
    . '<a class="cta" href="/enquire/" style="display:inline-block;padding:12px 24px;background-color:#8a5a3c;color:#f3efe8">Enquire about this collection</a>'
    . '<a class="cta cta-chat" href="https://example.test/chat" style="display:inline-block;padding:12px 24px;background-color:#1a1a1a;color:#fff">Chat with us</a></section>'
    . '<div class="card" style="background-color:#ffffff;color:#1a1a1a"><p>A light card.</p></div>'
    . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'site.css' => 'body{margin:0;background:#f3efe8;color:#1a1a1a}.cta:hover{color:#000}.cta-chat:hover{color:#8a5a3c}',
)))->toArray()['source_reports']['wordpress_site_plan'];

$palette = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $palette = array_column(json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR)['settings']['color']['palette'], 'color', 'slug');
}
$markup = $plan['pages'][0]['canonical_block_markup'];
$assert(2 === preg_match_all('/<!-- wp:button (\{.*?\}) -->(.*?)<!-- \/wp:button -->/s', $markup, $buttons, PREG_SET_ORDER), 'Both links are native buttons.');
foreach ($buttons as [, $json, $saved]) {
    $text = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['style']['color']['text'] ?? null;
    $assert(is_string($text) && 1 === preg_match('/^var:preset\|color\|([a-z0-9-]+)$/', $text, $slug), 'A button text colour equal to a token is stored as its preset: ' . json_encode($text));
    $assert(str_contains($saved, 'color:var(--wp--preset--color--' . $slug[1] . ')') && !str_contains($saved, 'has-' . $slug[1] . '-color'), 'The saved link reads the token inline, without a preset class that would mask the hover rule.');
    $source = str_contains($saved, 'Chat with us') ? '#fff' : '#f3efe8';
    $assert(CssColor::hex($source) === CssColor::hex((string) ($palette[$slug[1]] ?? '')), 'The preset paints the colour the source rendered.');
}
$assert(1 === preg_match('/<!-- wp:group \{[^}]*"className":"card"[^>]*"backgroundColor":"([a-z0-9-]+)"/', $markup, $card) && '#ffffff' === ($palette[$card[1]] ?? null), 'A group background equal to a token is stored as its preset.');

$metrics = $plan['theme']['design_system']['metrics'];
$assert(0 === ($metrics['literal_preset_color_count'] ?? null), 'No colour attribute stores a literal equal to a preset: ' . json_encode($metrics['literal_preset_color_count'] ?? null));
$assert(0 === $metrics['refusal_reasons']['state_variant'], 'The hover-restyled button colours are not refused.');

echo "Preset colour binding contract passed.\n";
