#!/usr/bin/env node
import { existsSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { homedir, tmpdir } from 'node:os';
import { basename, join } from 'node:path';

import { foundation } from '../src/theme/foundation.js';
import { ingest } from '../src/theme/ingest.js';
import { visibleText } from '../src/theme/native-block-builders.js';
import { COLOR_SNAP_GATE, nearestToken, type PaletteToken } from '../src/theme/native-color.js';
import { opaqueLiteralBgHex } from '../src/theme/native-layout.js';
import { sectionExtract } from '../src/theme/section-extract.js';
import { siteToTheme } from '../src/theme/site-to-theme.js';
import type { SectionBlocks } from '../src/theme/types.js';
import type { SectionSpec, SectionSpecButton, SectionSpecCell } from '../src/theme/section-spec.js';

type Emission = { kind: 'slug'; slug: string } | { kind: 'literal'; hex: string };
type SiteCounts = { slug: number; literal: number; skipped: number };

const SITE_DIRS = [
  '15-saas',
  '14-restaurant',
  '12-portfolio',
  '10-nonprofit',
  '20-switchback-woocommerce-extra-hard',
].map((name) => join(homedir(), 'Desktop', 'raw-html', name));

function slugify(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function uniqueSlug(baseSlug: string, used: Set<string>): string {
  let slug = baseSlug;
  let suffix = 2;
  while (used.has(slug)) {
    slug = `${baseSlug}-${suffix}`;
    suffix += 1;
  }
  used.add(slug);
  return slug;
}

function paletteTokensForSite(siteDir: string): PaletteToken[] {
  const tokens = foundation(ingest(siteDir));
  const used = new Set<string>();
  return tokens.palette
    .map((token) => {
      const name = token.name.trim();
      const hex = token.color.trim();
      if (!name || !hex) return null;
      return { slug: uniqueSlug(slugify(name) || 'color', used), hex };
    })
    .filter((token): token is PaletteToken => token !== null);
}

function offBrandHex(tokens: PaletteToken[]): string {
  const candidates = [
    '#c04a9d',
    '#00a3ff',
    '#ff6b00',
    '#7f4cff',
    '#123456',
    '#d6ff00',
  ];
  for (const candidate of candidates) {
    if (nearestToken(candidate, tokens, COLOR_SNAP_GATE) === null) return candidate;
  }

  for (const r of [17, 73, 131, 197, 241]) {
    for (const g of [29, 89, 149, 211]) {
      for (const b of [43, 103, 163, 223]) {
        const candidate = `#${[r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('')}`;
        if (nearestToken(candidate, tokens, COLOR_SNAP_GATE) === null) return candidate;
      }
    }
  }

  throw new Error('unable to find a deterministic off-brand dogfood color');
}

function onBrandHex(tokens: PaletteToken[]): string {
  for (const token of tokens) {
    const hex = opaqueLiteralBgHex(token.hex);
    if (hex && nearestToken(hex, tokens, COLOR_SNAP_GATE) === token.slug) return token.hex;
  }
  return '#008060';
}

function baseSection(sectionIndex: number, overrides: Partial<SectionSpec>): SectionSpec {
  return {
    sectionIndex,
    interactionModel: 'static',
    top: 0,
    height: 420,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 255,
    backgroundColor: 'rgb(255, 255, 255)',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 1100,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '24px',
    },
    ...overrides,
  };
}

function dogfoodSections(siteDir: string, tokens: PaletteToken[]): Record<string, SectionSpec[]> {
  const site = ingest(siteDir);
  const out: Record<string, SectionSpec[]> = {};
  const onBrand = onBrandHex(tokens);
  const offBrand = offBrandHex(tokens);

  for (const page of site.pages) {
    out[page.slug] = sectionExtract({ ...page, html: page.html });
  }

  const home = site.pages[0]?.slug;
  if (!home) return out;
  const existing = out[home] ?? [];
  const sectionIndex = existing.reduce((max, section) => Math.max(max, section.sectionIndex), 0) + 1;

  existing.push(
    baseSection(sectionIndex, {
      interactionModel: 'cta',
      headings: ['Dogfood snap gate CTA'],
      bodyText: ['Synthetic renderer sentry for site-bound palette verification.'],
      buttons: [
        { label: 'Dogfood literal button', href: '#literal-button', background: offBrand },
        { label: 'Dogfood slug button', href: '#slug-button', background: onBrand },
      ],
    }),
    baseSection(sectionIndex + 1, {
      layout: {
        containerWidth: 1100,
        padding: '0',
        childLayout: 'grid',
        columnCount: 2,
        gap: '24px',
      },
      cells: [
        {
          heading: 'Dogfood literal card',
          body: ['Synthetic renderer sentry for literal card backgrounds.'],
          image: null,
          icon: null,
          button: null,
          background: offBrand,
          radius: 16,
          padding: { top: 20, right: 24, bottom: 20, left: 24 },
        },
        {
          heading: 'Dogfood slug card',
          body: ['Synthetic renderer sentry for slug card backgrounds.'],
          image: null,
          icon: null,
          button: null,
          background: onBrand,
          radius: 16,
          padding: { top: 20, right: 24, bottom: 20, left: 24 },
        },
      ],
    }),
  );
  out[home] = existing;
  return out;
}

function normalizeText(value: string): string {
  return value.replace(/\s+/g, ' ').trim();
}

function wpBlocks(markup: string, blockName: 'button' | 'group'): string[] {
  const re = new RegExp(`<!-- wp:${blockName} [\\s\\S]*?<!-- /wp:${blockName} -->`, 'g');
  return markup.match(re) ?? [];
}

function classifyEmission(markup: string): Emission | null {
  const literal = /"color":\{"background":"(#[0-9a-f]{6})"\}/i.exec(markup);
  if (literal) return { kind: 'literal', hex: literal[1].toLowerCase() };

  const slug = /"backgroundColor":"([^"]+)"/.exec(markup);
  if (slug) return { kind: 'slug', slug: slug[1] };

  return null;
}

