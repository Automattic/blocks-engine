<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$result = (new HtmlTransformer())->transform(
    '<form><button type="submit" class="send"><div class="label-shell" data-label="true"><p class="caption" style="color:white;font-family:Inter">Submit</p></div></button></form>',
    array()
)->toArray();
$markup = $result['serialized_blocks'];
$document = new DOMDocument();
$document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$assert(1 === $xpath->query('//button[@type="submit"]/div[contains(@class,"label-shell")][@data-label="true"]/p[contains(@class,"caption")][@style="color:white;font-family:Inter"]')->length, 'styled label ancestry and native submit semantics survive conversion');
$assert('Submit' === trim($xpath->query('//button')->item(0)->textContent), 'label stays plain editable text inside its presentation chain');
$typed = (new HtmlTransformer())->transform('<style>p{color:white;font-size:14px}</style><form><button type="submit"><p>Submit</p></button></form>', array())->toArray();
$assert(str_contains($typed['serialized_blocks'], 'blocks-engine-source-p-'), 'retained label tags receive the same identity that carried type selectors address');
$generator = new AuthoredButtonBlockGenerator();
$wrappers = array(
    array('tagName' => 'div', 'attributes' => array('class' => 'label-shell')),
    array('tagName' => 'span', 'attributes' => array('style' => 'color:white', 'onclick' => 'bad()', 'data-wp-on--click' => 'bad()')),
);
$attrs = array('type' => 'submit', 'text' => 'Send & Go', 'labelWrappers' => $wrappers);
$saved = $generator->markup($attrs);
$assert('<button type="submit"><div class="label-shell"><span style="color:white">Send &amp; Go</span></div></button>' === $saved, 'save escapes edited text while preserving safe presentation');
$attrs['text'] = '<script>bad()</script>';
$assert(!str_contains($generator->markup($attrs), '<script>'), 'edited label cannot introduce executable markup');
$attrs['labelWrappers'][] = array('tagName' => 'script', 'attributes' => array());
$assert(!str_contains($generator->markup($attrs), '<script>'), 'unsupported wrapper tags do not render');
$assert('<button type="submit">Plain &amp; safe</button>' === $generator->markup(array('text' => 'Plain & safe')), 'plain labels retain their established save shape');
$branched = new DOMDocument();
$branched->loadHTML('<button><span>One</span><input value="Two"></button>', LIBXML_NOERROR | LIBXML_NOWARNING);
$assert(array() === AuthoredButtonBlockGenerator::labelWrappers($branched->getElementsByTagName('button')->item(0)), 'interactive or branching label trees are not copied as HTML');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Authored button label presentation passed: 8 assertions\n";
