<?php
declare(strict_types=1);

/**
 * Throw classification guard (Automattic/blocks-engine#2713).
 *
 * Every `throw new` in src/ and every call to a throwing helper must be
 * classified in tests/contract/fixtures/throw-classification.json, so a new
 * limit cannot fail a whole import by accident. See
 * docs/contracts/throw-classification.md for how to classify a site.
 *
 * - Sites are keyed by relative file, then `Class(<normalized message>)` for a
 *   throw or `helper(<normalized arguments>)` for a throwing-helper call, with
 *   an occurrence count. Line numbers never appear in the key.
 * - `LogicException` and project classes extending it are integrity by
 *   construction and are not listed. PHP's SPL subclasses of LogicException
 *   (InvalidArgumentException, DomainException, ...) are listed like any other
 *   class, because InvalidArgumentException is this library's generic
 *   validation throw.
 * - A representability entry names its unit and finding code, plus either the
 *   test that proves the finding (`test`) or `pending: true`.
 * - `representability_pending_max` equals the pending site count and may only
 *   shrink.
 *
 * Usage, from php-transformer/:
 *   php tests/contract/throw-classification.php          # check
 *   php tests/contract/throw-classification.php --list   # every site with its line
 */

const THROW_CLASSIFICATION_SCHEMA = 'blocks-engine/php-transformer/throw-classification/v1';
const THROW_CLASSIFICATION_CLASSES = array('integrity', 'operational', 'representability', 'programmer');
const THROW_CLASSIFICATION_UNITS = array('item', 'binding', 'block', 'asset', 'page', 'route');

/**
 * Methods that throw on behalf of their caller, by declaring file, with the
 * exception class they throw. The call site carries the classification
 * because its arguments decide the message. The guard fails when one of these
 * disappears or when a new `: never` method appears outside this list.
 */
const THROW_CLASSIFICATION_HELPERS = array(
    'src/WordPressSitePlan/WordPressSitePlan.php' => array(
        'invalidDeclaration' => 'ValidationException',
        'unique' => 'InvalidArgumentException',
    ),
);

/**
 * @param list<array{0:int|string,1:string,2:int}> $tokens
 */
function throw_classification_normalize(array $tokens): string
{
    $out = '';
    foreach ($tokens as $token) {
        $text = $token[1];
        if ('' !== $out && preg_match('/[\w$]$/', $out) && preg_match('/^[\w$]/', $text)) {
            $out .= ' ';
        }
        $out .= $text;
    }

    return $out;
}

/**
 * Tokens of a balanced argument list starting at the `(` at $open.
 *
 * @param list<array{0:int|string,1:string,2:int}> $tokens
 * @return array{0:list<array{0:int|string,1:string,2:int}>,1:list<array{0:int|string,1:string,2:int}>} All argument tokens, first argument tokens.
 */
