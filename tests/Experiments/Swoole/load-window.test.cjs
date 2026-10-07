// Exercise the actual load script at its external clock, k6 and HTTP boundaries.
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { test } = require('node:test');

function observe(starts, supplied = {}) {
  const metrics = {};
  class Metric {
    constructor(name) { this.name = name; metrics[name] = []; }
    add(value) { metrics[this.name].push(value); }
  }
  const execution = { scenario: { startTime: 100000, iterationInTest: 0 } };
  let clock = 0;
  let present = false;
  const context = vm.createContext({
    __ENV: { RATE: '10', WARMUP: '1', DURATION: '1', PATH_MODE: 'generator', OUTPUT: 'window-test',
      EXECUTION_TOPOLOGY: 'single-local-constant-arrival' },
    Counter: Metric, Trend: Metric, exec: execution, Date: { now: () => clock },
    http: { get(url) {
      present = url.includes('key=member-');
      return { status: 200, timings: { duration: 1 }, json: () => ({
        exists: present, membership: 'Bypassed', sql_calls: 0, redis_calls: 0,
        controlled_delay_ms: 0, query_ms: 1,
      }) };
    } },
  });
  const script = readFileSync(join(__dirname, 'load.js'), 'utf8')
    .replace(/^import .*;\r?\n/gm, '')
    .replace('export const options', 'const options')
    .replace('export default function ()', 'function runIteration()')
    .replace('export function handleSummary', 'function handleSummary');
  vm.runInContext(script, context);
  for (const [index, timestamp] of starts) {
    execution.scenario.iterationInTest = index;
    clock = timestamp;
    vm.runInContext('runIteration()', context);
  }
  const counts = Object.fromEntries(Object.entries(metrics).map(([name, values]) =>
    [name, values.reduce((sum, value) => sum + value, 0)]));
  context.summary = { metrics: Object.fromEntries(Object.entries(counts)
    .map(([name, count]) => [name, { values: { count } }])) };
  Object.assign(context.summary.metrics, supplied);
  const output = vm.runInContext('handleSummary(summary)', context);
  return { counts, cell: JSON.parse(output['/results/window-test.json']).cell };
}

test('retains warmup work crossing into the actual window without relabeling latency', () => {
  const { counts: metrics } = observe([[9, 101000], [10, 101001], [19, 102000]]);
  assert.equal(metrics.started_iterations, 2);
  assert.equal(metrics.audit_scheduled_started, 2);
  assert.equal(metrics.audit_scheduled_completed, 2);
  assert.equal(metrics.audit_warmup_entered, 1);
  assert.equal(metrics.audit_scheduled_outside, 1);
  assert.equal(metrics.audit_extra_entered, 0);
});

test('does not interpret indices as scheduled work when builtin completion evidence is absent', () => {
  const { cell } = observe([[0, 100000], [10, 101000]]);
  assert.equal(cell.window_audit.index_interpretation, 'unavailable');
});

test('reports exclusive terminal and late crossings while preserving actual-window counts', () => {
  const { cell } = observe([[0, 100000], [9, 101000], [10, 101001], [19, 102000], [20, 101999]], {
    iterations: { values: { count: 5 } }, http_reqs: { values: { count: 5 } },
  });
  assert.equal(cell.started_iterations, 3);
  assert.equal(cell.window_audit.index_interpretation, 'zero-drop-local-only');
  assert.equal(cell.window_audit.scheduled_started, 2);
  assert.equal(cell.window_audit.scheduled_completed, 2);
  assert.equal(cell.window_audit.warmup_entered, 1);
  assert.equal(cell.window_audit.extra_entered, 1);
  assert.equal(cell.window_audit.scheduled_outside, 1);
  assert.equal(cell.window_audit.scheduled_early, 0);
  assert.equal(cell.window_audit.scheduled_late, 1);
});

test('any drop prevents scheduled-index interpretation even with matching completed totals', () => {
  const { cell } = observe([[0, 100000], [10, 101000]], {
    iterations: { values: { count: 2 } }, http_reqs: { values: { count: 2 } },
    dropped_iterations: { values: { count: 1 } },
  });
  assert.equal(cell.window_audit.index_interpretation, 'unavailable');
  assert.equal(cell.dropped_iterations, 1);
});

test('emits the versioned contract with explicit builtin observations rather than inferred zeroes', () => {
  const { cell } = observe([[0, 100000], [10, 101000]], {
    iterations: { values: { count: 2 } }, http_reqs: { values: { count: 2 } },
  });
  assert.equal(cell.measurement_contract, 'scheduled-completeness-actual-window-v1');
  assert.equal(cell.execution_topology, 'single-local-constant-arrival');
  assert.equal(cell.builtin_iterations, 2);
  assert.equal(cell.builtin_http_requests, 2);
  const missing = observe([[0, 100000]]).cell;
  assert.equal(missing.builtin_iterations, null);
  assert.equal(missing.builtin_http_requests, null);
});
