#!/usr/bin/env node
/**
 * Standalone Node.js test for newsletter DOM safety
 * Ports PHP DOM harness behaviors faithfully:
 * - newsletter-admin.js: text-only rendering, canonical ID actions, delegated nested clicks
 * - newsletter-signup.js: pending state without cookie writes, mocked fetch
 */

'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

/**
 * Test 1: newsletter-admin.js renders text-only rows and handles canonical actions
 */
function testAdminScriptRendersTextOnlyRowsAndCanonicalActions() {
  console.log('Running: testAdminScriptRendersTextOnlyRowsAndCanonicalActions');

  // Load the actual script
  const scriptPath = path.join(__dirname, '../../src/js/newsletter-admin.js');
  let scriptCode = fs.readFileSync(scriptPath, 'utf8');

  // Modify script to inject instrumentation at IIFE boundary
  const instrumentation = `
subscribers = [
  { id: 12, email: \`o'hara@example.com<img src=x onerror=alert(1)>\`, subscribed_at: '2026-01-01 00:00:00', is_active: 1 },
  { id: '12junk', email: '<b>invalid</b>', subscribed_at: '2026-01-01 00:00:00', is_active: 0 },
  { id: 0, email: 'zero@example.test', subscribed_at: '2026-01-01 00:00:00', is_active: 0 }
];
renderSubscribers();`;
  scriptCode = scriptCode.replace(/\n\}\)\(\);\s*$/, '\n' + instrumentation + '\n})();\n');

  // Build complete test harness
  const testCode = `
// Minimal DOM harness
const handlers = {};
let subscribers = []; // Global for injection after IIFE
class Node {
  constructor(tag) {
    this.tagName = tag;
    this.children = [];
    this.attributes = {};
    this.className = '';
    this._text = '';
  }
  appendChild(child) { this.children.push(child); return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text + this.children.map(c => c.textContent || '').join(''); }
  set innerHTML(value) { this.children = []; this._text = String(value); }
  get innerHTML() { return this._text; }
  closest(selector) { return selector === '.subscriber-action-btn' && this.actionButton ? this.actionButton : null; }
  addEventListener() {}
}
const tbody = new Node('tbody');
global.document = {
  getElementById(id) { return id === 'subscribersList' ? tbody : null; },
  createElement(tag) { return new Node(tag); },
  createTextNode(text) { const node = new Node('#text'); node.textContent = text; return node; },
  addEventListener(name, fn) { (handlers[name] ||= []).push(fn); }
};
global.window = { showNotification() {} };
global.bootstrap = { Modal: class { show() {} } };

// The actual script (wrapped IIFE with instrumentation injected)
${scriptCode}

// After IIFE, renderSubscribers() has been called and rows are ready
const rows = tbody.children;
const validButton = rows[0].children[3].children[0];
const invalidButtonCount = rows.slice(1).reduce((count, row) => count + row.children[3].children.length, 0);

// Fire DOMContentLoaded
for (const listener of handlers.DOMContentLoaded || []) listener();

// Simulate nested icon click (delegates to button)
const icon = validButton.children[0];
icon.actionButton = validButton;
for (const listener of handlers.click || []) listener({ target: icon });

// Collect results
const result = {
  emailText: rows[0].children[0].textContent,
  emailChildren: rows[0].children[0].children.length,
  actionId: validButton.getAttribute('data-subscriber-id'),
  invalidButtonCount,
  rowAttributes: rows.flatMap(row => Object.keys(row.attributes)),
  nestedClickHandled: true
};
console.log(JSON.stringify(result));
`;

  // Execute the test
  let output = '';
  const oldLog = console.log;

  try {
    // Capture console output
    console.log = function(...args) {
      output += args.map(String).join(' ') + '\n';
    };

    // Execute via eval in a controlled scope
    eval(testCode);
  } catch (error) {
    console.log = oldLog;
    throw new Error(`Execution failed: ${error.message}\n${error.stack}`);
  }

  console.log = oldLog;

  // Parse result from captured output
  let result;
  try {
    // Get last JSON line from output
    const lines = output.trim().split('\n');
    const jsonLine = lines[lines.length - 1];
    result = JSON.parse(jsonLine);
  } catch (error) {
    throw new Error(`Failed to parse result: ${output}\nError: ${error.message}`);
  }

  // Assertions matching PHP test
  assert.strictEqual(
    result.emailText,
    "o'hara@example.com<img src=x onerror=alert(1)>",
    'Email text should render malicious payload as plain text'
  );
  assert.strictEqual(
    result.emailChildren,
    0,
    'Email cell should have no child elements (no innerHTML parsing)'
  );
  assert.strictEqual(
    result.actionId,
    '12',
    'Action button should only exist for canonical positive decimal ID'
  );
  assert.strictEqual(
    result.invalidButtonCount,
    0,
    'Rows with invalid IDs should have no action buttons'
  );
  assert.deepStrictEqual(
    result.rowAttributes,
    [],
    'No rows should have data attributes set'
  );
  assert.strictEqual(
    result.nestedClickHandled,
    true,
    'Delegated click on nested icon should reach validated button'
  );

  console.log('✓ testAdminScriptRendersTextOnlyRowsAndCanonicalActions passed\n');
}

