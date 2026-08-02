import { describe, expect, it } from 'vitest';
import { analyzeRuntimeRegionEffects } from './region-effect-manifest.js';

describe('analyzeRuntimeRegionEffects', () => {
  it('splits fixture87-style carousel and reveal effects without dropping either', () => {
    const manifest = analyzeRuntimeRegionEffects(`document.querySelectorAll('.carousel .next').forEach((button) => button.addEventListener('click', () => button.closest('.carousel').classList.add('active')));\ndocument.querySelectorAll('.reveal').forEach((item) => item.addEventListener('scroll', () => item.classList.add('visible')));`);
    expect(manifest.units.map((unit) => unit.status)).toEqual(['independently_suppressible', 'independently_suppressible']);
    expect(manifest.units[0].targets).toEqual(['.carousel .next']);
    expect(manifest.units[1].targets).toEqual(['.reveal']);
  });

  it('fails closed for shared state and dynamic selectors', () => {
    const shared = analyzeRuntimeRegionEffects(`let active = 0;\ndocument.querySelector('.carousel').addEventListener('click', () => active++);`);
    expect(shared.units.every((unit) => unit.status === 'shared_or_unsplittable')).toBe(true);
    const dynamic = analyzeRuntimeRegionEffects(`document.querySelector(selector).addEventListener('click', () => {});`);
    expect(dynamic.units[0].reason).toBe('dynamic_selector');
  });
});