function expectedEmission(color: string | null | undefined, tokens: PaletteToken[]): Emission | null {
  const hex = opaqueLiteralBgHex(color);
  if (!hex) return null;
  const slug = nearestToken(hex, tokens, COLOR_SNAP_GATE);
  return slug ? { kind: 'slug', slug } : { kind: 'literal', hex };
}

function takeMatchingBlock(blocks: string[], text: string): string | null {
  const wanted = normalizeText(text);
  const index = blocks.findIndex((block) => normalizeText(visibleText(block)).includes(wanted));
  if (index === -1) return null;
  const [block] = blocks.splice(index, 1);
  return block ?? null;
}

function assertEmission(site: string, label: string, expected: Emission, actualMarkup: string): Emission {
  const actual = classifyEmission(actualMarkup);
  if (!actual) {
    throw new Error(`${site}: ${label} expected ${expected.kind} background but emitted none`);
  }
  if (actual.kind !== expected.kind) {
    throw new Error(`${site}: ${label} expected ${expected.kind} but emitted ${actual.kind}`);
  }
  if (actual.kind === 'slug' && expected.kind === 'slug' && actual.slug !== expected.slug) {
    throw new Error(`${site}: ${label} expected slug ${expected.slug} but emitted ${actual.slug}`);
  }
  if (actual.kind === 'literal' && expected.kind === 'literal' && actual.hex !== expected.hex) {
    throw new Error(`${site}: ${label} expected literal ${expected.hex} but emitted ${actual.hex}`);
  }
  return actual;
}

function assertButton(site: string, blocks: string[], button: SectionSpecButton, tokens: PaletteToken[]): Emission | null {
  const expected = expectedEmission(button.background, tokens);
  if (!expected) return null;
  const block = takeMatchingBlock(blocks, button.label);
  if (!block) throw new Error(`${site}: button "${button.label}" background not found in generated blocks`);
  return assertEmission(site, `button "${button.label}"`, expected, block);
}

function assertCard(site: string, blocks: string[], cell: SectionSpecCell, tokens: PaletteToken[]): Emission | null {
  const expected = expectedEmission(cell.background, tokens);
  if (!expected) return null;
  const label = cell.heading ?? cell.body[0] ?? '';
  if (!label) return null;
  const block = takeMatchingBlock(blocks, label);
  if (!block) throw new Error(`${site}: card "${label}" background not found in generated blocks`);
  return assertEmission(site, `card "${label}"`, expected, block);
}

function countEmission(counts: SiteCounts, emission: Emission | null): void {
  if (!emission) {
    counts.skipped += 1;
  } else {
    counts[emission.kind] += 1;
  }
}

async function verifySite(siteDir: string, tmpRoot: string): Promise<SiteCounts> {
  if (!existsSync(siteDir)) throw new Error(`missing dogfood site: ${siteDir}`);

  const siteName = basename(siteDir);
  const paletteTokens = paletteTokensForSite(siteDir);
  const seenSections: SectionBlocks[] = [];
  const site = ingest(siteDir);
  const renderOptions = Object.fromEntries(
    site.pages.map((page) => [page.slug, { paletteTokens }]),
  );

  await siteToTheme(siteDir, {
    outDir: join(tmpRoot, siteName),
    sections: dogfoodSections(siteDir, paletteTokens),
    variationHoist: false,
    coverageFloor: 1,
    renderOptions,
    hooks: {
      async onSection(section) {
        seenSections.push(section);
        return section;
      },
    },
  });

  const counts: SiteCounts = { slug: 0, literal: 0, skipped: 0 };
  for (const section of seenSections) {
    const buttonBlocks = wpBlocks(section.blocks, 'button');
    for (const button of section.spec.buttons ?? []) {
      if (button.background === undefined || button.background === null) continue;
      countEmission(counts, assertButton(siteName, buttonBlocks, button, paletteTokens));
    }

    const cardBlocks = wpBlocks(section.blocks, 'group').filter((block) => block.includes('is-replica-card'));
    for (const cell of section.spec.cells ?? []) {
      if (cell.background === undefined || cell.background === null) continue;
      countEmission(counts, assertCard(siteName, cardBlocks, cell, paletteTokens));
    }
  }

  return counts;
}

async function main(): Promise<void> {
  const tmpRoot = await mkdtemp(join(tmpdir(), 'blocks-engine-color-snap-'));
  let totalLiteral = 0;
  try {
    for (const siteDir of SITE_DIRS) {
      const counts = await verifySite(siteDir, tmpRoot);
      totalLiteral += counts.literal;
      process.stdout.write(
        `${basename(siteDir)} slug=${counts.slug} literal=${counts.literal} skipped=${counts.skipped}\n`,
      );
    }

    if (totalLiteral < 1) {
      throw new Error('expected at least one literal button/card background across dogfood sites');
    }
  } finally {
    await rm(tmpRoot, { recursive: true, force: true });
  }
}

main().catch((error: unknown) => {
  const message = error instanceof Error ? error.message : String(error);
  process.stderr.write(`${message}\n`);
  process.exitCode = 1;
});
