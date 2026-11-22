// Run from frontend/: node --test scripts/tests/serverClock.test.mjs
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {millisecondsUntil, parseUtcTimestamp, serverNow} from '../../src/utilites/serverClock.ts';

const RESERVED_UNTIL = '2026-11-16 10:47:00';
const SERVER_NOW = Date.UTC(2026, 10, 16, 10, 32, 0);
const TWO_HOURS = 2 * 60 * 60 * 1000;

test('SQL dates from the API are read as UTC', () => {
    assert.equal(parseUtcTimestamp(RESERVED_UNTIL), Date.UTC(2026, 10, 16, 10, 47, 0));
    assert.equal(parseUtcTimestamp('2026-11-16T10:47:00.000000Z'), Date.UTC(2026, 10, 16, 10, 47, 0));
    assert.equal(parseUtcTimestamp('2026-11-16T13:47:00+03:00'), Date.UTC(2026, 10, 16, 10, 47, 0));
    assert.equal(parseUtcTimestamp(''), null);
    assert.equal(parseUtcTimestamp('not a date'), null);
});

test('a phone two hours ahead used to see a fresh order as already expired', () => {
    const phoneNow = SERVER_NOW + TWO_HOURS;

    assert.ok(millisecondsUntil(RESERVED_UNTIL, 0, phoneNow) < 0);
});

test('with the server clock offset the buyer keeps the full 15 minutes', () => {
    const phoneNow = SERVER_NOW + TWO_HOURS;
    const offset = -TWO_HOURS;

    assert.equal(serverNow(offset, phoneNow), SERVER_NOW);
    assert.equal(millisecondsUntil(RESERVED_UNTIL, offset, phoneNow), 15 * 60 * 1000);
});

test('a phone running late does not get extra time', () => {
    const phoneNow = SERVER_NOW + 20 * 60 * 1000 - TWO_HOURS;

    assert.ok(millisecondsUntil(RESERVED_UNTIL, TWO_HOURS, phoneNow) < 0);
});
