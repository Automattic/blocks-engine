<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$document = new DOMDocument();
$element = $document->createElement('div');
foreach (array(
    '' => false,
    ' ; ; ' => false,
    'zXBUYb:zTPCnb;zQF9Uc:Qxe3nd;' => false,
    'rcuQ6b:WYd;GvneHb:og1FDd;' => false,
    'custom:click' => false,
    'clickCustom:handler' => false,
    'click:' => false,
    'click:handler' => true,
    'handler' => true,
    ' ; handler ; ' => true,
    'custom:handler; keydown: keyboard ;' => true,
    'click:handler;custom:handler' => true,
) as $value => $expected) {
    $element->setAttribute('jsaction', $value);
    $assert($expected === (array() !== SourceDom::eventMetadata($element)), 'jsaction metadata: ' . $value);
}
foreach (array('input', 'change', 'submit', 'focus', 'touchstart', 'pointerdown', 'dblclick', 'paste', 'wheel') as $event) {
    $element->setAttribute('jsaction', $event . ':handler');
    $assert(array(array('type' => 'declared', 'attribute' => 'jsaction')) === SourceDom::eventMetadata($element), 'interaction event: ' . $event);
}
$element->setAttribute('jsaction', 'custom:handler');
foreach (array('onclick', 'data-action', 'data-on', 'data-event') as $attribute) {
    $element->setAttribute($attribute, 'handler');
    $assert(1 === count(SourceDom::eventMetadata($element)), 'independent event evidence: ' . $attribute);
    $element->removeAttribute($attribute);
}

$page = '<!doctype html><html><head><meta charset="utf-8"><title>Declared custom events</title>'
    . '<style>.card{padding:16px;border:1px solid #ccc;margin:8px}</style></head><body>'
    . '<div class="card" jsaction="zXBUYb:zTPCnb;zQF9Uc:Qxe3nd;"><p>First card</p></div>'
    . '<div class="card" jsaction="rcuQ6b:WYd;GvneHb:og1FDd;"><p>Second card</p></div>'
    . '<div class="card" jsaction="zXBUYb:zTPCnb;"><p>Third card</p></div></body></html>';
$result = (new HtmlTransformer())->transform($page)->toArray();
$assert(array() === $result['fallbacks'], 'custom-only content imports without fallbacks');
foreach (array('click:handler', 'handler', 'custom:internal;keydown:handler') as $binding) {
    $result = (new HtmlTransformer())->transform('<div jsaction="' . $binding . '"><p>Interactive card</p></div>')->toArray();
    $losses = array_filter($result['fallbacks'], static fn(array $row): bool => 'interactive_control_behavior_lost' === ($row['reason'] ?? ''));
    $assert(array() !== $losses, 'real interaction still reports behavior loss: ' . $binding);
}
echo "JSAction declared events passed.\n";
