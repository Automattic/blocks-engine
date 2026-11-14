<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A single dormant message used to become visible provider help, whereas two
// messages were merely ambiguous. Neither hidden shape is permanent help.
foreach (array(
    '<span class="feedback">Required</span>',
    '<div class="feedback"><span>Required</span></div>',
    '<span hidden>Required</span>',
    '<div hidden><span>Required</span></div>',
    '<span aria-hidden="true">Required</span>',
    '<div aria-hidden="true"><span>Required</span></div>',
    '<span style="display:none">Required</span>',
    '<div style="display:none"><span>Required</span></div>',
    '<div class="feedback"><div><span>Required</span></div></div>',
) as $copy) {
    $result = (new HtmlTransformer())->transform('<style>.feedback{display:none}</style><form method="post"><div><label for="phone">Phone</label><input id="phone" name="phone" type="tel">' . $copy . '<span>Include area code</span></div><button>Send</button></form>', array())->toArray();
    $form = current(array_filter($result['fallbacks'] ?? array(), static fn(array $item): bool => 'html_form_fallback' === ($item['diagnostic_code'] ?? null)));
    $controls = array_column($form['controls'] ?? array(), null, 'name');
    if ('Include area code' !== ($controls['phone']['description'] ?? null)) {
        throw new RuntimeException('Hidden feedback must not become or obscure permanent field help.');
    }
}
echo "Hidden field feedback is not provider help\n";
