import http from 'k6/http';
import exec from 'k6/execution';
import { Counter, Trend } from 'k6/metrics';

const rate = Number(__ENV.RATE);
const warmup = Number(__ENV.WARMUP || 30);
const duration = Number(__ENV.DURATION || 60);
const path = __ENV.PATH_MODE;
const vus = Number(__ENV.VUS || 128);
const controlledDelay = Number(__ENV.CONTROLLED_DELAY_MS || 0);
if (!Number.isInteger(vus) || vus < 1 || vus > 2048) throw new Error('Invalid fixed VU budget');
const counters = Object.fromEntries(['started_iterations', 'completed_iterations', 'responses',
  'present_responses', 'absent_responses', 'errors', 'parity_failures', 'false_negatives',
  'unknown_membership', 'sql_calls', 'redis_calls'].map(n => [n, new Counter(n)]));
const latency = new Trend('measured_http_ms', true);
const query = new Trend('measured_query_ms', true);
const finished = new Trend('measured_finished_ms', true);
const measuredStart = new Trend('measured_start_epoch_ms');
const audit = Object.fromEntries(['scheduled_started', 'scheduled_completed', 'warmup_entered',
  'scheduled_outside', 'scheduled_early', 'scheduled_late', 'extra_entered']
  .map(n => [n, new Counter(`audit_${n}`)]));
const startOffset = new Trend('audit_start_offset_ms');
const crossingOffset = new Trend('audit_crossing_offset_ms');
const totals = Object.fromEntries(['started_iterations', 'completed_iterations', 'errors',
  'parity_failures', 'false_negatives', 'unknown_membership', 'delay_mismatches']
  .map(n => [n, new Counter(`total_${n}`)]));

export const options = {
  // Do not create a time series for each synthetic lookup key.
  systemTags: ['status', 'method', 'name', 'group', 'check', 'error', 'error_code', 'scenario', 'expected_response'],
  scenarios: { arrival: { executor: 'constant-arrival-rate', rate, timeUnit: '1s',
    duration: `${warmup + duration}s`, preAllocatedVUs: vus, maxVUs: vus, gracefulStop: '15s' } },
  summaryTrendStats: ['min', 'med', 'p(95)', 'p(99)', 'max'],
};

export default function () {
  const index = exec.scenario.iterationInTest;
  const measurementStart = exec.scenario.startTime + warmup * 1000;
  const startedAt = Date.now();
  const measured = startedAt >= measurementStart && startedAt < measurementStart + duration * 1000;
  // Diagnostic cohorts only: indices are not scheduled slots when any work drops.
  const scheduled = index >= rate * warmup && index < rate * (warmup + duration);
  const offset = startedAt - measurementStart;
  const record = (name, amount = 1) => {
    if (totals[name]) totals[name].add(amount);
    if (measured && counters[name]) counters[name].add(amount);
  };
  if (index === 0) {
    for (const counter of [...Object.values(counters), ...Object.values(totals), ...Object.values(audit)]) counter.add(0);
  }
  startOffset.add(offset);
  if (scheduled) audit.scheduled_started.add(1);
  if (index < rate * warmup && measured) audit.warmup_entered.add(1);
  if (index >= rate * (warmup + duration) && measured) audit.extra_entered.add(1);
  if (scheduled && !measured) {
    audit.scheduled_outside.add(1);
    audit[offset < 0 ? 'scheduled_early' : 'scheduled_late'].add(1);
  }
  if (scheduled !== measured) crossingOffset.add(offset);
  const present = index % 10 === 0;
  const mixed = Math.imul(index ^ 0x9e3779b9, 0x85ebca6b) >>> 0;
  const selected = ((mixed ^ (mixed >>> 16)) >>> 0) % 1000000;
  const key = present ? `member-${String(selected).padStart(7, '0')}` : `absent-${selected}`;
  record('started_iterations');
  if (measured) {
    measuredStart.add(measurementStart);
  }
  const suffix = path === 'generator' ? `&delay=${controlledDelay}` : '';
  const reply = http.get(`http://php:8000/measure/${path}?key=${key}${suffix}`, { timeout: '10s', tags: { name: `measure/${path}` } });
  record('completed_iterations');
  if (scheduled) audit.scheduled_completed.add(1);
  record('responses');
  record(present ? 'present_responses' : 'absent_responses');
  if (measured) {
    latency.add(reply.timings.duration);
    finished.add(Date.now() - exec.scenario.startTime - warmup * 1000);
  }
  try {
    if (reply.status !== 200) throw new Error('HTTP failure');
    const data = reply.json();
    if (!['Bypassed', 'MaybePresent', 'DefinitelyAbsent'].includes(data.membership)) {
      record('unknown_membership');
    }
    const optimized = path === 'shared' || path === 'redis';
    if (data.exists !== present || (optimized && data.membership === 'Bypassed') ||
        (!optimized && data.membership !== 'Bypassed') ||
        data.sql_calls !== (path === 'generator' || data.membership === 'DefinitelyAbsent' ? 0 : 1) ||
        (path !== 'redis' && data.redis_calls !== 0) ||
        (path === 'redis' && !(data.redis_calls >= 1 && data.redis_calls <= 3))) {
      record('parity_failures');
    }
    if (present && (data.exists !== true || data.membership === 'DefinitelyAbsent')) record('false_negatives');
    if (path === 'generator' && (data.controlled_delay_ms !== controlledDelay ||
        !Number.isFinite(data.query_ms) || data.query_ms < controlledDelay)) record('delay_mismatches');
    record('sql_calls', data.sql_calls);
    record('redis_calls', data.redis_calls);
    if (measured) query.add(data.query_ms);
  } catch (_) {
    record('errors');
  }
}

