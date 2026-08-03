import { createHash } from 'node:crypto';
import { parse } from 'acorn';

export type RuntimeEffectUnit = {
  id: string;
  source: { start: number; end: number; hash: string };
  targets: string[];
  events: string[];
  mutations: string[];
  dependencies: string[];
  status: 'independently_suppressible' | 'shared_or_unsplittable';
  reason?: 'dynamic_selector' | 'shared_state' | 'unrecognized_pattern';
};

export type RegionEffectManifest = {
  schema: 'blocks-engine/runtime-region-effects/v1';
  sourceHash: string;
  units: RuntimeEffectUnit[];
};

const hash = (value: string) => createHash('sha256').update(value).digest('hex');

/**
 * Produces an ownership manifest from top-level DOM-effect statements. This is
 * deliberately bounded: unsupported AST shapes remain retained as a whole.
 */
export function analyzeRuntimeRegionEffects(source: string): RegionEffectManifest {
  const sourceHash = hash(source);
  let program: any;
  try {
    program = parse(source, { ecmaVersion: 'latest', sourceType: 'script' });
  } catch {
    return { schema: 'blocks-engine/runtime-region-effects/v1', sourceHash, units: [] };
  }

  const declared = new Map<string, number>();
  for (const statement of program.body) {
    if (statement.type === 'VariableDeclaration') {
      for (const declaration of statement.declarations) {
        if (declaration.id.type === 'Identifier') declared.set(declaration.id.name, (declared.get(declaration.id.name) ?? 0) + 1);
      }
    }
  }

  return {
    schema: 'blocks-engine/runtime-region-effects/v1',
    sourceHash,
    units: program.body.map((statement: any, index: number) => unitFor(statement, index, source, sourceHash, declared)),
  };
}

function unitFor(statement: any, index: number, source: string, sourceHash: string, declared: Map<string, number>): RuntimeEffectUnit {
  const slice = source.slice(statement.start, statement.end);
  const targets = new Set<string>();
  const events = new Set<string>();
  const mutations = new Set<string>();
  const identifiers = new Set<string>();
  let dynamicSelector = false;
  let recognized = false;
  walk(statement, (node) => {
    if (node.type === 'Identifier') identifiers.add(node.name);
    if (node.type !== 'CallExpression' || node.callee.type !== 'MemberExpression') return;
    const name = node.callee.property.type === 'Identifier' ? node.callee.property.name : '';
    if ((name === 'querySelector' || name === 'querySelectorAll' || name === 'getElementById') && node.arguments.length) {
      recognized = true;
      const argument = node.arguments[0];
      if (argument.type !== 'Literal' || typeof argument.value !== 'string') dynamicSelector = true;
      else targets.add(name === 'getElementById' ? `#${argument.value}` : argument.value);
    }
    if (name === 'addEventListener' && node.arguments[0]?.type === 'Literal' && typeof node.arguments[0].value === 'string') {
      recognized = true;
      events.add(node.arguments[0].value);
    }
    if (['add', 'remove', 'toggle', 'replace'].includes(name) && node.callee.object?.type === 'MemberExpression' && node.callee.object.property?.name === 'classList') mutations.add('class');
    if (name === 'setAttribute' && node.arguments[0]?.type === 'Literal' && typeof node.arguments[0].value === 'string') mutations.add(`attribute:${node.arguments[0].value}`);
  });
  const shared = [...declared.keys()].some((name) => identifiers.has(name));
  const reason = dynamicSelector ? 'dynamic_selector' : shared ? 'shared_state' : !recognized || !targets.size ? 'unrecognized_pattern' : undefined;
  const unit: RuntimeEffectUnit = {
    id: `effect_${sourceHash.slice(0, 12)}_${index + 1}`,
    // Acorn offsets are UTF-16 code units. The PHP bridge slices byte strings,
    // so publish UTF-8 byte spans as the cross-runtime ownership contract.
    source: { start: Buffer.byteLength(source.slice(0, statement.start)), end: Buffer.byteLength(source.slice(0, statement.end)), hash: hash(slice) },
    targets: [...targets].sort(), events: [...events].sort(), mutations: [...mutations].sort(),
    dependencies: [...identifiers].filter((name) => declared.has(name)).sort(),
    status: reason ? 'shared_or_unsplittable' : 'independently_suppressible',
  };
  return reason ? { ...unit, reason } : unit;
}

function walk(node: any, visit: (node: any) => void) {
  if (!node || typeof node !== 'object' || typeof node.type !== 'string') return;
  visit(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach((child) => walk(child, visit));
    else walk(value, visit);
  }
}
