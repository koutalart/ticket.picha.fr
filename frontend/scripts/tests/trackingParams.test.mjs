// Run from frontend/: node --test scripts/tests/trackingParams.test.mjs
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {forwardTrackingParams} from '../../src/ssr/trackingParams.js';

const BASE = 'https://ticket.picha.fr';

test('the ad click survives the QR redirect', () => {
    const url = new URL(forwardTrackingParams(
        'https://innocent976.yt/event/7/halloween?qr=7b4dbdd&qrc=1',
        {fbclid: 'IwAR1abc', utm_source: 'facebook', utm_medium: 'paid', utm_campaign: 'halloween', ScCid: 'snap1', gclid: 'Cj0', ttclid: 'tt1', other: 'x'},
        BASE,
    ));

    assert.equal(url.searchParams.get('qr'), '7b4dbdd');
    assert.equal(url.searchParams.get('qrc'), '1');
    assert.equal(url.searchParams.get('fbclid'), 'IwAR1abc');
    assert.equal(url.searchParams.get('utm_source'), 'facebook');
    assert.equal(url.searchParams.get('utm_medium'), 'paid');
    assert.equal(url.searchParams.get('utm_campaign'), 'halloween');
    assert.equal(url.searchParams.get('ScCid'), 'snap1');
    assert.equal(url.searchParams.get('gclid'), 'Cj0');
    assert.equal(url.searchParams.get('ttclid'), 'tt1');
    assert.equal(url.searchParams.has('other'), false);
});

test('parameters already on the destination are kept', () => {
    const url = new URL(forwardTrackingParams('https://example.com/?utm_source=qr', {utm_source: 'facebook'}, BASE));
    assert.equal(url.searchParams.get('utm_source'), 'qr');
});

test('arrays, empty and oversized values are ignored', () => {
    const redirect = 'https://example.com/event/7/halloween?qr=abcd';
    assert.equal(forwardTrackingParams(redirect, {fbclid: ['a', 'b'], utm_source: '', gclid: 'x'.repeat(501)}, BASE), redirect);
    assert.equal(forwardTrackingParams(redirect, undefined, BASE), redirect);
});

test('relative destinations resolve on the platform and non web destinations are untouched', () => {
    assert.equal(forwardTrackingParams('/event/7/halloween', {fbclid: 'IwAR1'}, BASE), 'https://ticket.picha.fr/event/7/halloween?fbclid=IwAR1');
    assert.equal(forwardTrackingParams('itms-apps://apps.apple.com/app/id1', {fbclid: 'IwAR1'}, BASE), 'itms-apps://apps.apple.com/app/id1');
});
