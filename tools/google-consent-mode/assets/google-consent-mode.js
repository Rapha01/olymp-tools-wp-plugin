/**
 * Olymp Tools — Google Consent Mode front-end bridge
 *
 * Translates the consent a visitor gave in Complianz into Google Consent Mode.
 * Printed INLINE at the very top of <head> by Olymp_Tool_Google_Consent_Mode,
 * i.e. before the GTM container — it must never be enqueued, deferred or moved.
 *
 * Config comes from the inline `olympGcm` variable printed right before it:
 *   cookiePrefix  Complianz cookie prefix, usually "cmplz_"
 *   policyId      active Complianz cookie policy id ('' = unknown → no check)
 *   interval      how often the consent cookies are compared (ms)
 *
 * 1. Sends the Consent Mode "default": everything denied except the strictly
 *    necessary functionality/security storage.
 * 2. Reads the choice Complianz has already stored in its category cookies and,
 *    if it differs from the default, sends an "update" right away. This happens
 *    synchronously, before the container loads, so a returning visitor's known
 *    choice applies before any Google tag runs — no race against the load time
 *    of complianz.js.
 * 3. Keeps comparing the cookies and sends the complete state as an "update"
 *    whenever it changes, so accepting, rejecting or withdrawing consent on the
 *    current page takes effect immediately.
 *
 * The cookies are the only thing this relies on. They are the most stable part
 * of Complianz: renaming them would invalidate every stored consent. Only an
 * explicit "allow" counts; anything else (missing cookie, "deny", consent for
 * an outdated cookie policy) is treated as denied.
 */
(function (w, d, cfg) {
    'use strict';

    if (!cfg || w.olympGcmRunning) {
        return;
    }
    w.olympGcmRunning = true;

    var DENIED = 'denied';
    var GRANTED = 'granted';

    w.dataLayer = w.dataLayer || [];

    function gtag() {
        w.dataLayer.push(arguments);
    }

    /** Value of a Complianz cookie ("allow", "deny", …) or '' when absent. */
    function readCookie(name) {
        var parts = ('; ' + d.cookie).split('; ' + cfg.cookiePrefix + name + '=');
        if (parts.length < 2) {
            return '';
        }
        var value = parts.pop().split(';')[0];
        try {
            return decodeURIComponent(value);
        } catch (e) {
            return value;
        }
    }

    /**
     * Consent Mode state derived from the Complianz cookies. Consent given for
     * an older cookie policy is discarded by Complianz itself, so it is not
     * honoured here either.
     */
    function consentState() {
        var policyOk = !cfg.policyId || readCookie('policy_id') === cfg.policyId;
        var statistics = policyOk && readCookie('statistics') === 'allow';
        var marketing = policyOk && readCookie('marketing') === 'allow';
        var preferences = policyOk && readCookie('preferences') === 'allow';

        return {
            analytics_storage: statistics ? GRANTED : DENIED,
            ad_storage: marketing ? GRANTED : DENIED,
            ad_user_data: marketing ? GRANTED : DENIED,
            ad_personalization: marketing ? GRANTED : DENIED,
            personalization_storage: preferences ? GRANTED : DENIED
        };
    }

    /** Compact fingerprint of a state, used to detect changes. */
    function signature(state) {
        return state.analytics_storage + '|' + state.ad_storage + '|' + state.personalization_storage;
    }

    var defaults = {
        analytics_storage: DENIED,
        ad_storage: DENIED,
        ad_user_data: DENIED,
        ad_personalization: DENIED,
        personalization_storage: DENIED,
        functionality_storage: GRANTED,
        security_storage: GRANTED
    };

    gtag('consent', 'default', defaults);

    var last = signature(defaults);

    function sync() {
        var state = consentState();
        var sig = signature(state);
        if (sig !== last) {
            last = sig;
            gtag('consent', 'update', state);
        }
    }

    // Stored choice of a returning visitor: applied before the container loads.
    sync();

    // Changes on the current page (accept, reject, withdraw).
    w.setInterval(sync, cfg.interval);
})(window, document, window.olympGcm);
