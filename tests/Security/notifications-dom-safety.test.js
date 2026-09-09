#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

class Node {
  constructor(tagName) {
    this.tagName = tagName;
    this.children = [];
    this.attributes = {};
    this.className = '';
    this.style = {};
    this.listeners = {};
    this.disabled = false;
    this._text = '';
    this.classList = { add: () => {} };
  }

  appendChild(child) { this.children.push(child); return child; }
  insertAdjacentHTML(_position, html) {
    const toast = new Node('div');
    const toastMatch = html.match(/<div id="([^"]+)" class="([^"]+)"/);
    if (toastMatch) {
      toast.setAttribute('id', toastMatch[1]);
      toast.className = toastMatch[2];
    }
    const body = toast.appendChild(new Node('div'));
    body.className = 'toast-body';
    const message = body.appendChild(new Node('div'));
    const messageMatch = html.match(/<div class="toast-body">\s*<div>([\s\S]*?)<\/div>/);
    message.textContent = messageMatch ? messageMatch[1].replace(/<br>/g, '\n') : '';
    for (const tag of message.textContent.matchAll(/<(img|svg)\b/gi)) message.appendChild(new Node(tag[1].toLowerCase()));
    const labelMatch = html.match(/class="btn btn-sm btn-light toast-action-btn w-100">([\s\S]*?)<\/button>/);
    if (labelMatch) {
      const button = body.appendChild(new Node('button'));
      button.className = 'btn btn-sm btn-light toast-action-btn w-100';
      button.textContent = labelMatch[1];
      for (const tag of button.textContent.matchAll(/<(img|svg)\b/gi)) button.appendChild(new Node(tag[1].toLowerCase()));
    }
    this.appendChild(toast);
  }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] || null; }
  addEventListener(name, listener) { this.listeners[name] = listener; }
  querySelector(selector) {
    const className = selector.startsWith('.') ? selector.slice(1) : null;
    const id = selector.startsWith('#') ? selector.slice(1) : null;
    const dataHook = selector.match(/^\[([^\]]+)\]$/);
    for (const child of this.children) {
      if ((className && child.className.split(/\s+/).includes(className)) || (id && child.attributes.id === id) || (dataHook && Object.hasOwn(child.attributes, dataHook[1]))) return child;
      const nested = child.querySelector(selector);
      if (nested) return nested;
    }
    return null;
  }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
}

const body = new Node('body');
const document = {
  body,
  createElement: tagName => new Node(tagName),
  querySelector: selector => body.querySelector(selector),
  getElementById: id => body.querySelector('#' + id),
};
global.document = document;
global.window = { location: { search: '' } };
global.bootstrap = { Toast: class { constructor(element) { this.element = element; } show() {} hide() {} } };

const script = fs.readFileSync(path.join(__dirname, '../../src/js/notifications.js'), 'utf8');
eval(script);

let callbackCount = 0;
window.showNotification('Message <img src=x onerror=messageHandler()>\nSecond line', 'warning', {
  label: 'Action <svg onload=labelHandler()>',
  callback: () => { callbackCount++; },
});

const toast = body.querySelector('[data-toast-boundary]');
const message = body.querySelector('[data-toast-message]');
const action = body.querySelector('[data-toast-action]');
assert.ok(toast, 'Toast has a stable boundary hook');
assert.ok(message, 'Toast message has a stable semantic hook');
assert.ok(action, 'Toast action has a stable semantic hook');
assert.strictEqual(message.textContent, 'Message <img src=x onerror=messageHandler()>\nSecond line');
assert.strictEqual(action.textContent, 'Action <svg onload=labelHandler()>');
assert.strictEqual(body.querySelector('img'), null, 'Message HTML must not create elements');
assert.strictEqual(body.querySelector('svg'), null, 'Action label HTML must not create elements');
assert.deepStrictEqual(Object.keys(toast.attributes).filter(name => name.startsWith('on')), [], 'Toast must not receive injected event attributes');
action.listeners.click();
assert.strictEqual(callbackCount, 1, 'Action callback remains functional');

window.showEmailError({
  error: 'API error <iframe srcdoc="<img onerror=apiErrorHandler()>"></iframe>',
  actionRequired: 'none',
});
const apiErrorToast = body.querySelector('[data-notification-toast-container]').children[1];
assert.strictEqual(
  apiErrorToast.querySelector('iframe'),
  null,
  'API-provided error HTML must not create elements'
);
assert.strictEqual(
  apiErrorToast.querySelector('[data-toast-message]').textContent,
  'API error <iframe srcdoc="<img onerror=apiErrorHandler()>"></iframe>'
);

console.log('notifications DOM safety passed');
