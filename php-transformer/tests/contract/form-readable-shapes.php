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
$assert(!empty($form['readable_blocks']), 'Readable tree must survive every control type (date, file, reset, image).');
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
// Each control and each fieldset is one field; a control's box encloses it.
$fields = [];
$fieldWalk = static function (array $blocks, ?array $parent) use (&$fieldWalk, &$fields, $assert): void {
    foreach ($blocks as $block) {
        if (isset($block['form_field'])) $fields[] = [$block, $parent];
        $assert(count($block['innerBlocks'] ?? []) === count(array_filter($block['innerContent'] ?? [], 'is_null')), 'Every finding block keeps one innerContent slot per inner block.');
        if ('group' === ($block['form_field']['kind'] ?? null)) {
            foreach ($block['innerBlocks'] ?? [] as $child) $assert(!str_contains(json_encode($child), '"' . strip_tags($block['form_field']['label']) . '"'), 'A legend is stated once, as its group label.');
        }
        $fieldWalk($block['innerBlocks'] ?? [], $block);
    }
};
$fieldWalk($form['readable_blocks'], null);
$byLabel = [];
foreach ($fields as [$field, $parent]) $byLabel[strip_tags($field['form_field']['label'])] = [$field, $parent];
foreach (['Topic', 'Booking', 'Days'] as $legend) {
    $assert('group' === ($byLabel[$legend][0]['form_field']['kind'] ?? null), $legend . ' fieldset is a group labelled by its legend.');
    $assert('14px' === ($byLabel[$legend][0]['form_field']['label_attributes']['style']['typography']['fontSize'] ?? ''), 'Legend presentation survives on the group label.');
}
$box = static function (string $label) use (&$byLabel): array {
    [$field, $parent] = $byLabel[$label] ?? [[], []];
    $wrapper = $parent['attrs']['wrappers'][0] ?? [];
    return [isset($field['form_control_index']) && str_ends_with($parent['blockName'] ?? '', '/layout-shell') && 1 === count($parent['innerBlocks']), $wrapper, $parent['innerContent'][0] ?? ''];
};
foreach (['Name' => '240px', 'Location' => '200px', 'Guests' => '120px'] as $label => $basis) {
    [$enclosed, $wrapper, $opening] = $box($label);
    $assert($enclosed && 'div' === ($wrapper['tagName'] ?? '') && str_contains($wrapper['attributes']['style'] ?? '', 'flex:1 1 ' . $basis) && str_starts_with($opening, '<div'), $label . ' control is enclosed in its label box, retagged to a div.');
}
[$enclosed, $wrapper] = $box('I agree to the privacy policy.');
$assert($enclosed && str_contains($wrapper['attributes']['style'] ?? '', 'align-items:flex-start'), 'Consent control keeps its box.');
$assert(str_contains($byLabel['I agree to the privacy policy.'][0]['form_field']['label'] ?? '', '<a href="/privacy/">privacy policy</a>'), 'Consent field label keeps its link.');
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
// A styled field box is stated once on the control's path, and a caption label
// that names no control stays in the tree as content.
$rowHtml = '<html><head><style>.half{float:left;width:50%}.group{padding-bottom:18px}</style></head><body><form><div class="group"><label>Name</label><div class="row"><div class="half"><input id="first"><label for="first">First</label></div><div class="half"><input id="last"><label for="last">Last</label></div></div></div><div class="group"><label for="email">Email</label><input id="email" type="email"></div><button type="submit">Send</button></form></body></html>';
$form = null;
$find((new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>$rowHtml]])->toArray());
$paths = [];
$pathWalk = static function (array $blocks, array $classes) use (&$pathWalk, &$paths): void {
    foreach ($blocks as $block) {
        $own = array_map(static fn (array $wrapper): string => $wrapper['attributes']['class'] ?? '', $block['attrs']['wrappers'] ?? []);
        if ('core/group' === ($block['blockName'] ?? '')) $own = array_merge($own, preg_split('/\s+/', trim($block['attrs']['className'] ?? '')) ?: []);
        if (isset($block['form_control_index'])) $paths[$block['form_control_index']] = array_merge($classes, $own);
        $pathWalk($block['innerBlocks'] ?? [], array_merge($classes, $own));
    }
};
$pathWalk($form['readable_blocks'], []);
foreach ([0 => 'half', 1 => 'half', 2 => 'group'] as $index => $class) {
    $assert(1 === count(array_keys($paths[$index] ?? [], $class)), 'Control ' . $index . ' sits in its .' . $class . ' field box once.');
}
$assert(str_contains(json_encode($form['readable_blocks']), '"Name"'), 'A caption label that names no control stays in the tree.');
// A wrapping label that owns its control (the default Framer field shape)
// frames it outermost first: the walk shells the shared row, the field box
// ends at the caption label, and the wrapper between the caption level and
// the control rides separately as the control's own box, enclosing just the
// control inside the field box (`row > lab > wrap`, never `row > wrap > lab`).
$framerHtml = '<html><head><style>.row{display:flex;gap:16px}.lab{flex:1 0 0px;width:1px}.wrap{width:100%}.cap{padding:4px}</style></head><body><form><div class="row">'
    . '<label class="lab"><div class="cap"><p>Nom</p></div><div class="wrap"><input name="nom"></div></label>'
    . '<label class="lab"><div class="cap"><p>Mail</p></div><div class="wrap"><input name="mail"></div></label>'
    . '</div></form></body></html>';
