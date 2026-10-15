import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    isStandaloneDisplay,
    isScrolledToTop,
    pullOffset,
    createPullToRefresh,
} from '../../resources/js/lib/pullToRefresh.js';

/** matchMedia fake: returns matches=true only for the queries listed. */
function fakeWindow({ media = [], standalone, scrollY = 0 } = {}) {
    return {
        scrollY,
        matchMedia: (query) => ({ matches: media.includes(query) }),
        navigator: standalone === undefined ? {} : { standalone },
    };
}

/** Builds a fake DOM chain: leaf -> ... -> root. Each node is { scrollTop, attrs }. */
function chain(...nodes) {
    let parent = null;
    const built = [];
    for (let i = nodes.length - 1; i >= 0; i--) {
        const spec = nodes[i];
        const el = {
            scrollTop: spec.scrollTop ?? 0,
            parentElement: parent,
            hasAttribute: (name) => (spec.attrs ?? []).includes(name),
        };
        built.unshift(el);
        parent = el;
    }
    return built[0];
}

const ok = () => {};

function touch(target, clientY, extra = {}) {
    let prevented = false;
    return {
        target,
        touches: [{ clientY }],
        cancelable: true,
        preventDefault: () => {
            prevented = true;
        },
        get prevented() {
            return prevented;
        },
        ...extra,
    };
}

describe('isStandaloneDisplay', () => {
    it('true for installed PWA (display-mode standalone)', () => {
        assert.equal(isStandaloneDisplay(fakeWindow({ media: ['(display-mode: standalone)'] })), true);
    });

    it('true for iOS home-screen app (navigator.standalone)', () => {
        assert.equal(isStandaloneDisplay(fakeWindow({ standalone: true })), true);
    });

    it('false in a regular browser tab', () => {
        assert.equal(isStandaloneDisplay(fakeWindow()), false);
        assert.equal(isStandaloneDisplay(fakeWindow({ standalone: false })), false);
    });

    it('false without window (SSR)', () => {
        assert.equal(isStandaloneDisplay(undefined), false);
    });
});

describe('isScrolledToTop', () => {
    it('true when page and every ancestor are at the top', () => {
        assert.equal(isScrolledToTop(chain({}, {}, {}), fakeWindow()), true);
    });

    it('false when the window is scrolled down', () => {
        assert.equal(isScrolledToTop(chain({}, {}), fakeWindow({ scrollY: 40 })), false);
    });

    it('false when an inner scroll container is scrolled down', () => {
        assert.equal(isScrolledToTop(chain({}, { scrollTop: 120 }, {}), fakeWindow()), false);
    });

    it('false inside an element that opts out with data-no-pull-refresh', () => {
        assert.equal(isScrolledToTop(chain({}, { attrs: ['data-no-pull-refresh'] }, {}), fakeWindow()), false);
    });
});

describe('pullOffset', () => {
    it('is zero when dragging up or not moving', () => {
        assert.equal(pullOffset(0), 0);
        assert.equal(pullOffset(-50), 0);
    });

    it('applies resistance (indicator moves slower than the finger)', () => {
        const offset = pullOffset(100);
        assert.ok(offset > 0 && offset < 100, `got ${offset}`);
    });

    it('is capped', () => {
        assert.ok(pullOffset(5000) <= 120);
    });
});

describe('createPullToRefresh', () => {
    function setup(opts = {}) {
        const calls = { refresh: 0, changes: [] };
        const ptr = createPullToRefresh({
            win: opts.win ?? fakeWindow({ media: ['(display-mode: standalone)'] }),
            isLocked: opts.isLocked ?? (() => false),
            threshold: 70,
            onRefresh: () => {
                calls.refresh++;
            },
            onChange: (state) => calls.changes.push(state),
        });
        return { ptr, calls };
    }

    it('refreshes after pulling past the threshold from the top', () => {
        const { ptr, calls } = setup();
        const el = chain({}, {});
        ptr.start(touch(el, 100));
        const move = touch(el, 400);
        ptr.move(move);
        assert.equal(move.prevented, true, 'blocks native bounce while pulling');
        ptr.end();
        assert.equal(calls.refresh, 1);
        assert.equal(calls.changes.at(-1).refreshing, true);
    });

    it('does not refresh on a short pull', () => {
        const { ptr, calls } = setup();
        const el = chain({}, {});
        ptr.start(touch(el, 100));
        ptr.move(touch(el, 130));
        ptr.end();
        assert.equal(calls.refresh, 0);
        assert.equal(calls.changes.at(-1).offset, 0);
    });

    it('ignores pulls when the page is scrolled down', () => {
        const { ptr, calls } = setup({ win: fakeWindow({ media: ['(display-mode: standalone)'], scrollY: 300 }) });
        const el = chain({}, {});
        ptr.start(touch(el, 100));
        const move = touch(el, 500);
        ptr.move(move);
        ptr.end();
        assert.equal(move.prevented, false, 'normal scroll is untouched');
        assert.equal(calls.refresh, 0);
    });

    it('ignores pulls inside a scrolled inner container (modal, sidebar)', () => {
        const { ptr, calls } = setup();
        const el = chain({}, { scrollTop: 50 }, {});
        ptr.start(touch(el, 100));
        ptr.move(touch(el, 500));
        ptr.end();
        assert.equal(calls.refresh, 0);
    });

    it('ignores pulls while body scroll is locked (modal open)', () => {
        const { ptr, calls } = setup({ isLocked: () => true });
        const el = chain({}, {});
        ptr.start(touch(el, 100));
        ptr.move(touch(el, 500));
        ptr.end();
        assert.equal(calls.refresh, 0);
    });

    it('ignores multi-touch (pinch zoom)', () => {
        const { ptr, calls } = setup();
        const el = chain({}, {});
        ptr.start({ ...touch(el, 100), touches: [{ clientY: 100 }, { clientY: 200 }] });
        ptr.move(touch(el, 500));
        ptr.end();
        assert.equal(calls.refresh, 0);
    });

    it('scrolling up first and then down does not start a pull', () => {
        const { ptr, calls } = setup();
        const el = chain({}, {});
        ptr.start(touch(el, 300));
        const up = touch(el, 250);
        ptr.move(up);
        assert.equal(up.prevented, false);
        ptr.move(touch(el, 600));
        ptr.end();
        assert.equal(calls.refresh, 0);
    });

    it('does nothing outside the installed PWA', () => {
        const { ptr, calls } = setup({ win: fakeWindow() });
        const el = chain({}, {});
        ptr.start(touch(el, 100));
        ptr.move(touch(el, 500));
        ptr.end();
        assert.equal(calls.refresh, 0);
    });

    it('a second pull while refreshing is ignored', () => {
        const { ptr, calls } = setup();
        const el = chain({}, {});
        ptr.start(touch(el, 100));
        ptr.move(touch(el, 400));
        ptr.end();
        ptr.start(touch(el, 100));
        ptr.move(touch(el, 400));
        ptr.end();
        assert.equal(calls.refresh, 1);
    });
});
