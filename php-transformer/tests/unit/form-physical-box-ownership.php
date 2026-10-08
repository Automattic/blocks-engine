<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<form method="post"><div class="fields"><div id="email-row"><input name="email" type="email"></div><div id="submit-row"><button type="submit"><span class="caption">Subscribe</span></button></div></div></form>';
$css = '.fields{display:grid;grid-template-columns:100%;grid-template-rows:min-content 1fr;width:280px}'
    . '#email-row{position:relative;left:20px;margin:32px 0 9px;width:240px;grid-area:1/1/2/2;place-self:start}'
    . '#submit-row{position:relative;left:20px;margin:0 0 5px;width:240px;height:45px;grid-area:2/1/3/2;place-self:start;--caption:normal normal normal 16px/1.4em Arial}'
    . 'input{height:40px}button{width:100%;min-width:100%;height:100%}.caption{font:var(--caption)}'
    . '@media(min-width:768px){#email-row{margin-top:12px;left:10px}#submit-row{--caption:normal normal normal 18px/1.4em Arial}}';
$result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
$form = $result['fallbacks'][0] ?? array();
$nodes = array_column($form['layout_graph']['nodes'] ?? array(), null, 'id');
$bySource = array();
foreach ($nodes as $node) $bySource[$node['source']['id'] ?? ''] = $node;
$email = $bySource['email-row']['layout'] ?? array();
$submit = $bySource['submit-row']['layout'] ?? array();
$expected = array('margin_top' => '32px', 'margin_right' => '0', 'margin_bottom' => '9px', 'margin_left' => '0', 'left' => '20px', 'position' => 'relative', 'align_self' => 'start', 'justify_self' => 'start');
foreach ($expected as $key => $value) {
    if (($email[$key] ?? null) !== $value) throw new RuntimeException("Email source box lost {$key}: " . json_encode($email));
}
if (($submit['margin_bottom'] ?? null) !== '5px' || ($submit['height'] ?? null) !== '45px') throw new RuntimeException('Submit box ownership lost.');
$variant = current(array_filter($form['layout_graph']['variants'], static fn(array $row): bool => $row['node'] === $bySource['email-row']['id']));
if (($variant['layout_patch']['margin_top'] ?? null) !== '12px' || ($variant['layout_patch']['left'] ?? null) !== '10px' || isset($variant['layout_patch']['margin_bottom'])) throw new RuntimeException('Conditional box patch must contain its authored changes only.');
$presentation = array_column($form['presentation_graph']['controls'] ?? array(), null, 'index');
if (($presentation[1]['control']['styles']['font'] ?? null) !== 'normal normal normal 16px/1.4em Arial') throw new RuntimeException('Button caption must carry its source-resolved font shorthand: ' . json_encode($form['presentation_graph'] ?? array()));
$fontVariant = current(array_filter($form['presentation_graph']['variants'], static fn(array $row): bool => 1 === $row['index']));
if (($fontVariant['style_patch']['font'] ?? null) !== 'normal normal normal 18px/1.4em Arial') throw new RuntimeException('Responsive caption variable must remain condition-scoped.');
$longhand = (new HtmlTransformer())->transform($html, array('static_css' => $css . '.caption{font:16px Arial;font-size:19px}'))->toArray();
$longhandStyles = array_column($longhand['fallbacks'][0]['presentation_graph']['controls'], null, 'index')[1]['control']['styles'];
if (($longhandStyles['font_size'] ?? null) !== '19px') throw new RuntimeException('A later same-rule font longhand must still win.');
$fragment = (new HtmlTransformer())->transform("<form><div class=\"choice-grid\"><label id=\"choice-label\" class=\"choice\">\n<input type=\"checkbox\" checked><span>Receive updates</span></label></div></form>", array('static_css' => '.choice-grid{display:grid;min-height:584px}.choice{align-self:start;display:flex;height:18px;gap:13px}'))->toArray();
if (!str_contains($fragment['serialized_blocks'], 'authored-native-form') || !empty($fragment['fallbacks'])) throw new RuntimeException('An unnamed choice-only fragment must preserve native state without inventing a provider submission.');
if (!str_contains($fragment['serialized_blocks'], '<label id="choice-label" class="choice"><input') || ($fragment['source_reports']['wp_block_validity']['status'] ?? '') !== 'pass') throw new RuntimeException('Native fragment identity, input order and saved block validity must remain intact.');
echo "Physical form box ownership passed: physical spacing, positioning, self alignment, inherited caption font and native choice fragments.\n";
