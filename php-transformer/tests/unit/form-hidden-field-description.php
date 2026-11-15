<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Hidden validation copy must not become always-visible provider help text.
// Keep visible helper copy eligible, even beside nested hidden feedback.
foreach ( array('class="feedback"', 'hidden', 'style="display:none"', 'style="visibility:hidden"') as $hidden ) {
    foreach ( array('', '<small>Use your work email</small>') as $help ) {
        $html = '<style>.feedback{display:none}</style><main><form method="post">'
            . '<div><label for="email">Email</label><input id="email" name="email" type="email">'
            . '<div ' . $hidden . '><span>An email is required.</span></div>' . $help
            . '</div><button type="submit">Send</button></form></main>';
        $result = (new HtmlTransformer())->transform($html, array())->toArray();
        $form = current(array_filter($result['fallbacks'] ?? array(), static fn (array $item): bool => 'html_form_fallback' === ($item['diagnostic_code'] ?? null)));
        $controls = array_column($form['controls'] ?? array(), null, 'name');
        if (!isset($controls['email']) || ($controls['email']['description'] ?? null) !== ('' === $help ? null : 'Use your work email')) {
            throw new RuntimeException('Hidden validation feedback must not be promoted into field help: ' . json_encode($controls));
        }
        if ('email' !== ($controls['email']['type'] ?? null) || 'Email' !== ($controls['email']['label'] ?? null)) {
            throw new RuntimeException('Filtering hidden help must preserve the editable control identity.');
        }
    }
}
echo "form hidden field description passed\n";
