<?php
declare(strict_types=1);

// Unqualified class references must still resolve after a file moves.
//
// PHP resolves a bare `Foo` inside namespace `N` to `N\Foo`. That works
// silently while the two share a namespace and breaks the moment either
// moves — with no import to update, nothing for a linter to flag, and a
// failure that only appears when the line executes.
//
// Decomposition work under #242 hit this three times in one session:
//
//   * a moved file referencing a class left behind (`ShellLandmarkPolicy`)
//   * a moved file type-hinting one (`GeneratedBlockRegistry`) — invisible to
//     the parity fixtures, caught only because `runtime-no-markdown` happens
//     to exercise a degraded autoload path
//   * seven references in `HtmlTransformer` that needed imports once the
//     classes moved out from under it
//
// This makes that class of breakage a static failure. It reports two things:
//
//   1. an import naming a package class that does not exist
//   2. a bare reference to a name that is not in the file's own namespace but
//      is a real class somewhere else in the package
//
// Both are decided against the package's own class map, so a bare `DOMElement`
// or `RuntimeException` is never considered — those are not names this package
// defines, and guessing about globals is how a check like this turns into
// noise someone silences.

$root = dirname(__DIR__, 2);
// dev/ shares the package namespace but is mapped through autoload-dev, so it
// is subject to exactly the same bare-reference breakage as src/ and is scanned
// with it. Keeping both roots here is what stops the harness from silently
// losing this guarantee when it moved out of src/.
$roots = array( $root . '/src', $root . '/dev' );
$prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\';

/** @return iterable<string> */
$phpFiles = static function (string $dir): iterable {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && 'php' === $file->getExtension()) {
            yield $file->getPathname();
        }
    }
};

/**
 * @param list<string> $dirs
 * @return iterable<string>
 */
$allFiles = static function (array $dirs) use ($phpFiles): iterable {
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        yield from $phpFiles($dir);
    }
};

$declaredIn = static function (array $tokens): array {
    $namespace = '';
    $classes = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if (T_NAMESPACE === $t[0]) {
            $ns = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR), true)) {
                    $ns .= $tokens[$j][1];
                    continue;
                }
                if (is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
                    continue;
                }
                break;
            }
            // `namespace` is also legal as a method name, which tokenizes the
            // same way. A real declaration is always followed by a name.
            $ns = trim($ns, '\\');
            if ('' !== $ns) {
                $namespace = $ns;
            }
            continue;
        }
        if (in_array($t[0], array(T_CLASS, T_INTERFACE, T_TRAIT), true) || (defined('T_ENUM') && T_ENUM === $t[0])) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
                    continue;
                }
                if (is_array($tokens[$j]) && T_STRING === $tokens[$j][0]) {
                    $classes[] = $tokens[$j][1];
                }
                break;
            }
        }
    }

    return array($namespace, $classes);
};

// Pass one: every class-like this package declares.
$declared = array();       // FQN => true
$shortNames = array();     // short name => list of FQN
foreach ($allFiles($roots) as $path) {
    $tokens = token_get_all((string) file_get_contents($path));
    list($ns, $classes) = $declaredIn($tokens);
    foreach ($classes as $class) {
        $fqn = '' === $ns ? $class : $ns . '\\' . $class;
        $declared[$fqn] = true;
        $shortNames[$class][] = $fqn;
    }
}

$reserved = array(
    'self', 'static', 'parent', 'array', 'string', 'int', 'bool', 'float', 'void',
    'mixed', 'callable', 'iterable', 'object', 'null', 'false', 'true', 'never', 'class',
);

$problems = array();