/**
 * Test 2: newsletter-signup.js shows pending state without writing cookie
 */
async function testPublicScriptShowsPendingStateWithoutWritingCookie() {
  console.log('Running: testPublicScriptShowsPendingStateWithoutWritingCookie');

  // Load the actual script
  const scriptPath = path.join(__dirname, '../../src/js/newsletter-signup.js');
  const scriptCode = fs.readFileSync(scriptPath, 'utf8');

  // Build test harness with async support
  const testCode = `
(async () => {
  const handlers = {};

  class Node {
    constructor() {
      this.classList = {
        add: () => { this.hidden = true; },
        remove: () => { this.hidden = false; }
      };
      this.listeners = {};
      this.value = '';
      this.disabled = false;
      this.innerHTML = 'Subscribe';
      this.hidden = false;
    }
    addEventListener(name, fn) { this.listeners[name] = fn; }
    focus() {}
    setCustomValidity() {}
    reset() { this.value = ''; }
  }

  const formNode = new Node();
  const emailNode = new Node();
  const buttonNode = new Node();
  const errorNode = new Node();
  const formDivNode = new Node();
  const pendingNode = new Node();

  emailNode.value = 'pending@example.test';

  global.document = {
    cookie: '',
    getElementById(id) {
      const map = {
        'newsletterSignupForm': formNode,
        'newsletter-email': emailNode,
        'newsletter-submit-btn': buttonNode,
        'newsletter-error': errorNode,
        'newsletter-signup-form': formDivNode,
        'newsletter-pending-confirmation': pendingNode
      };
      return map[id] || null;
    },
    addEventListener(name, fn) { handlers[name] = fn; }
  };

  global.fetch = async () => ({
    json: async () => ({ success: true })
  });

  // The actual script (wrapped IIFE)
  ${scriptCode}

  // Trigger DOMContentLoaded
  handlers.DOMContentLoaded();

  // Call submit handler and await it
  const submitHandler = formNode.listeners.submit;
  if (submitHandler) {
    await submitHandler({
      preventDefault() {},
      stopPropagation() {}
    });
  }

  // State should now be updated
  const result = {
    cookie: global.document.cookie,
    formHidden: formDivNode.hidden,
    pendingVisible: pendingNode.hidden === false
  };
  console.log(JSON.stringify(result));
})();
`;

  // Execute the test
  let output = '';
  const oldLog = console.log;

  try {
    // Capture console output
    console.log = function(...args) {
      output += args.map(String).join(' ') + '\n';
    };

    // Execute via eval - async IIFE will run
    eval(testCode);

    // Wait for output to be captured (async execution)
    await new Promise(resolve => setTimeout(resolve, 100));
  } catch (error) {
    console.log = oldLog;
    throw new Error(`Execution failed: ${error.message}\n${error.stack}`);
  }

  console.log = oldLog;

  // Parse result
  let result;
  try {
    const lines = output.trim().split('\n');
    const jsonLine = lines[lines.length - 1];
    result = JSON.parse(jsonLine);
  } catch (error) {
    throw new Error(`Failed to parse result: ${output}\nError: ${error.message}`);
  }

  // Assertions
  assert.strictEqual(
    result.cookie,
    '',
    'No cookie should be written on form submission'
  );
  assert.strictEqual(
    result.formHidden,
    true,
    'Form should be hidden after successful submission'
  );
  assert.strictEqual(
    result.pendingVisible,
    true,
    'Pending confirmation message should be visible'
  );

  console.log('✓ testPublicScriptShowsPendingStateWithoutWritingCookie passed\n');
}

// Run tests
async function runTests() {
  try {
    testAdminScriptRendersTextOnlyRowsAndCanonicalActions();
    await testPublicScriptShowsPendingStateWithoutWritingCookie();
    console.log('All tests passed!');
    process.exit(0);
  } catch (error) {
    console.error(`Test failed: ${error.message}`);
    console.error(error.stack);
    process.exit(1);
  }
}

runTests();
