<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$page = static fn (string $body): string => '<!doctype html><html><head><link rel="stylesheet" href="/source.css"></head><body><main>' . $body . '</main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('<form class="contact-form"><label>Name <input name=n></label><button>Send</button></form><a href="/newsletter/">Newsletter</a>'),
    'newsletter/index.html' => $page('<form class="newsletter"><label><input type=checkbox name=c><span>I agree</span></label><button>Join</button></form>'),
    'source.css' => '.contact-form label{font-weight:800}',
)))->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$marker = EngineMarker::subjectClassClass('contact-form');
preg_match_all('/([^{}]+)\{[^{}]*font-weight:800[^{}]*\}/', $css, $rules);
$assert(array() !== $rules[1], 'The author font-weight rule survives projection.');
foreach ($rules[1] as $selector) {
    $assert(!str_starts_with(trim($selector), '.contact-form '), 'The projected ancestor must not use the raw host-colliding class.');
    $assert(str_contains($selector, '.' . $marker), 'The projected ancestor uses source class identity.');
}
$pages = array_column($result['source_reports']['wordpress_site_plan']['pages'] ?? array(), null, 'source_path');
$a = (string) ($pages['index.html']['canonical_block_markup'] ?? '');
$b = (string) ($pages['newsletter/index.html']['canonical_block_markup'] ?? '');
$assert(1 === preg_match('/<[^>]+class="[^"]*' . preg_quote($marker, '/') . '[^"]*"[^>]*>/', $a), 'Page A emitted form wrapper carries its source class marker.');
$assert(str_contains($a, '"tagName":"form"') && str_contains($b, '"tagName":"form"'), 'Both source forms retain emitted form wrappers.');
$assert(!str_contains($b, $marker), 'The newsletter wrapper does not acquire the contact form identity.');
$newsletter = (new HtmlTransformer())->transform('<style>.contact-form label{font-weight:800}.contact-form{margin-top:2rem}</style><form class="newsletter"><label>Email<input type="email" name="email"></label><button>Join</button></form>')->toArray();
$newsletterCss = implode("\n", array_column($newsletter['assets'] ?? array(), 'content'));
$assert(!preg_match('/\.contact-form(?:[:{\s])/', $newsletterCss), 'A page with no source occurrence cannot leak through its runtime form.');
$assert(!str_contains((string) ($newsletter['serialized_blocks'] ?? ''), $marker), 'A runtime form does not invent source identity.');
// Subject-position collisions must be isolated too; ordinary ancestor classes
// on the same provider-bound page retain their readable selector and markup.
$subject = (new HtmlTransformer())->transform('<style>.contact-form{margin-top:2rem}.panel label{font-weight:700}</style><div class="panel"><form class="contact-form"><label>Name<input name="n"></label><button>Send</button></form><form><label>Email<input type="email" name="e"></label><button>Join</button></form></div>')->toArray();
$subjectCss = implode("\n", array_column($subject['assets'] ?? array(), 'content'));
$assert(str_contains($subjectCss, '.' . $marker), 'A host-colliding subject class binds to source identity.');
$assert(!preg_match('/\.contact-form(?:[:{\s])/', $subjectCss), 'No raw host-colliding subject selector survives.');
$assert(str_contains($subjectCss, '.panel '), 'Unrelated ancestor selectors remain readable on provider pages.');
$assert(!str_contains((string) ($subject['serialized_blocks'] ?? ''), EngineMarker::subjectClassClass('panel')), 'Unrelated source classes receive no identity markup.');
$ordinary = (new HtmlTransformer())->transform('<style>.contact-form label{font-weight:800}</style><div class="contact-form"><label>Name</label></div>')->toArray();
$ordinaryCss = implode("\n", array_column($ordinary['assets'] ?? array(), 'content'));
$assert(str_contains($ordinaryCss, '.contact-form '), 'Without a materializable form even contact-form stays readable.');
$assert(!str_contains((string) ($ordinary['serialized_blocks'] ?? ''), $marker), 'Without a materializable host no extra identity is emitted.');
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Ancestor class source identity contract passed.\n";
