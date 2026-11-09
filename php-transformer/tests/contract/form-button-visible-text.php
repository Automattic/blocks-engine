<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html>
<html><head><style>
.cart-loader, .cart-added-text { display: none; }
</style></head><body>
<form action="/cart" method="post">
<label>Quantity <input type="number" name="quantity" value="1"></label>
<button class="sqs-add-to-cart-button"><div class="sqs-add-to-cart-button-inner">
  <span class="add-to-cart-text">Add To Cart</span><span class="cart-loader"></span><span class="cart-added-text">Added!</span>
</div></button>
</form>
</body></html>
HTML;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$texts = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] ?? array() as $entity) {
        foreach ($entity['controls'] ?? array() as $control) {
            if ('submit' === ($control['type'] ?? null)) $texts[] = $control['text'] ?? null;
        }
    }
}
if (array('Add To Cart') !== $texts) {
    fwrite(STDERR, 'Expected exactly the rendered button label Add To Cart; got ' . json_encode($texts) . "\n");
    exit(1);
}
echo "Form button metadata excludes CSS-hidden state labels.\n";
