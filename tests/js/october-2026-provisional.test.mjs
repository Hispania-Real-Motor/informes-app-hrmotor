import test from 'node:test';
import assert from 'node:assert/strict';
import { bindProvisionalDistributionControls } from '../../resources/js/reports/october-2026-provisional.js';

function control(value = '') {
    const listeners = new Map();

    return {
        value,
        disabled: false,
        textContent: '',
        selectedOptions: [{ textContent: 'Delegación de procedencia' }],
        addEventListener(name, listener) {
            listeners.set(name, listener);
        },
        trigger(name) {
            listeners.get(name)();
        },
    };
}

function panel(distribution, labels) {
    const rows = labels.map((label) => ({ dataset: { provisionalLabel: label }, hidden: false }));

    return {
        dataset: { provisionalDistribution: distribution },
        hidden: false,
        rows,
        querySelectorAll(selector) {
            return selector === '[data-provisional-row]' ? rows : [];
        },
    };
}

function collection(panels) {
    return {
        hidden: false,
        querySelectorAll(selector) {
            return selector === '[data-provisional-distribution]' ? panels : [];
        },
    };
}

function fixture() {
    const dimension = control();
    const search = control();
    const reset = control();
    const selection = control();
    const panels = [panel('delegation', ['Madrid General', 'Torrejón']), panel('status', ['Potencial', 'Convertido'])];
    const collections = [collection([panels[0]]), collection([panels[1]])];
    const elements = new Map([
        ['provisionalDimension', dimension],
        ['provisionalSearch', search],
        ['resetProvisionalFilters', reset],
        ['provisionalSelection', selection],
    ]);
    const root = {
        getElementById(id) {
            return elements.get(id);
        },
        querySelectorAll(selector) {
            if (selector === '[data-provisional-distribution]') return panels;

            return selector === '[data-provisional-collection]' ? collections : [];
        },
    };

    bindProvisionalDistributionControls(root);

    return { dimension, search, reset, selection, panels, collections };
}

test('initial exploration state exposes all precalculated distributions and disables value search', () => {
    const { panels, search, selection } = fixture();

    assert.deepEqual(panels.map((item) => item.hidden), [false, false]);
    assert.equal(search.disabled, true);
    assert.match(selection.textContent, /Seleccione una distribución/);
});

test('selecting a distribution shows only its independently aggregated panel', () => {
    const { dimension, panels, collections, search, selection } = fixture();

    dimension.value = 'status';
    dimension.selectedOptions = [{ textContent: 'Estado' }];
    dimension.trigger('change');

    assert.deepEqual(panels.map((item) => item.hidden), [true, false]);
    assert.deepEqual(collections.map((item) => item.hidden), [true, false]);
    assert.equal(search.disabled, false);
    assert.equal(selection.textContent, 'Mostrando la distribución: Estado.');
});

test('value search affects only the selected distribution and switching it clears the prior search', () => {
    const { dimension, search, panels } = fixture();

    dimension.value = 'delegation';
    dimension.trigger('change');
    search.value = 'torre';
    search.trigger('input');

    assert.deepEqual(panels[0].rows.map((row) => row.hidden), [true, false]);
    assert.deepEqual(panels[1].rows.map((row) => row.hidden), [false, false]);

    dimension.value = 'status';
    dimension.trigger('change');

    assert.equal(search.value, '');
    assert.deepEqual(panels[1].rows.map((row) => row.hidden), [false, false]);
});

test('clearing the exploration controls restores all panels and values', () => {
    const { dimension, search, reset, panels, collections } = fixture();

    dimension.value = 'delegation';
    dimension.trigger('change');
    search.value = 'madrid';
    search.trigger('input');
    reset.trigger('click');

    assert.equal(dimension.value, '');
    assert.equal(search.value, '');
    assert.equal(search.disabled, true);
    assert.deepEqual(panels.map((item) => item.hidden), [false, false]);
    assert.deepEqual(collections.map((item) => item.hidden), [false, false]);
    assert.deepEqual(panels.flatMap((item) => item.rows).map((row) => row.hidden), [false, false, false, false]);
});
