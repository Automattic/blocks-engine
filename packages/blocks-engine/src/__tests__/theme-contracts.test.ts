import { describe, expect, it } from 'vitest';
import type { WorkerPool } from '../pool/types.js';
import {
  assemble,
  foundation,
  ingest,
  reconstruct,
  sectionExtract,
  siteToTheme,
  writeTheme,
  type AssetFile,
  type AssetInventory,
  type AssetVerdicts,
  type FoundationAggregates,
  type FoundationTokens,
  type SectionBlocks,
  type SectionSpec,
  type SectionSpecButton,
  type SectionSpecCell,
  type SectionSpecForm,
  type SectionSpecFormField,
  type SectionSpecIcon,
  type SectionSpecImage,
  type SectionSpecLayout,
  type SectionSpecMotion,
  type SiteModel,
  type SitePage,
  type SiteToThemeHooks,
  type SiteToThemeOptions,
  type StageCtx,
  type ThemeBuildResult,
  type ThemeMeta,
  type ThemeModel,
} from '../theme/index.js';

type Satisfies<T, U extends T> = U;

type ThemeStageSignatures = {
  siteToTheme: (srcDir: string, options?: SiteToThemeOptions) => Promise<ThemeBuildResult>;
  writeTheme: (model: ThemeModel, outDir: string) => Promise<string[]>;
  ingest: (srcDir: string) => SiteModel;
  sectionExtract: (page: SitePage) => SectionSpec[];
  foundation: (site: SiteModel, aggregates?: FoundationAggregates) => FoundationTokens;
  reconstruct: (
    specs: SectionSpec[],
    ctx: StageCtx,
    pool: WorkerPool,
    hooks: SiteToThemeHooks,
    coverageFloor: number
  ) => Promise<SectionBlocks[]>;
  assemble: (parts: {
    site: SiteModel;
    tokens: FoundationTokens;
    pages: Record<string, SectionBlocks[]>;
    meta: ThemeMeta;
  }) => ThemeModel;
};

const themeStageSignatures: ThemeStageSignatures = {
  siteToTheme,
  writeTheme,
  ingest,
  sectionExtract,
  foundation,
  reconstruct,
  assemble,
};

void themeStageSignatures;

