<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Capture metadata must not change native eligibility, including folded
// textarea/feedback chains and a classless one-control frame.
$html = '<style>input,textarea{background:#fff;border:1px solid #ccc;padding:12px}.row{display:flex}.column{width:50%}.field{margin-bottom:16px}.feedback{display:none}</style>'
    . '<form><div class="row" data-blocks-engine-node-id="row">'
    . '<div class="column" data-blocks-engine-node-id="left"><div class="field" data-blocks-engine-node-id="field"><input name="email" type="email"></div></div>'
    . '<div class="column" style="" data-blocks-engine-node-id="right"><div class="field" data-blocks-engine-node-id="message"><textarea name="message"></textarea><div class="feedback">Required</div></div></div>'
    . '</div><div data-blocks-engine-node-id="bare"><input name="name"></div>'
    . '<div class="actions" data-blocks-engine-node-id="actions"><button type="submit">Send</button></div></form>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || substr_count($markup, '<!-- wp:group') < 6) {
    throw new RuntimeException('Captured form containers must remain editable native Groups.');
}
$runtime = new Runtime();
$saved = $runtime->serializeBlocks($runtime->parseBlocks($markup));
$dom = new DOMDocument();
@$dom->loadHTML($saved);
$xpath = new DOMXPath($dom);
if (1 !== $xpath->query('//div[contains(@class,"row")]/div[contains(@class,"column")]/div[contains(@class,"field")]/input')->length
    || 1 !== $xpath->query('//div[contains(@class,"column")]/div[contains(@class,"field")]/textarea')->length
    || 1 !== $xpath->query('//div[contains(@class,"field")]/*[contains(@class,"feedback")]')->length
    || 2 !== $xpath->query('//input')->length) {
    throw new RuntimeException('Native form save must preserve wrapper ancestry, controls and feedback.');
}
// Authored attributes outside the native contract still require transport.
$protected = (new HtmlTransformer())->transform('<form><div class="field" data-provider-frame="owned"><input name="name"></div><button>Send</button></form>')->toArray();
if (!str_contains($protected['serialized_blocks'], 'data-provider-frame')) {
    throw new RuntimeException('Authored wrapper attributes must not be discarded.');
}
echo "Captured form native containers passed\n";
