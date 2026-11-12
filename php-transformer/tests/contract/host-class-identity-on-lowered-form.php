<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$css = '.contact-form{gap:1rem;margin-top:2rem;display:grid}.contact-form label{font-weight:800}';
$form = '<main><form class="contact-form" action="/thanks/" method="post"><label>Name <input name="name" required></label><label>Message <textarea name="message"></textarea></label><button type="submit">Send</button></form></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="/source.css"></head><body>' . $form . '</body></html>',
    'source.css' => $css,
)))->toArray();
$pages = array_column($result['source_reports']['wordpress_site_plan']['pages'] ?? array(), null, 'source_path');
$markup = (string) ($pages['index.html']['canonical_block_markup'] ?? '');
$marker = EngineMarker::subjectClassClass('contact-form');
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$countMatches = static function (string $selector) use ($dom): int {
    $parsed = CssSelectorMatcher::parse($selector);
    $count = 0;
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (CssSelectorMatcher::matches($element, $parsed)['matches']) ++$count;
    }
    return $count;
};
$assert(1 === $countMatches('.' . $marker), 'The lowered form markup carries exactly one source class identity.');
// PHP preserves this POST form as core/html; a provider may substitute it
// later. Test the actual emitted carrier, not an assumed provider wrapper.
$assert(1 === $countMatches('form.' . $marker), 'Identity lands on the rendered form, not just block attributes.');
$emittedCss = implode("\n", array_column($result['assets'] ?? array(), 'content'));
foreach (array('margin-top:2rem' => 1, 'font-weight:800' => 2) as $declaration => $matches) {
    preg_match_all('/([^{}]+)\{[^{}]*' . preg_quote($declaration, '/') . '[^{}]*\}/', $emittedCss, $rules);
    $assert(array() !== $rules[1], 'The author ' . $declaration . ' rule survives.');
    foreach ($rules[1] as $selectors) {
        foreach (explode(',', $selectors) as $selector) {
            $assert(!preg_match('/\.contact-form(?![a-zA-Z0-9_-])/', $selector), 'No raw host class survives in ' . $selector);
            $assert(str_contains($selector, '.' . $marker), 'The selector binds source identity: ' . $selector);
            $assert($matches === $countMatches(trim($selector)), 'The marker-bound selector matches the lowered markup: ' . $selector);
        }
    }
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Lowered form host class identity contract passed.\n";