$form = null;
$find((new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>$framerHtml]])->toArray());
$frames = [];
$frameWalk = static function (array $blocks, array $classes) use (&$frameWalk, &$frames): void {
    foreach ($blocks as $block) {
        $own = array_map(static fn (array $wrapper): string => $wrapper['attributes']['class'] ?? '', $block['attrs']['wrappers'] ?? []);
        // A class-only field box frames the control as a native group.
        if ('core/group' === ($block['blockName'] ?? '') && '' !== ($block['attrs']['className'] ?? '')) $own[] = $block['attrs']['className'];
        if (isset($block['form_control_index'])) $frames[$block['form_control_index']] = array_merge($classes, $own);
        $frameWalk($block['innerBlocks'] ?? [], array_merge($classes, $own));
    }
};
$frameWalk($form['readable_blocks'], []);
foreach ([0, 1] as $index) {
    $assert(['row', 'lab', 'wrap'] === ($frames[$index] ?? null), 'Control ' . $index . ' keeps its frames in source order: row > lab > wrap.');
}
// The caption level bounds the field box: the field shell holds one inner
// block, the control's own wrapper shell, which alone holds the control.
foreach ([0, 1] as $index) {
    $enclosing = null;
    $controlWalk = static function (array $blocks) use (&$controlWalk, &$enclosing, $index): void {
        foreach ($blocks as $block) {
            foreach ($block['innerBlocks'] ?? [] as $inner) {
                if (($inner['form_control_index'] ?? null) === $index) $enclosing = $block;
                $controlWalk([$inner]);
            }
        }
    };
    $controlWalk($form['readable_blocks']);
    $wrapper = $enclosing['attrs']['wrappers'][0] ?? [];
    $assert(1 === count($enclosing['innerBlocks'] ?? []) && 'wrap' === ($wrapper['attributes']['class'] ?? ''), 'Control ' . $index . ' is enclosed alone by its own wrapper shell.');
    $assert($index === ($enclosing['form_control_box'] ?? null), 'Control ' . $index . "'s own wrapper shell is marked as its control box.");
}
// A control's own box beside an empty error slot: the field holds more than
// caption and control, so the walk reaches the box's outer frame as its own
// entry. That frame is still the control's box, marked once around just the
// control, so a provider can present the border on its input, not its label.
$slotHtml = '<html><head><style>.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.box{border:1px solid #000;border-radius:100px;height:44px}.entry{border:0;width:100%}</style></head><body><form><div class="grid">'
    . '<div class="field"><label for="first" class="caption">First</label><div class="root"><div class="box"><input id="first" name="first" class="entry"></div></div><div class="error"></div></div>'
    . '<div class="field"><label for="mail" class="caption">Email</label><div class="root"><div class="box"><input id="mail" type="email" name="mail" class="entry"></div></div><div class="error"></div></div>'
    . '</div><div class="send"><button type="submit">Send</button></div><p class="note">We reply soon.</p></form></body></html>';
$form = null;
$find((new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>$slotHtml]])->toArray());
foreach ([0, 1] as $index) {
    $enclosing = null;
    $controlWalk = static function (array $blocks) use (&$controlWalk, &$enclosing, $index): void {
        foreach ($blocks as $block) {
            foreach ($block['innerBlocks'] ?? [] as $inner) {
                if (($inner['form_control_index'] ?? null) === $index) $enclosing = $block;
                $controlWalk([$inner]);
            }
        }
    };
    $controlWalk($form['readable_blocks'] ?? []);
    $classes = array_map(static fn (array $wrapper): string => $wrapper['attributes']['class'] ?? '', $enclosing['attrs']['wrappers'] ?? []);
    $assert(['root', 'box'] === $classes && 1 === count($enclosing['innerBlocks'] ?? []), 'Control ' . $index . ' beside an error slot is enclosed alone by its root > box frames.');
    $assert($index === ($enclosing['form_control_box'] ?? null), 'Control ' . $index . "'s frames beside an error slot are marked as its control box.");
}
$marked = [];
$markWalk = static function (array $blocks) use (&$markWalk, &$marked): void {
    foreach ($blocks as $block) {
        if (isset($block['form_control_box'])) $marked[] = $block['form_control_box'];
        $markWalk($block['innerBlocks'] ?? []);
    }
};
$markWalk($form['readable_blocks'] ?? []);
$assert([0, 1] === $marked, 'Each captioned control is marked once; an uncaptioned submit wrapper stays a plain shell.');
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
// A fieldset framing a captured group is that group's node, labelled by its legend.
$fieldsetHtml = str_replace(['<form><div id="choices"', '</div><label>'], ['<form><fieldset><legend>Pick <em>one</em></legend><div id="choices"', '</div></fieldset><label>'], $capturedHtml);
$form = null;
$find((new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>$fieldsetHtml]])->toArray());
$group = $form['readable_blocks'][0]['innerBlocks'][0] ?? [];
$assert(0 === ($group['form_choice_group_index'] ?? null) && 'Pick <em>one</em>' === ($group['form_field']['label'] ?? '') && !str_contains(json_encode($group['innerBlocks']), 'form_choice_group_index'), 'Fieldset is the choice group node with its legend as label.');
// The source shows which choice is selected through each choice's ARIA state.
$pressedConfig = $config;
foreach ($pressedConfig['choices'] as &$choice) $choice['tag'] = 'button';
unset($choice);
$pressedHtml = str_replace([htmlspecialchars(json_encode($config), ENT_QUOTES), '>One<', '>Two<'], [htmlspecialchars(json_encode($pressedConfig), ENT_QUOTES), ' aria-pressed="false">One<', ' aria-pressed="true">Two<'], $capturedHtml);
$form = null;
$find((new ArtifactCompiler())->compile(['entrypoint'=>'index.html','files'=>['index.html'=>$pressedHtml]])->toArray());
$assert(['selected_index' => 1, 'selected' => [false, true]] === ($form['choice_groups'][0]['observed_transition'] ?? null), 'A choice group records the selection its source shows.');
echo "Readable form shapes contract passed.\n";
