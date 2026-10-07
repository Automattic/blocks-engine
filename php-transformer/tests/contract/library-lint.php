<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . '/php-transformer-lint-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create lint evidence directory.');
$fixtures = array(
    'valid' => array('source' => '<?php declare(strict_types=1); namespace LibraryFixture; final class Example { public function value(): int { return 42; } }', 'status' => 0, 'sniff' => null),
    'syntax' => array('source' => '<?php function broken( {', 'status' => 1, 'sniff' => 'Generic.PHP.Syntax'),
    'deprecated' => array('source' => '<?php $result = utf8_encode("example");', 'status' => 1, 'sniff' => 'Generic.PHP.DeprecatedFunctions'),
    'valid-symbols' => array('source' => '<?php declare(strict_types=1); namespace LibraryFixture; final class Example { public function value(): int { return 42; } }', 'status' => 0, 'sniff' => null, 'tool' => 'phpstan'),
    'missing-symbol' => array('source' => '<?php namespace LibraryFixture; new MissingClass();', 'status' => 1, 'sniff' => 'class.notFound', 'tool' => 'phpstan'),
);
try {
    foreach ($fixtures as $name => $fixture) {
        $file = $directory . '/' . $name . '.php';
        file_put_contents($file, $fixture['source']);
        $phpstan = 'phpstan' === ($fixture['tool'] ?? 'phpcs');
        $command = $phpstan
            ? array(PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $root . '/phpstan.neon.dist', '--error-format=json', '--no-progress', '--memory-limit=1G', $file)
            : array(PHP_BINARY, $root . '/vendor/bin/phpcs', '--standard=' . $root . '/phpcs.xml.dist', '--report=json', $file);
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root);
        if (!is_resource($process)) throw new RuntimeException('Cannot start library lint.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $messages = array_merge(...array_values(array_map(static fn(array $row): array => $row['messages'], $report['files'])));
        if (($fixture['status'] === 0 ? $status !== 0 : $status === 0) || ($fixture['sniff'] !== null && !array_filter($messages, static fn(array $row): bool => str_starts_with($row[$phpstan ? 'identifier' : 'source'], $fixture['sniff'])))) {
            throw new RuntimeException($name . ' lint contract failed: ' . $stdout . $stderr);
        }
    }
    print "Library lint contract passed: valid PHP and symbols accepted; malformed PHP, deprecated calls and missing classes rejected.\n";
} finally {
    foreach (array_keys($fixtures) as $name) if (is_file($directory . '/' . $name . '.php')) unlink($directory . '/' . $name . '.php');
    rmdir($directory);
}
