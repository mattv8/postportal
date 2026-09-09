#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class ClassList {
  add() {}
  remove() {}
  contains() { return false; }
}

function element(value = '') {
  const listeners = {};
  return {
    value, checked: false, disabled: false, innerHTML: 'Create', type: 'password',
    classList: new ClassList(), dataset: {},
    addEventListener(event, listener) { listeners[event] = listener; },
    async trigger(event, argument = { preventDefault() {} }) { return listeners[event](argument); },
    setCustomValidity() {}, querySelector() { return null; },
  };
}

const fields = {
  username: element('welcome user'), password: element(''), first: element('Welcome'),
  last: element('User'), email: element('welcome@example.test'), isadmin: element(), active: element(),
};
const form = element();
form.checkValidity = () => fields.password.disabled || fields.password.value !== '';
form.reset = () => {};
form.querySelector = (selector) => selector === 'button[type="submit"]' ? directButton : null;
const directButton = element();
const emailButton = element();
const passwordInput = fields.password;
const emailInput = fields.email;
const elements = {
  UserMgmtModal: element(), 'user-mgmt-tbody': element(), createUserForm: form,
  deleteUserModal: element(), confirmDeleteUser: element(), toggleNewPassword: element(),
  generatePasswordBtn: element(), createAndEmailBtn: emailButton, newPassword: passwordInput,
  newEmail: emailInput, newActive: fields.active,
};
const requests = [];

global.FormData = class {
  constructor() {}
  get(name) { return fields[name]?.checked ? 'on' : fields[name]?.value ?? null; }
};
global.document = {
  readyState: 'complete',
  getElementById(id) { return elements[id] || null; },
  querySelector(selector) {
    if (selector === 'meta[name="csrf-token"]') return { content: 'csrf' };
    return null;
  },
  addEventListener() {}, createElement() { return element(); },
};
global.window = { showNotification() {} };
global.fetch = async (url, options) => {
  if (!options.body) return { json: async () => ({ success: true, data: [] }) };
  requests.push({ url, options, body: JSON.parse(options.body) });
  return { json: async () => ({ success: true, email_sent: true }) };
};

vm.runInThisContext(fs.readFileSync('src/js/user-management.js', 'utf8'), { filename: 'user-management.js' });

(async () => {
  await emailButton.trigger('click');
  assert.equal(requests.length, 1, 'Create & Email submits despite required empty password');
  assert.equal(requests[0].body.send_email, true);
  assert.equal(Object.hasOwn(requests[0].body, 'password'), false);
  assert.equal(Object.hasOwn(requests[0].body, 'confirm_password'), false);

  fields.password.value = 'StrongPass1';
  await form.trigger('submit');
  assert.equal(requests.length, 2, 'normal Create still submits');
  assert.equal(requests[1].body.password, 'StrongPass1');
  console.log('user-management welcome behavior passed');
})().catch((error) => { console.error(error); process.exit(1); });
