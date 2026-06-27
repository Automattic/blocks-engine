<?php
declare(strict_types=1);

$root = __DIR__;
$manifestPath = $root . '/website-product-class-manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);

if ( ! is_array($manifest) ) {
    fwrite(STDERR, "Could not decode {$manifestPath}.\n");
    exit(1);
}

$expectedSchema = 'blocks-engine/fixtures/website-product-class-manifest/v1';
if ( $expectedSchema !== ($manifest['schema'] ?? null) ) {
    fwrite(STDERR, "Unexpected manifest schema.\n");
    exit(1);
}

$fixtures = $manifest['fixtures'] ?? null;
if ( ! is_array($fixtures) ) {
    fwrite(STDERR, "Manifest fixtures must be a list.\n");
    exit(1);
}

$ids = array();
$classCounts = array();
$errors = array();

foreach ( $fixtures as $index => $fixture ) {
    if ( ! is_array($fixture) ) {
        $errors[] = "Fixture at index {$index} must be an object.";
        continue;
    }

    foreach ( array('id', 'current_path', 'product_class', 'intended_path', 'entrypoint') as $key ) {
        if ( ! isset($fixture[$key]) || ! is_string($fixture[$key]) || '' === $fixture[$key] ) {
            $errors[] = "Fixture at index {$index} is missing {$key}.";
        }
    }

    if ( ! isset($fixture['id'], $fixture['current_path'], $fixture['intended_path'], $fixture['entrypoint'], $fixture['product_class']) ) {
        continue;
    }

    $id = $fixture['id'];
    if ( isset($ids[$id]) ) {
        $errors[] = "Duplicate fixture id {$id}.";
    }
    $ids[$id] = true;
    $classCounts[$fixture['product_class']] = ($classCounts[$fixture['product_class']] ?? 0) + 1;

    $currentEntrypoint = $root . '/' . $fixture['current_path'] . '/' . $fixture['entrypoint'];
    $intendedEntrypoint = $root . '/' . $fixture['intended_path'] . '/' . $fixture['entrypoint'];
    if ( ! is_file($currentEntrypoint) && ! is_file($intendedEntrypoint) ) {
        $errors[] = "Fixture {$id} entrypoint was not found at current_path or intended_path.";
    }
}

if ( isset($manifest['source_fixture_count']) && count($fixtures) !== (int) $manifest['source_fixture_count'] ) {
    $errors[] = 'Fixture count does not match source_fixture_count.';
}

$declaredCounts = array();
foreach ( $manifest['classes'] ?? array() as $class ) {
    if ( ! is_array($class) || ! isset($class['product_class'], $class['fixture_count']) ) {
        $errors[] = 'Each class declaration must include product_class and fixture_count.';
        continue;
    }
    $declaredCounts[(string) $class['product_class']] = (int) $class['fixture_count'];
}

foreach ( $classCounts as $productClass => $count ) {
    if ( ! array_key_exists($productClass, $declaredCounts) ) {
        $errors[] = "Missing class declaration for {$productClass}.";
        continue;
    }
    if ( $declaredCounts[$productClass] !== $count ) {
        $errors[] = "Class {$productClass} declares {$declaredCounts[$productClass]} fixtures but manifest contains {$count}.";
    }
}

foreach ( $declaredCounts as $productClass => $count ) {
    if ( ! array_key_exists($productClass, $classCounts) ) {
        $errors[] = "Class {$productClass} declares {$count} fixtures but manifest contains none.";
    }
}

if ( array() !== $errors ) {
    foreach ( $errors as $error ) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}

echo sprintf("Validated %d website fixture manifest entries across %d product classes.\n", count($fixtures), count($classCounts));
