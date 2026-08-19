'use strict';

const assert = require('node:assert/strict');

class TestEvent {
  constructor(type, options = {}) {
    this.type = type;
    this.bubbles = Boolean(options.bubbles);
  }
}

class TestInput {
  constructor() {
    this.tagName = 'INPUT';
    this.currentValue = '';
    this.events = [];
    this.focused = false;
    this.ownerDocument = {
      defaultView: { HTMLInputElement: TestInput, Event: TestEvent }
    };
  }

  get value() {
    return this.currentValue;
  }

  set value(value) {
    this.currentValue = String(value);
  }

  dispatchEvent(event) {
    this.events.push([event.type, event.bubbles]);
    return true;
  }

  focus() {
    this.focused = true;
  }
}

require('../browser-extension/preparer.js');

const input = new TestInput();
const root = {
  getElementById(id) {
    assert.equal(id, 'MainContent_Main_NumeroART_NumeroARTTxt');
    return input;
  }
};
const result = globalThis.AtlanticaArtPreparer.prepareArtInput(root, '28027230230943447');
assert.deepEqual(result, { ok: true, art: '28027230230943447' });
assert.equal(input.value, '28027230230943447');
assert.deepEqual(input.events, [['input', true], ['change', true]]);
assert.equal(input.focused, true);

const rejected = globalThis.AtlanticaArtPreparer.prepareArtInput(root, 'ART-123');
assert.equal(rejected.ok, false);
assert.equal(input.events.length, 2);

process.stdout.write('[OK] Campo da ART preenchido; somente eventos input e change disparados.\n');