function throw_classification_arguments(array $tokens, int $open): array
{
    $all = array();
    $first = array();
    $depth = 0;
    $inFirst = true;
    $count = count($tokens);
    for ($j = $open + 1; $j < $count; $j++) {
        $text = $tokens[$j][1];
        if (in_array($text, array('(', '[', '{'), true) || in_array($tokens[$j][0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true)) {
            ++$depth;
        } elseif (in_array($text, array(')', ']', '}'), true)) {
            if (0 === $depth) {
                break;
            }
            --$depth;
        } elseif (',' === $text && 0 === $depth) {
            $inFirst = false;
        }
        $all[] = $tokens[$j];
        if ($inFirst) {
            $first[] = $tokens[$j];
        }
    }

    return array($all, $first);
}

/**
 * @return array{sites:list<array{file:string,line:int,class:string,key:string}>,never:list<string>,extends:array<string,string>}
 */
function throw_classification_file(string $relative, string $source): array
{
    $tokens = array();
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $tokens[] = $token;
        } else {
            $tokens[] = array($token, $token, 0);
        }
    }
    $line = 1;
    foreach ($tokens as $index => $token) {
        if (0 === $token[2]) {
            $tokens[$index][2] = $line;
        }
        $line = $tokens[$index][2];
    }

    $helpers = THROW_CLASSIFICATION_HELPERS[$relative] ?? array();
    $sites = array();
    $never = array();
    $extends = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $id = $tokens[$i][0];
        if (T_THROW === $id && T_NEW === ($tokens[$i + 1][0] ?? null)) {
            $class = ltrim((string) $tokens[$i + 2][1], '\\');
            $class = substr($class, (int) strrpos('\\' . $class, '\\'));
            $first = '(' === ($tokens[$i + 3][1] ?? null) ? throw_classification_arguments($tokens, $i + 3)[1] : array();
            $sites[] = array('file' => $relative, 'line' => $tokens[$i][2], 'class' => $class, 'key' => $class . '(' . throw_classification_normalize($first) . ')');
        } elseif (T_STRING === $id && isset($helpers[$tokens[$i][1]]) && '(' === ($tokens[$i + 1][1] ?? null) && T_DOUBLE_COLON === ($tokens[$i - 1][0] ?? null)) {
            $all = throw_classification_arguments($tokens, $i + 1)[0];
            $sites[] = array('file' => $relative, 'line' => $tokens[$i][2], 'class' => $helpers[$tokens[$i][1]], 'key' => $tokens[$i][1] . '(' . throw_classification_normalize($all) . ')');
        } elseif (T_FUNCTION === $id && T_STRING === ($tokens[$i + 1][0] ?? null) && '(' === ($tokens[$i + 2][1] ?? null)) {
            $close = $i + 2;
            for ($depth = 0; $close < $count; $close++) {
                $depth += '(' === $tokens[$close][1] ? 1 : (')' === $tokens[$close][1] ? -1 : 0);
                if (0 === $depth) {
                    break;
                }
            }
            if (':' === ($tokens[$close + 1][1] ?? null) && 'never' === strtolower((string) ($tokens[$close + 2][1] ?? ''))) {
                $never[] = $tokens[$i + 1][1];
            }
        } elseif (T_CLASS === $id && T_STRING === ($tokens[$i + 1][0] ?? null) && T_EXTENDS === ($tokens[$i + 2][0] ?? null)) {
            $parent = ltrim((string) $tokens[$i + 3][1], '\\');
            $extends[$tokens[$i + 1][1]] = substr($parent, (int) strrpos('\\' . $parent, '\\'));
        }
    }

    return array('sites' => $sites, 'never' => $never, 'extends' => $extends);
}

/**
 * @return array{sites:list<array{file:string,line:int,class:string,key:string}>,never:array<string,list<string>>,extends:array<string,string>}
 */
function throw_classification_scan(string $root): array
{
    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($files);
    $sites = array();
    $never = array();
    $extends = array();
    foreach ($files as $relative) {
        $scan = throw_classification_file($relative, (string) file_get_contents($root . '/' . $relative));
        array_push($sites, ...$scan['sites']);
        if (array() !== $scan['never']) {
            $never[$relative] = $scan['never'];
        }
        $extends += $scan['extends'];
    }

    return array('sites' => $sites, 'never' => $never, 'extends' => $extends);
}

/**
 * @param array<string,string> $extends
 */
function throw_classification_is_logic(string $class, array $extends): bool
{
    for ($depth = 0; $depth < 20; $depth++) {
        if ('LogicException' === $class) {
            return true;
        }
        if (!isset($extends[$class])) {
            return false;
        }
        $class = $extends[$class];
    }

    return false;
}

function throw_classification_subsystem(string $file): string
{
    $parts = explode('/', $file);
    if ('HtmlToBlocks' === ($parts[1] ?? '') && 'Style' === ($parts[2] ?? '')) {
        return 'HtmlToBlocks/Style';
    }

    return 2 < count($parts) ? $parts[1] : '(root)';
}

/**
 * A finding code with the test that compiles to it: the test exists, names the
 * code, and runs in `composer test`.
 *
 * @param array<string,mixed> $entry
 * @return list<string>
 */
function throw_classification_check_test(string $label, array $entry, string $root, string $composer): array
{
    $finding = $entry['finding'] ?? null;
    $test = $entry['test'] ?? null;
    if (!is_string($finding) || !preg_match('/^[a-z][a-z0-9_]*$/', $finding)) {
        return array(sprintf('%s: finding must be a snake_case code', $label));
    }
    if (!is_string($test) || !is_file($root . '/' . $test)) {
        return array(sprintf('%s: test %s does not exist', $label, json_encode($test, JSON_UNESCAPED_SLASHES)));
    }
    $failures = array();
    if (!str_contains((string) file_get_contents($root . '/' . $test), $finding)) {
        $failures[] = sprintf('%s: test %s never mentions finding %s', $label, $test, $finding);
    }
    if (!str_contains($composer, '"php ' . $test . '"')) {
        $failures[] = sprintf('%s: test %s is not registered in composer.json', $label, $test);
    }

    return $failures;
}