export function handleSummary(data) {
  const count = name => data.metrics[name] ? data.metrics[name].values.count : 0;
  const trend = name => data.metrics[name] ? data.metrics[name].values : {};
  const elapsed = Math.max(duration, (trend('measured_finished_ms').max || 0) / 1000);
  const cell = { block: Number(__ENV.BLOCK || 0), path: __ENV.CELL_PATH || path, workers: 4, vus,
    controlled_delay_ms: controlledDelay,
    offered_rate: rate, absent_percent: 90, warmup_seconds: warmup, duration_seconds: duration,
    window_basis: 'scenario-start-time',
    elapsed_seconds: elapsed, unfinished_iterations: count('started_iterations') - count('completed_iterations'),
    dropped_iterations: count('dropped_iterations'),
    generator_saturated: count('dropped_iterations') > 0,
    rps: count('responses') / elapsed,
    measurement_start_epoch_ms: trend('measured_start_epoch_ms').min,
    measurement_end_epoch_ms: trend('measured_start_epoch_ms').min + elapsed * 1000,
    p50_ms: trend('measured_http_ms').med, p95_ms: trend('measured_http_ms')['p(95)'],
    p99_ms: trend('measured_http_ms')['p(99)'], query_ms: trend('measured_query_ms') };
  for (const name of Object.keys(counters)) cell[name] = count(name);
  for (const name of Object.keys(totals)) cell[`total_${name}`] = count(`total_${name}`);
  const indexObserved = count('dropped_iterations') === 0 && cell.total_started_iterations > 0
    && cell.total_started_iterations === cell.total_completed_iterations
    && data.metrics.iterations && count('iterations') === cell.total_completed_iterations
    && data.metrics.http_reqs && count('http_reqs') === cell.total_completed_iterations;
  cell.window_audit = {
    index_interpretation: indexObserved ? 'zero-drop-local-only' : 'unavailable',
    start_offset_ms: trend('audit_start_offset_ms'),
    crossing_offset_ms: trend('audit_crossing_offset_ms'),
  };
  for (const name of Object.keys(audit)) cell.window_audit[name] = count(`audit_${name}`);
  return { [`/results/${__ENV.OUTPUT}.json`]: JSON.stringify({ cell, metrics: data.metrics }, null, 2) };
}
