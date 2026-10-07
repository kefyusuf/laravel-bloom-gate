import http from 'k6/http';
import exec from 'k6/execution';
import { Counter, Trend } from 'k6/metrics';

const rate = Number(__ENV.RATE);
const warmup = Number(__ENV.WARMUP || 30);
const duration = Number(__ENV.DURATION || 60);
const path = __ENV.PATH_MODE;
const counters = Object.fromEntries(['started_iterations', 'completed_iterations', 'responses',
  'present_responses', 'absent_responses', 'errors', 'parity_failures', 'false_negatives',
  'unknown_membership', 'sql_calls', 'redis_calls'].map(n => [n, new Counter(n)]));
const latency = new Trend('measured_http_ms', true);
const query = new Trend('measured_query_ms', true);
const finished = new Trend('measured_finished_ms', true);
const measuredStart = new Trend('measured_start_epoch_ms');

export const options = {
  scenarios: { arrival: { executor: 'constant-arrival-rate', rate, timeUnit: '1s',
    duration: `${warmup + duration}s`, preAllocatedVUs: 128, maxVUs: 128, gracefulStop: '15s' } },
  summaryTrendStats: ['min', 'med', 'p(95)', 'p(99)', 'max'],
};

export default function () {
  const index = exec.scenario.iterationInTest;
  const measured = index >= rate * warmup;
  const present = index % 10 === 0;
  const mixed = Math.imul(index ^ 0x9e3779b9, 0x85ebca6b) >>> 0;
  const selected = ((mixed ^ (mixed >>> 16)) >>> 0) % 1000000;
  const key = present ? `member-${String(selected).padStart(7, '0')}` : `absent-${selected}`;
  if (measured) {
    for (const counter of Object.values(counters)) counter.add(0);
    counters.started_iterations.add(1);
    measuredStart.add(Date.now());
  }
  const reply = http.get(`http://php:8000/measure/${path}?key=${key}`, { timeout: '10s' });
  if (!measured) return;
  counters.completed_iterations.add(1);
  counters.responses.add(1);
  counters[present ? 'present_responses' : 'absent_responses'].add(1);
  latency.add(reply.timings.duration);
  finished.add(Date.now() - exec.scenario.startTime - warmup * 1000);
  try {
    if (reply.status !== 200) throw new Error('HTTP failure');
    const data = reply.json();
    if (!['Bypassed', 'MaybePresent', 'DefinitelyAbsent'].includes(data.membership)) {
      counters.unknown_membership.add(1);
    }
    const optimized = path === 'shared' || path === 'redis';
    if (data.exists !== present || (optimized && data.membership === 'Bypassed') ||
        (!optimized && data.membership !== 'Bypassed') ||
        data.sql_calls !== (data.membership === 'DefinitelyAbsent' ? 0 : 1) ||
        (path !== 'redis' && data.redis_calls !== 0) ||
        (path === 'redis' && !(data.redis_calls >= 1 && data.redis_calls <= 3))) {
      counters.parity_failures.add(1);
    }
    if (present && (data.exists !== true || data.membership === 'DefinitelyAbsent')) counters.false_negatives.add(1);
    counters.sql_calls.add(data.sql_calls);
    counters.redis_calls.add(data.redis_calls);
    query.add(data.query_ms);
  } catch (_) {
    counters.errors.add(1);
  }
}

export function handleSummary(data) {
  const count = name => data.metrics[name] ? data.metrics[name].values.count : 0;
  const trend = name => data.metrics[name] ? data.metrics[name].values : {};
  const elapsed = Math.max(duration, (trend('measured_finished_ms').max || 0) / 1000);
  const cell = { block: Number(__ENV.BLOCK || 0), path: __ENV.CELL_PATH || path, workers: 4,
    offered_rate: rate, absent_percent: 90, warmup_seconds: warmup, duration_seconds: duration,
    elapsed_seconds: elapsed, unfinished_iterations: count('started_iterations') - count('completed_iterations'),
    dropped_iterations: count('dropped_iterations'),
    generator_saturated: count('dropped_iterations') > 0,
    rps: count('responses') / elapsed,
    measurement_start_epoch_ms: trend('measured_start_epoch_ms').min,
    measurement_end_epoch_ms: trend('measured_start_epoch_ms').min + elapsed * 1000,
    p50_ms: trend('measured_http_ms').med, p95_ms: trend('measured_http_ms')['p(95)'],
    p99_ms: trend('measured_http_ms')['p(99)'], query_ms: trend('measured_query_ms') };
  for (const name of Object.keys(counters)) cell[name] = count(name);
  return { [`/results/${__ENV.OUTPUT}.json`]: JSON.stringify({ cell, metrics: data.metrics }, null, 2) };
}
