// Run from frontend/: node --test scripts/tests/attribution.test.mjs
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    ATTRIBUTION_RETENTION_MS,
    buildTouch,
    cookieDomainsFor,
    externalReferrer,
    fbcCookieValue,
    fbclidFromFbc,
    fbpCookieValue,
    gclAwCookieValue,
    mergeTouch,
    readClickIds,
    stripMarketingFields,
} from '../../src/utilites/attribution/core.ts';

const NOW = 1_761_000_000_000;

test('without marketing consent only exempt audience data is kept', () => {
    const {touch, isNewSource} = buildTouch({
        url: new URL('https://wawasalegy.com/event/8/konka?utm_source=facebook&utm_medium=paid&fbclid=IwAR1&gclid=Cj0'),
        referrer: 'https://l.facebook.com/l.php?u=secret',
        marketing: false,
        now: NOW,
    });

    assert.deepEqual(touch, {
        utm_source: 'facebook',
        utm_medium: 'paid',
        referrer_domain: 'l.facebook.com',
        landing_path: '/event/8/konka',
        captured_at: NOW,
    });
    assert.equal(isNewSource, true);
    assert.equal(JSON.stringify(touch).includes('IwAR1'), false);
});

test('with marketing consent the landing url and referrer without query are kept', () => {
    const {touch} = buildTouch({
        url: new URL('https://innocent976.yt/event/4/soiree?gclid=Cj0'),
        referrer: 'https://www.google.com/search?q=private',
        marketing: true,
        now: NOW,
    });

    assert.equal(touch.referrer_domain, 'google.com');
    assert.equal(touch.referrer, 'https://www.google.com/search');
    assert.equal(touch.landing_url, 'https://innocent976.yt/event/4/soiree?gclid=Cj0');
});

test('internal, platform and payment referrers are not sources', () => {
    assert.equal(externalReferrer('https://innocent976.yt/', 'www.innocent976.yt'), null);
    assert.equal(externalReferrer('https://ticket.picha.fr/event/4/x', 'innocent976.yt', ['ticket.picha.fr']), null);
    assert.equal(externalReferrer('https://checkout.stripe.com/pay', 'innocent976.yt'), null);
    assert.equal(externalReferrer('android-app://com.facebook', 'innocent976.yt'), null);
    assert.equal(externalReferrer('not a url', 'innocent976.yt'), null);
    assert.equal(externalReferrer('https://m.facebook.com/', 'innocent976.yt')?.host, 'm.facebook.com');

    const {isNewSource} = buildTouch({
        url: new URL('https://innocent976.yt/event/4/x'),
        referrer: 'https://innocent976.yt/',
        marketing: false,
        now: NOW,
    });
    assert.equal(isNewSource, false);
});

test('first touch is kept, last touch changes only with a new source', () => {
    const first = {utm_source: 'sms', landing_path: '/', captured_at: NOW};
    const direct = {landing_path: '/event/1/x', captured_at: NOW + 1000};
    const campaign = {utm_source: 'facebook', landing_path: '/event/1/x', captured_at: NOW + 2000};

    let state = mergeTouch(null, first, true, NOW);
    assert.deepEqual(state, {first_touch: first, last_touch: first});

    state = mergeTouch(state, direct, false, NOW + 1000);
    assert.deepEqual(state.last_touch, first);

    state = mergeTouch(state, campaign, true, NOW + 2000);
    assert.deepEqual(state, {first_touch: first, last_touch: campaign});

    const later = NOW + ATTRIBUTION_RETENTION_MS + 1;
    const fresh = {landing_path: '/', captured_at: later};
    assert.deepEqual(mergeTouch(state, fresh, false, later), {first_touch: fresh, last_touch: fresh});
});

test('withdrawal strips the full urls', () => {
    const touch = {referrer_domain: 'google.com', referrer: 'https://google.com/', landing_path: '/', landing_url: 'https://x.fr/?gclid=1', captured_at: NOW};
    assert.deepEqual(stripMarketingFields({first_touch: touch, last_touch: touch}).last_touch, {
        referrer_domain: 'google.com',
        landing_path: '/',
        captured_at: NOW,
    });
});

test('click ids are validated', () => {
    assert.deepEqual(
        readClickIds(new URLSearchParams('fbclid=IwAR_1-2.3&gclid=<script>&ttclid=E.C.P&wbraid=wb&gbraid=')),
        {fbclid: 'IwAR_1-2.3', ttclid: 'E.C.P', wbraid: 'wb'},
    );
});

test('meta and google cookie formats', () => {
    assert.equal(fbcCookieValue('IwAR1', NOW), `fb.1.${NOW}.IwAR1`);
    assert.match(fbpCookieValue(NOW), /^fb\.1\.\d{13}\.\d{10}$/);
    assert.equal(fbpCookieValue(NOW, () => 0), `fb.1.${NOW}.1000000000`);
    assert.match(fbpCookieValue(NOW, () => 0.9999999999), /^fb\.1\.\d{13}\.\d{10}$/);
    assert.equal(gclAwCookieValue('Cj0', NOW), `GCL.${NOW / 1000}.Cj0`);
    assert.equal(fbclidFromFbc(`fb.1.${NOW}.IwAR1`), 'IwAR1');
    assert.equal(fbclidFromFbc(null), null);
});

test('cookie domains cover the host and its parents', () => {
    assert.deepEqual(cookieDomainsFor('www.innocent976.yt'), ['innocent976.yt']);
    assert.deepEqual(cookieDomainsFor('ticket.picha.fr'), ['ticket.picha.fr', 'picha.fr']);
    assert.deepEqual(cookieDomainsFor('localhost'), []);
});