/**
 * @param array{sites:list<array{file:string,line:int,class:string,key:string}>,never:array<string,list<string>>,extends:array<string,string>} $scan
 * @param array<string,mixed> $baseline
 * @return array{failures:list<string>,classified:list<array{file:string,line:int,class:string,key:string,classification:string,pending:bool}>}
 */
function throw_classification_check(array $scan, array $baseline, string $root): array
{
    $failures = array();
    if (THROW_CLASSIFICATION_SCHEMA !== ($baseline['schema'] ?? null)) {
        $failures[] = 'fixture schema must be ' . THROW_CLASSIFICATION_SCHEMA;
    }
    $entries = is_array($baseline['sites'] ?? null) ? $baseline['sites'] : array();
    $composer = (string) file_get_contents($root . '/composer.json');

    // Throwing helpers: every configured helper exists, and every `: never` method is configured.
    foreach (THROW_CLASSIFICATION_HELPERS as $file => $helpers) {
        $source = is_file($root . '/' . $file) ? (string) file_get_contents($root . '/' . $file) : '';
        foreach (array_keys($helpers) as $helper) {
            if (!preg_match('/function\s+' . preg_quote($helper, '/') . '\s*\(/', $source)) {
                $failures[] = sprintf('throwing helper %s::%s() no longer exists; update THROW_CLASSIFICATION_HELPERS', $file, $helper);
            }
        }
    }
    foreach ($scan['never'] as $file => $methods) {
        foreach ($methods as $method) {
            if (!isset(THROW_CLASSIFICATION_HELPERS[$file][$method])) {
                $failures[] = sprintf('%s::%s() returns never, so it is a throwing helper; add it to THROW_CLASSIFICATION_HELPERS and classify its calls', $file, $method);
            }
        }
    }

    // Validate entries.
    $pendingMax = $baseline['representability_pending_max'] ?? null;
    foreach ($entries as $file => $keys) {
        foreach (is_array($keys) ? $keys : array() as $key => $entry) {
            $label = $file . ' ' . json_encode((string) $key, JSON_UNESCAPED_SLASHES);
            $class = $entry['class'] ?? null;
            if (!in_array($class, THROW_CLASSIFICATION_CLASSES, true)) {
                $failures[] = sprintf('%s: class must be one of %s', $label, implode('|', THROW_CLASSIFICATION_CLASSES));
                continue;
            }
            if (!is_int($entry['count'] ?? null) || 1 > $entry['count']) {
                $failures[] = sprintf('%s: count must be a positive integer', $label);
            }
            $exception = (string) strstr((string) $key, '(', true);
            if (throw_classification_is_logic($exception, $scan['extends'])) {
                $failures[] = sprintf('%s: %s is integrity by construction; remove the entry', $label, $exception);
            }
            $unknown = array_diff(array_keys(is_array($entry) ? $entry : array()), array('class', 'count', 'unit', 'finding', 'test', 'pending'));
            if (array() !== $unknown) {
                $failures[] = sprintf('%s: unknown fields %s', $label, implode(', ', $unknown));
            }
            if ('representability' !== $class) {
                // An integrity backstop may name the finding its upstream
                // builder degrades to, with the test that proves it.
                $allowed = 'integrity' === $class ? array('unit', 'pending') : array('unit', 'finding', 'test', 'pending');
                foreach ($allowed as $field) {
                    if (isset($entry[$field])) {
                        $failures[] = sprintf('%s: %s does not apply to %s', $label, $field, $class);
                    }
                }
                if ('integrity' === $class && (isset($entry['finding']) || isset($entry['test']))) {
                    if (!isset($entry['finding'], $entry['test'])) {
                        $failures[] = sprintf('%s: an integrity backstop names both finding and test', $label);
                    } else {
                        array_push($failures, ...throw_classification_check_test($label, $entry, $root, $composer));
                    }
                }
                continue;
            }
            if (!in_array($entry['unit'] ?? null, THROW_CLASSIFICATION_UNITS, true)) {
                $failures[] = sprintf('%s: representability needs unit %s', $label, implode('|', THROW_CLASSIFICATION_UNITS));
            }
            $pending = true === ($entry['pending'] ?? null);
            if (isset($entry['pending']) && !$pending) {
                $failures[] = sprintf('%s: pending is either true or absent', $label);
            }
            if (!isset($entry['finding'])) {
                $failures[] = sprintf('%s: representability needs a finding code', $label);
            } elseif ($pending === isset($entry['test'])) {
                $failures[] = sprintf('%s: representability needs exactly one of test or pending: true', $label);
            } elseif (!$pending) {
                array_push($failures, ...throw_classification_check_test($label, $entry, $root, $composer));
            } elseif (!is_string($entry['finding']) || !preg_match('/^[a-z][a-z0-9_]*$/', $entry['finding'])) {
                $failures[] = sprintf('%s: finding must be a snake_case code', $label);
            }
        }
    }

    // Match live sites against entries.
    $live = array();
    foreach ($scan['sites'] as $site) {
        $live[$site['file']][$site['key']][] = $site;
    }
    $classified = array();
    $pendingCount = 0;
    foreach ($live as $file => $keys) {
        foreach ($keys as $key => $sites) {
            $entry = $entries[$file][$key] ?? null;
            if (throw_classification_is_logic($sites[0]['class'], $scan['extends'])) {
                foreach ($sites as $site) {
                    $classified[] = $site + array('classification' => 'integrity', 'pending' => false);
                }
                continue;
            }
            $allowed = is_array($entry) && is_int($entry['count'] ?? null) ? $entry['count'] : 0;
            foreach ($sites as $index => $site) {
                if ($index >= $allowed) {
                    $failures[] = sprintf('%s:%d: unclassified throw site %s; classify it in tests/contract/fixtures/throw-classification.json (see docs/contracts/throw-classification.md)', $file, $site['line'], json_encode($key, JSON_UNESCAPED_SLASHES));
                    continue;
                }
                $pending = 'representability' === ($entry['class'] ?? null) && true === ($entry['pending'] ?? null);
                $pendingCount += $pending ? 1 : 0;
                $classified[] = $site + array('classification' => (string) $entry['class'], 'pending' => $pending);
            }
            if ($allowed > count($sites)) {
                $failures[] = sprintf('stale entry %s %s: count %d, found %d; lower or remove it', $file, json_encode($key, JSON_UNESCAPED_SLASHES), $allowed, count($sites));
            }
        }
    }
    foreach ($entries as $file => $keys) {
        foreach (is_array($keys) ? $keys : array() as $key => $entry) {
            if (!isset($live[$file][$key])) {
                $failures[] = sprintf('stale entry %s %s: the site no longer exists; remove it', $file, json_encode((string) $key, JSON_UNESCAPED_SLASHES));
            }
        }
    }

    // Pending ratchet.
    if (!is_int($pendingMax)) {
        $failures[] = 'representability_pending_max must be an integer';
    } elseif ($pendingCount > $pendingMax) {
        $failures[] = sprintf('representability ratchet: %d pending sites exceed representability_pending_max %d. Degrade the new representability site to a finding (with a test) instead of failing the import.', $pendingCount, $pendingMax);
    } elseif ($pendingCount < $pendingMax) {
        $failures[] = sprintf('representability ratchet: %d pending sites, below representability_pending_max %d; lower it to %d so the list can only shrink', $pendingCount, $pendingMax, $pendingCount);
    }

    return array('failures' => $failures, 'classified' => $classified);
}

