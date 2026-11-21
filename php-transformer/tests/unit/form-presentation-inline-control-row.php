<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

// #7249: Cornerstone's quantity stepper has inline controls, not flex/grid items.
$html = <<<'HTML'
<style>.form-increment .button{margin:0;padding:3px 5px}.form-input--incrementTotal{width:32px}</style>
<form method="post" action="/cart.php" data-cart-item-add>
  <input type="hidden" name="product_id" value="1">
  <div class="form-field form-field--increments">
    <div class="form-increment" data-quantity-change>
      <button class="button button--icon" data-action="dec"><span class="is-srOnly">Decrease Quantity:</span>−</button>
      <input class="form-input form-input--incrementTotal" name="qty[]" type="tel" value="1" min="1" pattern="[0-9]*">
      <button class="button button--icon" data-action="inc"><span class="is-srOnly">Increase Quantity:</span>+</button>
    </div>
  </div>
  <input type="submit" class="button button--primary" value="Add to Cart">
</form>
HTML;
$document = new DOMDocument();
$document->loadHTML($html);
$graph = (new FormPresentationGraphBuilder())->build(
    $document->getElementsByTagName('form')->item(0),
    array(),
    $document->getElementsByTagName('style')->item(0)->textContent
);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert('generic/computed-form-presentation/v3' === $graph['schema'], 'Inline row upgrades the v1 graph to v3.');
$assert(1 === count($graph['control_rows'] ?? array()), 'Only the nearest shared container records a row.');
$row = $graph['control_rows'][0];
$assert('inline' === ($row['flow'] ?? null), 'The row records inline flow.');
$assert(array(0, 1, 2) === array_column($row['members'], 'index'), 'The three controls are adjacent members in source order.');
$container = $document->getElementsByTagName('div')->item(1);
$assert(SourceDom::elementSelector($container) === $row['source_selector'], 'The row identifies the shared form-increment container.');
$children = array();
foreach ($container->childNodes as $child) {
    if ($child instanceof DOMElement) $children[] = $child;
}
$assert(array_map(SourceDom::elementSelector(...), $children) === array_column($row['members'], 'source_selector'), 'Members identify the three adjacent children in source order.');
FormPresentationGraphBuilder::assertValid($graph);
echo "form presentation inline control row passed\n";
