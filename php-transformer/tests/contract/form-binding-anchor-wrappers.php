<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * A form provider replaces a form binding's `search_block_markup` wholesale.
 * When the form sits in layout wrappers (`.grid > .cell > .center > form`),
 * the binding must anchor on a block that holds only the form: neither
 * coalescing a wrapper into the form block nor folding the form into the
 * wrappers' layout shell may happen, or the replacement deletes the wrappers.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

$page = static fn (string $wrapperAttributes): string => <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Form test</title>
<style>
body { margin: 0; background: #222; color: #ddd; font-family: sans-serif; }
.field { padding: 0.5em; width: 100%; box-sizing: border-box; border: 1px solid #aaa; }
@media (min-width: 1280px) {
  .center { text-align: center; }
  .grid { display: flex; }
  .cell { flex-grow: 1; flex-basis: 66.6667%; max-width: 66.6667%; margin-left: 16.6667%; }
}
</style></head>
<body><section><h2>Contact</h2>
<div class="grid"><div class="cell"><div class="center"{$wrapperAttributes}>
<form>
  <h4>Write to us</h4>
  <div><input class="field" id="f-name" type="text"><label for="f-name">Name</label></div>
  <div><input class="field" id="f-email" type="text"><label for="f-email">Email*</label></div>
  <div><textarea class="field" aria-label="Message" placeholder="Message" rows="4"></textarea></div>
  <div><button type="submit">Send</button></div>
</form>
</div></div></div>
</section></body></html>
HTML;

// The plain fixture coalesces `.center` into the form block; a data
// attribute keeps `.center` a wrapper, so the chain folds into one shell.
foreach (array('coalesced wrapper' => '', 'folded wrapper chain' => ' data-role="form-box"') as $case => $attributes) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page($attributes))))->toArray();
    $forms = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? null)));
    $assert(1 === count($forms), "[$case] The form is reported once.");
    $search = (string) ($forms[0]['binding']['search_block_markup'] ?? '');
    $assert(str_starts_with($search, '<!-- wp:group {"tagName":"form"'), "[$case] The form binding anchors on the form's own block: " . substr($search, 0, 160));
    foreach (array('grid', 'cell', 'center') as $class) {
        $assert(! preg_match('/class="[^"]*\b' . $class . '\b/', $search), "[$case] The binding's markup excludes the .$class wrapper.");
    }

    $markup = (string) ($result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '');
    $assert(1 === substr_count($markup, $search), "[$case] The binding's markup is emitted exactly once on the page.");
    $replaced = str_replace($search, '<!-- wp:provider/form /-->', $markup);
    $assert(1 === preg_match('/class="[^"]*\bgrid\b.*class="[^"]*\bcell\b.*class="[^"]*\bcenter\b[^"]*">\s*<!-- wp:provider\/form \/-->/s', $replaced), "[$case] Replacing the binding keeps the grid, cell and center wrappers around it.");
}

echo "Form binding anchor wrappers contract passed.\n";
