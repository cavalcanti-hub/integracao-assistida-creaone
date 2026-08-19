'use strict';

const assert = require('node:assert/strict');

const listeners = {};
class TestMutationObserver {
  constructor(callback) {
    this.callback = callback;
    TestMutationObserver.instance = this;
  }
  observe() {}
}

const feedback = {
  textContent: '',
  classList: {
    values: new Set(['form-feedback']),
    remove(value) {
      this.values.delete(value);
    },
    contains(value) {
      return this.values.has(value);
    }
  }
};
const form = {
  addEventListener(type, callback) {
    listeners[type] = callback;
  }
};

global.MutationObserver = TestMutationObserver;
global.document = {
  addEventListener(type, callback) {
    if (type === 'DOMContentLoaded') callback();
  },
  getElementById(id) {
    if (id === 'open-art-form') return form;
    if (id === 'open-art-feedback') return feedback;
    return null;
  }
};

require('../assets/js/bridge-feedback-guard.js');
assert.equal(typeof listeners.submit, 'function');
assert.ok(TestMutationObserver.instance);

listeners.submit();
feedback.textContent = 'A extensão não confirmou o recebimento do comando.';
feedback.classList.values.add('is-error');
TestMutationObserver.instance.callback();

assert.equal(feedback.textContent, 'Aguardando a extensão buscar o comando...');
assert.equal(feedback.classList.contains('is-error'), false);
process.stdout.write('[OK] Guarda evita erro prematuro de confirmação da extensão.\n');
