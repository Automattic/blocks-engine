<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/form-readable-shapes.html');
$result = (new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>$html]])->toArray();
$form = null;
$find = static function (array $value) use (&$find, &$form): void {
    if ('html_form_fallback' === ($value['diagnostic_code'] ?? null)) $form = $value;
    foreach ($value as $child) if (is_array($child)) $find($child);
};
$find($result);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(!empty($form['readable_blocks']), 'Readable tree must survive a date input.');
$indices = [];
$walk = static function (array $blocks) use (&$walk, &$indices, $assert): void {
    foreach ($blocks as $block) {
        if (isset($block['form_control_index'])) $indices[] = $block['form_control_index'];
        $assert(!str_contains($block['attrs']['className'] ?? '', 'authored-select-wrapper'), 'No synthetic select wrapper.');
        $walk($block['innerBlocks'] ?? []);
    }
};
$walk($form['readable_blocks']);
sort($indices);
$assert($indices === range(0, count($form['controls']) - 1), 'Every control has exactly one metadata index.');
$legends = [];
$legendWalk = static function (array $blocks) use (&$legendWalk, &$legends): void {
    foreach ($blocks as $block) {
        if ('core/group' === ($block['blockName'] ?? '')) {
            foreach ($block['innerBlocks'] ?? [] as $child) {
                if ('core/paragraph' === ($child['blockName'] ?? '') && in_array($child['attrs']['content'] ?? '', ['Topic','Booking','Days'], true)) $legends[] = $child;
            }
        }
        $legendWalk($block['innerBlocks'] ?? []);
    }
};
$legendWalk($form['readable_blocks']);
$assert(3 === count($legends), 'Each fieldset owns a legend paragraph at its source position.');
foreach ($legends as $legend) $assert('14px' === ($legend['attrs']['style']['typography']['fontSize'] ?? ''), 'Legend presentation survives.');
$tree = json_encode($form['readable_blocks'], JSON_UNESCAPED_SLASHES);
foreach (['Topic','Booking','Days','Name','Email','Phone','Location','Date','Guests','Message','I agree to the','privacy policy','/privacy/'] as $text) {
    $assert(str_contains($tree, $text), 'Tree preserves ' . $text);
}
// Inspect published block markup, not the copy embedded in diagnostic metadata.
$strings = [];
$collect = static function (array $value) use (&$collect, &$strings): void {
    foreach ($value as $key => $child) {
        if (in_array($key, ['source_reports','diagnostics','findings'], true)) continue;
        if (is_array($child)) $collect($child);
        elseif (is_string($child) && str_contains($child, '<!-- wp:')) $strings[] = $child;
    }
};
$collect($result);
$page = implode("\n", $strings);
$assert('' !== $page, 'Serialized page blocks found.');
foreach (['Topic','Booking','Days','/privacy/'] as $text) $assert(str_contains($page, $text), 'Published fallback preserves ' . $text);
$assert(str_contains($page, '<a href="/privacy/">privacy policy</a>'), 'Published consent label retains its anchor.');
$assert(!str_contains($page, 'authored-select-wrapper'), 'Published select has no synthetic wrapper.');
$assert(!str_contains($page, 'form_control_index') && !str_contains($page, 'form_choice_group_index'), 'Finding indices never enter serialized markup.');
$plain = (new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>'<html><body><form><label><input type="checkbox">Accept the <a href="/terms/">terms</a>.</label><button type="submit">Send</button></form></body></html>']])->toArray();
$form = null;
$find($plain);
$assert(str_contains(json_encode($form['readable_blocks'], JSON_UNESCAPED_SLASHES), '<a href=\\"/terms/\\">terms</a>'), 'Unstyled labels retain rich inline content too.');
// Types without prose degradations still paint as native inputs, even unstyled.
foreach (['date','time','datetime-local','month','week','color'] as $type) {
    $typed = (new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>'<html><body><form><label>Value<input type="' . $type . '"></label><button type="submit">Send</button></form></body></html>']])->toArray();
    $form = null;
    $find($typed);
    $assert(!empty($form['readable_blocks']), $type . ' keeps a readable tree.');
    $assert(str_contains(json_encode($form['readable_blocks']), 'type=\\"' . $type . '\\"'), $type . ' has a native input fallback.');
}
// Captured choice groups carry their own index as well as indices on children.
$config = ['group'=>['selector'=>'#choices','tag'=>'div','id'=>'choices'], 'choices'=>[['index'=>0,'label'=>'One'],['index'=>1,'label'=>'Two']], 'states'=>[['html'=>'<button type="button">One</button><button type="button">Two</button>']]];
$capturedHtml = '<html><body><form><div id="choices" data-blocks-engine-choice-group="true" data-blocks-engine-choice-config="' . htmlspecialchars(json_encode($config), ENT_QUOTES) . '"><button type="button" style="padding:10px">One</button><button type="button" style="padding:10px">Two</button></div><label>Name<input type="text" style="padding:10px"></label><button type="submit">Send</button></form></body></html>';
$captured = (new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>$capturedHtml]])->toArray();
$form = null;
$find($captured);
$groups = [];
$groupWalk = static function (array $blocks) use (&$groupWalk, &$groups): void {
    foreach ($blocks as $block) {
        if (isset($block['form_choice_group_index'])) $groups[] = $block['form_choice_group_index'];
        $groupWalk($block['innerBlocks'] ?? []);
    }
};
$groupWalk($form['readable_blocks'] ?? []);
$assert([0] === $groups && 1 === count($form['choice_groups'] ?? []), 'Captured choice block identifies its metadata index.');
$indices = [];
$walk($form['readable_blocks']);
sort($indices);
$assert($indices === range(0, count($form['controls']) - 1), 'Captured group children retain control identity.');
echo "Readable form shapes contract passed.\n";
