import { createRequire } from 'node:module';

type JSDOMConstructor = new (
  html?: string,
  options?: { pretendToBeVisual?: boolean; url?: string },
) => BootstrapDom;

type JSDOMModule = {
  JSDOM: JSDOMConstructor;
};

type BlockLibraryModule = {
  registerCoreBlocks: () => void;
};

type BootstrapWindow = Window &
  typeof globalThis & {
    close: () => void;
  };

type BootstrapDom = {
  window: BootstrapWindow;
};

type DomGlobalTarget = typeof globalThis & {
  window: Window & typeof globalThis;
  document: Document;
  DOMParser: typeof DOMParser;
  XMLSerializer: typeof XMLSerializer;
  Node: typeof Node;
  Element: typeof Element;
  HTMLElement: typeof HTMLElement;
  getComputedStyle: typeof getComputedStyle;
  MutationObserver: typeof MutationObserver;
  requestAnimationFrame: typeof requestAnimationFrame;
  cancelAnimationFrame: typeof cancelAnimationFrame;
  matchMedia: typeof matchMedia;
  ResizeObserver: typeof ResizeObserver;
  navigator: Navigator;
};

const requireFromHere = createRequire(
  typeof __filename === 'string' ? __filename : import.meta.url,
);

let dom: BootstrapDom | undefined;
let ready = false;

function installDomGlobals(nextDom: BootstrapDom): void {
  const { window } = nextDom;
  const target = globalThis as DomGlobalTarget;

  target.window = window;
  target.document = window.document;
  target.DOMParser = window.DOMParser;
  target.XMLSerializer = window.XMLSerializer;
  target.Node = window.Node;
  target.Element = window.Element;
  target.HTMLElement = window.HTMLElement;
  target.getComputedStyle = window.getComputedStyle;
  target.MutationObserver = window.MutationObserver;
  target.requestAnimationFrame = ((callback) =>
    setTimeout(() => {
      callback(Date.now());
    }, 16) as unknown as number) as typeof requestAnimationFrame;
  target.cancelAnimationFrame = ((id) => {
    clearTimeout(id as unknown as ReturnType<typeof setTimeout>);
  }) as typeof cancelAnimationFrame;
  target.matchMedia = (() => ({
    matches: false,
    addListener() {},
    removeListener() {},
    addEventListener() {},
    removeEventListener() {},
  })) as unknown as typeof matchMedia;
  target.ResizeObserver = class ResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  } as typeof ResizeObserver;

  Object.defineProperty(target, 'navigator', {
    value: window.navigator,
    writable: true,
    configurable: true,
  });
}

export function bootstrap(): void {
  if (ready) {
    return;
  }

  const { JSDOM } = requireFromHere('jsdom') as JSDOMModule;
  dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    url: 'http://localhost',
    pretendToBeVisual: true,
  });

  installDomGlobals(dom);

  try {
    const { registerCoreBlocks } = requireFromHere(
      '@wordpress/block-library',
    ) as BlockLibraryModule;
    registerCoreBlocks();
    ready = true;
  } catch (error) {
    dom.window.close();
    dom = undefined;
    throw error;
  }
}

export function __resetBootstrapForTest(): void {
  ready = false;
  dom?.window.close();
  dom = undefined;
}
