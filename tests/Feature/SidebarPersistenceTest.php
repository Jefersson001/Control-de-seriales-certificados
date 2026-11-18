<?php

use App\Models\User;
use Illuminate\Support\Facades\Process;

test('the sidebar remembers its state across navigation saves and screen sizes', function () {
    $script = <<<'JS'
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const app = fs.readFileSync(process.argv[1], 'utf8');
const layout = fs.readFileSync(process.argv[2], 'utf8');
const bootstrap = layout.match(/<script>([\s\S]*?)<\/script>/)[1];
const saved = new Map();

class Classes {
    constructor(values = []) { this.values = new Set(values); }
    contains(value) { return this.values.has(value); }
    toggle(value, force) {
        const enabled = force ?? !this.values.has(value);
        if (enabled) this.values.add(value); else this.values.delete(value);
        return enabled;
    }
}
class Element extends EventTarget {
    constructor(classes = []) { super(); this.classList = new Classes(classes); this.attributes = {}; this.dataset = {}; }
    setAttribute(key, value) { this.attributes[key] = value; }
}
function browser(wide, blocked = false) {
    const desktop = new EventTarget();
    desktop.matches = wide;
    const root = new Element();
    const document = new EventTarget();
    document.documentElement = root;
    document.replacePage = () => {
        document.elements = {
            '[data-sidebar]': new Element(['-translate-x-full', 'lg:translate-x-0']),
            '[data-sidebar-overlay]': new Element(['hidden']),
            '[data-sidebar-toggle]': new Element(),
            '[data-dashboard-content]': new Element(['lg:pl-72']),
            '[data-theme-toggle]': new Element(),
        };
    };
    document.replacePage();
    document.querySelector = (selector) => document.elements[selector] ?? null;
    document.querySelectorAll = (selector) => document.elements[selector] ? [document.elements[selector]] : [];
    const localStorage = {
        getItem(key) { if (blocked) throw new Error('Storage unavailable'); return saved.get(key) ?? null; },
        setItem(key, value) { if (blocked) throw new Error('Storage unavailable'); saved.set(key, value); },
    };
    const window = { matchMedia: (query) => query.includes('1024px') ? desktop : { matches: false } };
    const context = vm.createContext({ document, window, localStorage, AbortController });
    vm.runInContext(bootstrap, context);
    const initialState = root.dataset.sidebarState;
    vm.runInContext(app, context);
    const toggle = () => document.elements['[data-sidebar-toggle]'].dispatchEvent(new Event('click'));
    const navigate = () => { document.replacePage(); document.dispatchEvent(new Event('livewire:navigated')); };
    const state = () => root.dataset.sidebarState;
    const assertClosed = () => {
        assert.equal(state(), 'closed');
        assert.equal(document.elements['[data-sidebar-toggle]'].attributes['aria-expanded'], 'false');
        assert.equal(document.elements['[data-dashboard-content]'].classList.contains('lg:pl-72'), false);
    };
    return { desktop, document, root, initialState, toggle, navigate, state, assertClosed, context };
}

const first = browser(true);
assert.equal(first.state(), 'open');
first.toggle();
first.assertClosed();
first.navigate();
first.assertClosed();

const afterSave = browser(true);
assert.equal(afterSave.initialState, 'closed');
afterSave.assertClosed();
vm.runInContext(bootstrap, afterSave.context);
afterSave.document.dispatchEvent(new Event('livewire:navigated'));
afterSave.toggle();
assert.equal(afterSave.state(), 'open');
afterSave.document.elements['[data-theme-toggle]'].dispatchEvent(new Event('click'));
assert.equal(afterSave.root.classList.contains('dark'), true);
assert.equal(saved.get('theme'), 'dark');

const opened = browser(true);
assert.equal(opened.initialState, 'open');
opened.document.dispatchEvent(Object.assign(new Event('keydown'), { key: 'Escape' }));
opened.assertClosed();
opened.desktop.matches = false;
opened.desktop.dispatchEvent(new Event('change'));
opened.assertClosed();
opened.toggle();
assert.equal(opened.state(), 'open');
opened.desktop.matches = true;
opened.desktop.dispatchEvent(new Event('change'));
opened.assertClosed();
const mobile = browser(false);
assert.equal(mobile.initialState, 'open');
mobile.document.elements['[data-sidebar-overlay]'].dispatchEvent(new Event('click'));
mobile.assertClosed();
mobile.navigate();
mobile.assertClosed();

const restricted = browser(true, true);
restricted.toggle();
restricted.assertClosed();
restricted.navigate();
restricted.assertClosed();
console.log('Sidebar persistence checks passed');
JS;

    $result = Process::timeout(20)->run(['node', '--input-type=commonjs', '-e', $script, resource_path('js/app.js'), resource_path('views/layouts/dashboard.blade.php')]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Sidebar persistence checks passed');
});

test('the dashboard restores the sidebar preference before rendering navigation', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertSuccessful()
        ->assertSeeHtmlInOrder(['dashboard.sidebar.desktop', 'dataset.sidebarState', 'data-sidebar-overlay', 'data-sidebar-toggle']);
});