foreach ($allFiles($roots) as $path) {
    $tokens = token_get_all((string) file_get_contents($path));
    $count = count($tokens);
    $rel = substr($path, strlen($root) + 1);

    list($namespace) = $declaredIn($tokens);
    $imports = array();   // short/alias => FQN
    $refs = array();      // short name => first line seen

    $depth = 0;
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];

        if (!is_array($t)) {
            if ('{' === $t) {
                $depth++;
            } elseif ('}' === $t) {
                $depth--;
            }
            continue;
        }

        // Top-level `use` is an import; inside a class body it names a trait.
        if (T_USE === $t[0] && 0 === $depth) {
            $fqn = '';
            $alias = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $n = $tokens[$j];
                if (!is_array($n)) {
                    if (';' === $n || '(' === $n) {
                        break;
                    }
                    continue;
                }
                if (T_AS === $n[0]) {
                    for ($k = $j + 1; $k < $count; $k++) {
                        if (is_array($tokens[$k]) && T_STRING === $tokens[$k][0]) {
                            $alias = $tokens[$k][1];
                            break;
                        }
                    }
                    break;
                }
                if (in_array($n[0], array(T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR), true)) {
                    $fqn .= $n[1];
                }
            }
            $fqn = trim($fqn, '\\');
            if ('' !== $fqn && !str_contains($fqn, '{')) {
                $short = '' !== $alias ? $alias : substr($fqn, (int) strrpos($fqn, '\\') + 1);
                $imports[$short] = $fqn;
            }
            continue;
        }

        if (T_STRING !== $t[0]) {
            continue;
        }

        $name = $t[1];
        if (in_array(strtolower($name), $reserved, true)) {
            continue;
        }

        $prev = null;
        for ($p = $i - 1; $p >= 0; $p--) {
            if (is_array($tokens[$p]) && T_WHITESPACE === $tokens[$p][0]) {
                continue;
            }
            $prev = $tokens[$p];
            break;
        }
        $next = null;
        for ($n2 = $i + 1; $n2 < $count; $n2++) {
            if (is_array($tokens[$n2]) && T_WHITESPACE === $tokens[$n2][0]) {
                continue;
            }
            $next = $tokens[$n2];
            break;
        }

        // A name qualified at the point of use resolves on its own.
        if (is_array($prev) && T_NS_SEPARATOR === $prev[0]) {
            continue;
        }
        if (is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST), true)) {
            continue;
        }
        if (is_array($prev) && in_array($prev[0], array(T_CLASS, T_INTERFACE, T_TRAIT), true)) {
            continue; // its own declaration
        }

        $isRef = false;
        if (is_array($prev) && in_array($prev[0], array(T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS), true)) {
            $isRef = true;
        } elseif (is_array($prev) && T_USE === $prev[0] && $depth > 0) {
            $isRef = true; // trait use inside a class body
        } elseif (is_array($next) && T_DOUBLE_COLON === $next[0]) {
            $isRef = true;
        } elseif (is_array($next) && T_VARIABLE === $next[0]) {
            $isRef = true; // parameter or typed property
        } elseif (is_array($prev) && ':' === ($prev[1] ?? '') ) {
            $isRef = true; // return type
        } elseif (!is_array($prev) && ':' === $prev) {
            $isRef = true; // return type
        } elseif (is_array($prev) && '?' === ($prev[1] ?? '')) {
            $isRef = true;
        } elseif (!is_array($prev) && '?' === $prev) {
            $isRef = true;
        }

        if ($isRef && !isset($refs[$name])) {
            $refs[$name] = $t[2];
        }
    }

    // 1. An import that names a package class which does not exist.
    foreach ($imports as $short => $fqn) {
        if (!str_starts_with($fqn, $prefix)) {
            continue;
        }
        if (!isset($declared[$fqn])) {
            $problems[] = sprintf('%s: imports %s, which this package does not declare.', $rel, $fqn);
        }
    }

    // 2. A bare reference that does not resolve in this file's namespace but
    //    names a real class elsewhere in the package.
    foreach ($refs as $name => $line) {
        if (isset($imports[$name])) {
            continue;
        }
        $own = '' === $namespace ? $name : $namespace . '\\' . $name;
        if (isset($declared[$own])) {
            continue;
        }
        if (!isset($shortNames[$name])) {
            continue; // not a class this package defines; global or vendor
        }
        $problems[] = sprintf(
            '%s:%d: references %s with no import. It is not in %s; the package declares it as %s.',
            $rel,
            $line,
            $name,
            '' === $namespace ? '(global namespace)' : $namespace,
            implode(', ', $shortNames[$name])
        );
    }
}

if (array() !== $problems) {
    sort($problems);
    fwrite(STDERR, "Unresolvable class references:\n\n" . implode("\n", $problems) . "\n\n");
    fwrite(STDERR, "Each of these resolves to a class that does not exist. Add the missing\n");
    fwrite(STDERR, "import, or correct the stale one.\n");
    exit(1);
}

printf("Namespace resolution contract passed: %d class-likes declared, no unresolvable references.\n", count($declared));
