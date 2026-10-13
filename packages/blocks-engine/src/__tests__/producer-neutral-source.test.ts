import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

// Blocks Engine carries no knowledge of a specific capture producer; it reads
// capture evidence through its own published vocabulary
// (php-transformer/docs/contracts/capture-evidence.md). The guard is
// case-insensitive and word-ish: a token inside a longer lowercase word
// (`childLayout`) passes, while a camelCase or upper-case token fails. Tokens
// are built from fragments so this file passes its own check.
// php-transformer/tests/contract/producer-neutral-source.php applies the same
// rule to both packages.
const short = 'd' + 'la';
const long = 'liberation';
const SHORT_TOKEN = new RegExp(
  `(?:(?<![A-Za-z])(?:${short}|${short.toUpperCase()}|D${short.slice(1)})|(?<=[a-z])D${short.slice(1)})(?![a-z])`
);
const LONG_TOKEN = new RegExp(`data-${long}|${long}-agent`, 'i');
const isProducerToken = (text: string): boolean => SHORT_TOKEN.test(text) || LONG_TOKEN.test(text);

const packageRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function filesUnder(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const full = path.join(dir, entry);
    return statSync(full).isDirectory() ? filesUnder(full) : [full];
  });
}

function offendingLines(label: string, text: string): string[] {
  return text
    .split(/\r?\n/)
    .flatMap((line, index) => (isProducerToken(line) ? [`${label}:${index + 1}: ${line.trim().slice(0, 140)}`] : []));
}

describe('producer-neutral source', () => {
  it('keeps capture-producer names out of runtime source and package scripts', () => {
    const offenders = filesUnder(path.join(packageRoot, 'src')).flatMap((file) =>
      offendingLines(path.relative(packageRoot, file), readFileSync(file, 'utf8'))
    );
    const manifest = JSON.parse(readFileSync(path.join(packageRoot, 'package.json'), 'utf8')) as { scripts?: Record<string, string> };
    offenders.push(...offendingLines('package.json scripts', JSON.stringify(manifest.scripts ?? {}, null, 2)));

    expect(offenders).toEqual([]);
  });

  it('recognizes the producer tokens it guards against', () => {
    for (const sample of [`${short}-x`, `data-${short}-x`, `${short.toUpperCase()}_ROOT`, `card${'D' + short.slice(1)}Section`, `data-${long}-x`, `${long}-agent`]) {
      expect(isProducerToken(sample), sample).toBe(true);
    }
    for (const sample of ['childLayout', 'headlamp', 'isDirectChildOfAuthorOwnedLayout']) {
      expect(isProducerToken(sample), sample).toBe(false);
    }
  });
});
