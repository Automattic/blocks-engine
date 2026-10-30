<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A formless group submits only what a script reads from it. Unnamed fields
// with no messaging field carry no submission a form provider could host.
$cases = array(
    'unnamed amount and add button' => array('<label for="amount">Enter amount:</label><div class="input-group"><input type="text" value="250" id="amount" data-blocks-engine-capture-native-control-state="{&quot;version&quot;:1,&quot;kind&quot;:&quot;input&quot;,&quot;type&quot;:&quot;text&quot;,&quot;value&quot;:&quot;250&quot;,&quot;defaultValue&quot;:&quot;250&quot;,&quot;checked&quot;:false,&quot;defaultChecked&quot;:false,&quot;indeterminate&quot;:false}"><span>kr.</span></div><button type="submit" data-price="250"><span>Add</span></button>', false),
    'named field'                   => array('<label for="amount">Enter amount:</label><input type="text" name="amount" id="amount"><button type="submit">Add</button>', true),
    'unnamed contact fields'        => array('<label for="e">Email</label><input type="email" id="e"><label for="m">Message</label><textarea id="m"></textarea><button type="submit">Send</button>', true),
);
foreach ($cases as $name => [$group, $routes]) {
    $out = (new HtmlTransformer())->transform('<main><h1>Give</h1><div class="row"><p>Donation</p><div class="form-group">' . $group . '</div></div></main>')->toArray();
    $form = array_values(array_filter($out['fallbacks'] ?? array(), static fn (array $f): bool => 'html_form_fallback' === ($f['diagnostic_code'] ?? '')));
    if ($routes !== (array() !== $form)) {
        throw new RuntimeException("$name: expected " . ($routes ? 'a' : 'no') . " form-provider finding.\n" . json_encode($form));
    }
    if (! $routes && ! str_contains($out['serialized_blocks'], '/authored-input')) {
        throw new RuntimeException("$name: the captured field stays a native input.");
    }
    foreach (array('Enter amount:', 'Email') as $label) {
        if (str_contains($group, $label) && ! str_contains($out['serialized_blocks'], $label)) {
            throw new RuntimeException("$name: lost \"$label\".");
        }
    }
}
echo "Script-driven pseudo-form passed.\n";