$root = dirname(__DIR__, 2);
$scan = throw_classification_scan($root);

if (in_array('--list', $argv, true)) {
    foreach ($scan['sites'] as $site) {
        echo json_encode($site, JSON_UNESCAPED_SLASHES), "\n";
    }
    exit(0);
}

$baseline = json_decode((string) file_get_contents(__DIR__ . '/fixtures/throw-classification.json'), true);
$result = throw_classification_check($scan, is_array($baseline) ? $baseline : array(), $root);

$table = array();
$totals = array_fill_keys(THROW_CLASSIFICATION_CLASSES, 0);
$pending = 0;
foreach ($result['classified'] as $site) {
    $subsystem = throw_classification_subsystem($site['file']);
    $table[$subsystem] ??= array_fill_keys(THROW_CLASSIFICATION_CLASSES, 0);
    ++$table[$subsystem][$site['classification']];
    ++$totals[$site['classification']];
    $pending += $site['pending'] ? 1 : 0;
}
ksort($table);
printf("throw sites: %d (%d classified)\n", count($scan['sites']), count($result['classified']));
printf("  %-30s %9s %11s %16s %10s\n", 'subsystem', 'integrity', 'operational', 'representability', 'programmer');
foreach ($table + array('total' => $totals) as $subsystem => $row) {
    printf("  %-30s %9d %11d %16d %10d\n", $subsystem, $row['integrity'], $row['operational'], $row['representability'], $row['programmer']);
}
printf("representability: %d converted, %d pending (max %s)\n", $totals['representability'] - $pending, $pending, json_encode($baseline['representability_pending_max'] ?? null));

if (array() !== $result['failures']) {
    foreach ($result['failures'] as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "throw classification guard passed\n";