type CompileOnlyThemeContractAssignments = {
  siteModel: Satisfies<
    SiteModel,
    {
      root: string;
      pages: SitePage[];
    }
  >;
  sitePage: Satisfies<
    SitePage,
    {
      relPath: string;
      slug: string;
      html: string;
      title: string;
      bodyData: Record<string, string>;
    }
  >;
  sectionSpecImage: Satisfies<
    SectionSpecImage,
    {
      url: string;
      sourceUrl: string;
      alt: string;
      kind: 'img';
      width: number;
      height: number;
    }
  >;
  sectionSpecIcon: Satisfies<
    SectionSpecIcon,
    {
      kind: 'glyph';
      glyph: string;
      width: number;
      height: number;
    }
  >;
  sectionSpecButton: Satisfies<
    SectionSpecButton,
    {
      label: string;
      href: string;
      background: string | null;
      color: string | null;
      icon: SectionSpecIcon | null;
      iconAfter: boolean;
    }
  >;
  sectionSpecFormField: Satisfies<
    SectionSpecFormField,
    {
      kind: 'email';
      label: string;
      required: boolean;
      placeholder: string;
      defaultValue: string;
      options: string[];
      widthPct: 100;
    }
  >;
  sectionSpecForm: Satisfies<
    SectionSpecForm,
    {
      fields: SectionSpecFormField[];
      submitLabel: string;
    }
  >;
  sectionSpecCell: Satisfies<
    SectionSpecCell,
    {
      heading: string | null;
      body: string[];
      image: SectionSpecImage | null;
      icon: SectionSpecIcon | null;
      button: string | null;
    }
  >;
  sectionSpecMotion: Satisfies<
    SectionSpecMotion,
    {
      motionClass: 'none';
      signals: string[];
      animatedElements: number;
    }
  >;
  sectionSpecLayout: Satisfies<
    SectionSpecLayout,
    {
      containerWidth: number;
      padding: string;
      childLayout: 'stack';
      columnCount: number;
      gap: string;
    }
  >;
  sectionSpec: Satisfies<
    SectionSpec,
    {
      sectionIndex: number;
      interactionModel: 'static';
      top: number;
      height: number;
      headings: string[];
      bodyText: string[];
      buttonLabels: string[];
      images: SectionSpecImage[];
      icons: SectionSpecIcon[];
      backgroundBrightness: number;
      backgroundColor: string;
      gradient: string | null;
      gradientSource: null;
      motionProfile: SectionSpecMotion;
      dividerAbove: { color: string; thickness: number } | null;
      dividerBelow: { color: string; thickness: number } | null;
      layout: SectionSpecLayout;
      cells: SectionSpecCell[];
      forms: SectionSpecForm[];
    }
  >;
  foundationTokens: Satisfies<
    FoundationTokens,
    {
      palette: { name: string; color: string }[];
      typography: { body: string; display: string };
      breakpoints: { md: string; lg: string; xl: string };
    }
  >;
  foundationAggregates: Satisfies<
    FoundationAggregates,
    {
      palette: unknown;
      typography: unknown;
      breakpoints: unknown;
    }
  >;
  assetFile: Satisfies<
    AssetFile,
    {
      relPath: string;
      bytes: Uint8Array;
      sourcePath: string;
    }
  >;
  themeModel: Satisfies<
    ThemeModel,
    {
      styleCss: string;
      themeJson: Record<string, unknown>;
      templates: Record<string, string>;
      parts: Record<string, string>;
      patterns: Record<string, string>;
      assets: AssetFile[];
    }
  >;
  sectionBlocks: Satisfies<
    SectionBlocks,
    {
      spec: SectionSpec;
      blocks: string;
      coverage: number;
    }
  >;
  assetInventory: Satisfies<
    AssetInventory,
    {
      assets: AssetFile[];
    }
  >;
  assetVerdicts: Satisfies<
    AssetVerdicts,
    {
      keep: string[];
      decoration: string[];
    }
  >;
  stageCtx: Satisfies<
    StageCtx,
    {
      srcDir: string;
      site: SiteModel;
      themeMeta: ThemeMeta;
      warn(msg: string): void;
    }
  >;
  themeMeta: Satisfies<
    ThemeMeta,
    {
      name: string;
      slug: string;
      author: string;
    }
  >;
  siteToThemeHooks: Satisfies<
    SiteToThemeHooks,
    {
      onFoundation(tokens: FoundationTokens, ctx: StageCtx): Promise<FoundationTokens>;
      onSection(section: SectionBlocks, ctx: StageCtx): Promise<SectionBlocks>;
      onAssets(inventory: AssetInventory, ctx: StageCtx): Promise<AssetVerdicts>;
      onRefine(theme: ThemeModel, ctx: StageCtx): Promise<ThemeModel>;
    }
  >;
  siteToThemeOptions: Satisfies<
    SiteToThemeOptions,
    {
      outDir: string;
      sections: Record<string, SectionSpec[]>;
      foundationAggregates: FoundationAggregates;
      hooks: SiteToThemeHooks;
      fetchImpl: typeof fetch;
      coverageFloor: number;
      themeMeta: Partial<ThemeMeta>;
    }
  >;
  themeBuildResult: Satisfies<
    ThemeBuildResult,
    {
      outDir: string;
      model: ThemeModel;
      written: string[];
      tallies: Record<string, number>;
      warnings: string[];
    }
  >;
};

void (null as never as CompileOnlyThemeContractAssignments);

describe('theme public contract', () => {
  it('exports runtime stage functions with fixed arity', () => {
    const runtimeStages = [
      [siteToTheme, 2],
      [writeTheme, 2],
      [ingest, 1],
      [sectionExtract, 1],
      [foundation, 2],
      [reconstruct, 5],
      [assemble, 1],
    ] as const;

    for (const [stage, expectedArity] of runtimeStages) {
      expect(stage).toBeTypeOf('function');
      expect(stage).toHaveLength(expectedArity);
    }
  });
});
