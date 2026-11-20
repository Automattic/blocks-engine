<?php
declare(strict_types=1);

/**
 * Synthetic large-form page with a large class-based author stylesheet (#6752).
 *
 * Writes a fixture directory holding one page with several hosted-form-style
 * forms of a few hundred controls each (labels, inputs, selects, radios,
 * checkboxes, field wrappers, fieldsets) and an author stylesheet of class,
 * descendant and long selector-list rules over those controls. Compile it
 * with compile-page-steps.php to time the page and, with
 * COMPILE_STEPS_SAMPLE, sample where the time goes:
 *
 *   php tools/benchmarks/large-form-page.php /tmp/large-form [forms] [controls] [rules]
 *   php -d memory_limit=4G tools/benchmarks/compile-page-steps.php /tmp/large-form
 *
 * Defaults: 5 forms, 400 controls per form, 600 stylesheet rules.
 */

$output = $argv[1] ?? '';
if ('' === $output) {
    fwrite(STDERR, "Usage: php large-form-page.php <fresh-dir> [forms] [controls] [rules]\n");
    exit(1);
}
$forms = max(1, (int) ($argv[2] ?? 5));
$controls = max(1, (int) ($argv[3] ?? 400));
$rules = max(1, (int) ($argv[4] ?? 600));

$field = static function (int $form, int $index): string {
    $id = 'f' . $form . '-q' . $index;
    $label = '<label class="wsite-form-label" for="' . $id . '">Question ' . $index . ' <span class="form-required">*</span></label>';
    $control = match ($index % 6) {
        0 => '<input class="wsite-form-input wsite-input wsite-input-width-370px" type="text" id="' . $id . '" name="' . $id . '">',
        1 => '<textarea class="wsite-form-input wsite-input wsite-input-width-370px" id="' . $id . '" name="' . $id . '"></textarea>',
        2 => '<select class="form-select" id="' . $id . '" name="' . $id . '"><option value="">Choose</option><option value="a">A</option><option value="b">B</option></select>',
        3 => '<input class="wsite-form-input wsite-input" type="email" id="' . $id . '" name="' . $id . '">',
        4 => '<span class="form-radio-container"><input type="radio" id="' . $id . '" name="g' . $form . '-' . intdiv($index, 6) . '" value="yes"><label for="' . $id . '" class="form-choice-label">Yes</label></span>',
        default => '<span class="form-radio-container"><input type="checkbox" id="' . $id . '" name="' . $id . '" value="1"><label for="' . $id . '" class="form-choice-label">Agree</label></span>',
    };

    return '<div class="wsite-form-field wsite-form-field-' . ($index % 7) . '" style="margin:5px 0 0 0;"><div class="wsite-form-input-container">'
        . $label . $control . '</div><div id="instructions-' . $id . '" class="wsite-form-instructions" style="display:none;"></div></div>';
};

$body = '';
for ( $form = 0; $form < $forms; ++$form ) {
    $body .= '<div class="wsite-section-wrap"><div class="wsite-section-content"><div class="container">'
        . '<form enctype="multipart/form-data" action="/ajax/apps/formSubmitAjax.php" method="POST" id="form-' . $form . '" class="wsite-form">'
        . '<div id="form-' . $form . '-form-parent" class="wsite-form-container" style="margin-top:10px;"><ul class="formlist" id="form-' . $form . '-list"><h2 class="wsite-content-title">Form ' . $form . '</h2>';
    for ( $index = 0, $group = 0; $index < $controls; ++$index ) {
        if ( 0 === $index % 40 ) {
            if ( $index > 0 ) {
                $body .= '</fieldset>';
            }
            $body .= '<fieldset class="wsite-form-fieldset fieldset-' . $group . '"><legend class="wsite-form-legend">Section ' . $group++ . '</legend>';
        }
        $body .= $field($form, $index);
    }
    $body .= '</fieldset></ul></div><div style="text-align:left; margin-top:10px; margin-bottom:10px;"><input type="hidden" name="form_version" value="2">'
        . '<a class="wsite-button"><span class="wsite-button-inner">Submit</span></a><button type="submit" class="wsite-button-submit">Submit</button></div></form></div></div></div>';
}

$classes = array('wsite-form-field', 'wsite-form-label', 'wsite-form-input', 'wsite-input', 'form-select', 'form-radio-container', 'form-choice-label', 'wsite-form-fieldset', 'wsite-form-legend', 'wsite-form-instructions', 'wsite-form-input-container', 'wsite-button', 'wsite-button-inner', 'form-required', 'wsite-content-title', 'container');
$properties = array('color:#3a3a3a', 'padding:8px 10px', 'margin:0 0 12px', 'font-weight:600', 'border:1px solid #ccc', 'display:block', 'line-height:1.4', 'background:#fff', 'border-radius:3px', 'font-size:15px');
$css = 'body{margin:0;font-family:Georgia,serif}.wsite-form-field{display:flex;flex-direction:column;gap:4px}';
for ( $rule = 0; $rule < $rules; ++$rule ) {
    $a = $classes[ $rule % count($classes) ];
    $b = $classes[ ( $rule * 7 + 3 ) % count($classes) ];
    $declarations = $properties[ $rule % count($properties) ] . ';' . $properties[ ( $rule * 3 + 1 ) % count($properties) ];
    $selector = match ($rule % 5) {
        0 => '.' . $a,
        1 => '.wsite-form .' . $a . ' .' . $b,
        2 => '#form-' . ( $rule % $forms ) . ' .' . $a . ', .wsite-section-content .' . $b . ':hover',
        3 => '.wsite-form-field-' . ( $rule % 7 ) . ' .' . $a . ' > .' . $b,
        // A generated list of the form's id targets, the shape hosted form
        // services emit for per-field styles.
        default => implode(',', array_map(static fn (int $n): string => '#instructions-f' . ( $rule % $forms ) . '-q' . $n . ', #f' . ( $rule % $forms ) . '-q' . $n, range(( $rule * 11 ) % $controls, min($controls - 1, ( $rule * 11 ) % $controls + 23)))),
    };
    $css .= $selector . '{' . $declarations . '}';
    if ( 0 === $rule % 50 ) {
        $css .= '@media (max-width:' . ( 480 + $rule ) . 'px){.' . $a . ' .' . $b . '{' . $declarations . '}}';
    }
}

$html = '<!doctype html><html><head><meta charset="utf-8"><title>Large forms</title><link rel="stylesheet" href="style.css"></head><body>' . $body . '</body></html>';

if (file_exists($output)) {
    fwrite(STDERR, "Use a fresh output directory.\n");
    exit(1);
}
mkdir($output, 0777, true);
file_put_contents($output . '/index.html', $html);
file_put_contents($output . '/style.css', $css);
fwrite(STDOUT, json_encode(array('forms' => $forms, 'controls_per_form' => $controls, 'rules' => $rules, 'html_bytes' => strlen($html), 'css_bytes' => strlen($css)), JSON_UNESCAPED_SLASHES) . "\n");
