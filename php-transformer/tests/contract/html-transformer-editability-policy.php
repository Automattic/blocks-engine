<?php
declare(strict_types=1);

/**
 * Self-consistency gate: a converter must never emit blocks the engine's own
 * `Contract\EditabilityPolicy` rejects.
 *
 * `HtmlTransformer` — the entry point every `html_transformer.transform`
 * parity fixture drives — never evaluates `EditabilityPolicy` against its own
 * output. Only `ArtifactCompiler` (a real site compile) does. That gap let
 * PR #1992 ("lower safe inline buttons into paragraph RichText") pass all 319
 * parity fixtures while shipping blocks `EditabilityPolicy`'s zero-tolerance
 * `structural_rich_text_attribute_count` threshold rejects outright — a
 * regression invisible to the fixture suite and caught only by a real
 * end-to-end import, where it aborted materialization entirely (PR #1993,
 * the revert).
 *
 * This test closes that gap generically, for every converter, not just the
 * one that regressed: it replays every `html_transformer.transform` parity
 * fixture, measures an `EditabilityReport` over the blocks each one produces,
 * and fails the build if `EditabilityPolicy::evaluate()` ever returns
 * `status: failed`. A parity fixture proves a converter's shape is correct;
 * this proves that shape is also something the engine's own downstream
 * policy — the one a real compile enforces before a consumer ever sees a
 * theme — will accept.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$fixtureDir = dirname(__DIR__) . '/fixtures/parity';
$fixtures   = glob($fixtureDir . '/*.json');
$assert(false !== $fixtures && array() !== $fixtures, 'Parity fixtures are present to gate.');

$checked  = 0;
$failures = array();

foreach ($fixtures as $fixturePath) {
    $fixture = json_decode((string) file_get_contents($fixturePath), true);
    if (! is_array($fixture) || 'html_transformer.transform' !== ($fixture['operation'] ?? '')) {
        continue;
    }

    $input   = is_array($fixture['input'] ?? null) ? $fixture['input'] : array();
    $options = is_array($input['options'] ?? null) ? $input['options'] : array();
    $result  = ( new HtmlTransformer() )->transform((string) ($input['content'] ?? ''), $options)->toArray();
    ++$checked;

    $report = ( new EditabilityReport() )->fromBlocks(
        is_array($result['blocks'] ?? null) ? $result['blocks'] : array(),
        (string) ($fixture['name'] ?? $fixturePath),
        (string) ($result['serialized_blocks'] ?? '')
    );
    $policy = ( new EditabilityPolicy() )->evaluate($report);

    if ('failed' === ($policy['status'] ?? null)) {
        $failures[] = sprintf(
            '%s: %s',
            (string) ($fixture['name'] ?? $fixturePath),
            json_encode($policy['failures'], JSON_UNESCAPED_SLASHES)
        );
    }
}

$assert(0 < $checked, 'At least one html_transformer.transform parity fixture was measured against the editability policy.');

if (array() !== $failures) {
    throw new RuntimeException(
        "The following parity fixture(s) produce blocks the engine's own EditabilityPolicy rejects "
        . '— a converter change that ships this is a materialization-aborting regression, exactly like '
        . "PR #1992/#1993, even though every parity assertion can still pass:\n"
        . implode("\n", $failures)
    );
}

echo 'HTML transformer editability policy gate: ' . $checked . " fixture(s) checked, all passed.\n";
