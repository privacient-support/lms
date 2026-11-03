<?php
/**
 * Sign a learner in through their company's SAML IdP and hand them back to the
 * Privacient portal.
 *
 * The portal cannot verify a SAML assertion itself and should not try: IOMAD
 * already ships `auth_iomadsaml2` on top of SimpleSAMLphp, and XML signature
 * verification is the part of SAML that implementations get broken into. So
 * IOMAD does the authentication and this page vouches for the result.
 *
 * Handoff is an HMAC over the same "{timestamp}.{body}" scheme the progress
 * callback uses, bound to a `state` the portal generated and kept in its own
 * cookie. Replaying a captured handoff URL therefore fails: the attacker has
 * the token but not the cookie it must match.
 *
 * Do not bounce through `/auth/iomadsaml2/login.php`. That page is Moodle's
 * dual-login hop; after Azure it is the URL the browser sits on instead of
 * the frontend. SimpleSAMLphp returns to whichever script called requireAuth,
 * so that script has to be this one.
 */
require_once(__DIR__ . '/../../config.php');

// The tenant is named by its opaque SP token, so the address bar never carries
// a serial company id. `companyid` stays readable for links issued before
// tokens existed.
$company = optional_param('company', '', PARAM_ALPHANUMEXT);
$legacyid = optional_param('companyid', 0, PARAM_INT);
$state = optional_param('state', '', PARAM_ALPHANUMEXT);
$return = optional_param('return', '', PARAM_URL);
// Keyed hash of the address typed at the portal (see saml_sp::identity_hint).
$hint = optional_param('hint', '', PARAM_ALPHANUM);
// Set only by this page, after it has ended a session that belonged to someone
// other than the person signing in. Anyone else setting it merely gets asked
// for their password.
$reauth = optional_param('reauth', 0, PARAM_BOOL);

$companyid = 0;
if ($company !== '') {
    $companyid = \local_privacient\saml_sp::company_id_for_token($company);
} else if ($legacyid > 0 && $DB->record_exists('local_iomad_companies', ['id' => $legacyid])) {
    $companyid = $legacyid;
}
if ($companyid <= 0) {
    throw new moodle_exception(
        'invalidarguments', 'error', '', null, 'Unknown organisation'
    );
}
$token = \local_privacient\saml_sp::token($companyid);

$portal = trim((string) get_config('local_privacient', 'portalurl'));
$secret = (string) get_config('local_privacient', 'callbacksecret');
if ($portal === '' || $secret === '') {
    throw new moodle_exception(
        'invalidarguments', 'error', '', null,
        'The learner portal URL and callback secret must be configured first'
    );
}

// Keep the frontend callback out of the address bar. A `return=https://...`
// query would ride along as RelayState and Azure echoes it back, which is how
// the browser ended up parked on Moodle instead of the portal. The session
// holds the absolute callback; the URL only names the tenant and the state.
if ($return !== '') {
    $SESSION->privacient_sso_return = $return;
    // A fresh hand-off from the portal: its hint replaces any earlier one, and
    // whether the IdP has been made to re-authenticate starts over.
    unset(
        $SESSION->privacient_sso_hint,
        $SESSION->privacient_sso_forceauthn,
        $SESSION->privacient_sso_forced
    );
    if ($hint !== '') {
        $SESSION->privacient_sso_hint = $hint;
    }
    if ($reauth && $state !== '') {
        $SESSION->privacient_sso_forceauthn = $state;
    }
}
if ($state !== '') {
    $SESSION->privacient_sso_state = $state;
}
if ($return !== '' || $company === '') {
    redirect(new moodle_url('/local/privacient/portal_sso.php', [
        'company' => $token,
        'state' => $state,
    ]));
}

$state = $state !== '' ? $state : (string) ($SESSION->privacient_sso_state ?? '');
$return = (string) ($SESSION->privacient_sso_return ?? '');
$hint = (string) ($SESSION->privacient_sso_hint ?? '');
if ($return === '') {
    $return = \local_privacient\saml_sp::frontend_callback_url($portal);
}

// The return address must belong to the configured portal. Without this the
// page would forward a signed identity assertion to any host a caller names.
$portalparts = parse_url($portal);
$returnparts = $return !== '' ? parse_url($return) : null;
if (!$returnparts
    || ($returnparts['scheme'] ?? '') !== ($portalparts['scheme'] ?? '')
    || ($returnparts['host'] ?? '') !== ($portalparts['host'] ?? '')
    || ($returnparts['port'] ?? null) !== ($portalparts['port'] ?? null)) {
    throw new moodle_exception(
        'invalidarguments', 'error', '', null,
        'Return address does not belong to the configured learner portal'
    );
}

if (!isloggedin() || isguestuser()) {
    // Which company's IdP to use. Pre-login IOMAD reads this from the session;
    // it only routes the login, and grants nothing on its own — the identity
    // still comes from the signed assertion, and the portal re-checks the
    // returned address against its own roster before trusting it.
    $SESSION->currenteditingcompany = $companyid;
    $CFG->foundcompanyid = $companyid;

    // Come back here after Azure, not to Moodle's login page.
    $SESSION->wantsurl = (new moodle_url('/local/privacient/portal_sso.php', [
        'company' => $token,
        'state' => $state,
    ]))->out(false);

    // After a mismatched session was ended below, the IdP must prompt rather
    // than reuse its own session. Consumed here, and recorded as done for this
    // hand-off, so the return trip from the IdP signs in normally.
    $forceauthn = $state !== ''
        && (string) ($SESSION->privacient_sso_forceauthn ?? '') === $state;
    if ($forceauthn) {
        unset($SESSION->privacient_sso_forceauthn);
        $SESSION->privacient_sso_forced = $state;
    }

    // Straight to SAML rather than Moodle's login form: learners have no
    // Moodle password and the form would be a dead end for them.
    require_once($CFG->dirroot . '/auth/iomadsaml2/setup.php');
    $iomadsaml2auth->saml_login($forceauthn);
}

if (!isloggedin() || isguestuser()) {
    throw new moodle_exception(
        'invalidarguments', 'error', '', null,
        'Single sign-on did not produce a Moodle session'
    );
}

// The session must belong to the person who asked to sign in.
//
// IOMAD's session (and the SP and IdP sessions behind it) outlives the
// portal's sign-out, so this page used to vouch for whoever signed in first on
// a browser, whatever address was typed next — and the portal signed them in
// with no authentication at all. On a mismatch, end that session and make the
// IdP authenticate afresh. Only once per hand-off: if the person then signs in
// as someone else at the prompt, the portal refuses the result instead of this
// page looping. An administrator's "Log in as" session was never an IdP
// sign-in, so it is never vouched for.
$impersonating = \core\session\manager::is_loggedinas();
$mismatch = $impersonating || ($hint !== '' && !hash_equals(
    $hint,
    \local_privacient\saml_sp::identity_hint((string) $USER->email, $secret)
));
if ($mismatch) {
    if ($state !== '' && (string) ($SESSION->privacient_sso_forced ?? '') !== $state) {
        // Ending the session discards $SESSION, so the hand-off is carried
        // through the URL once more and re-stashed by the block at the top.
        require_logout();
        redirect(new moodle_url('/local/privacient/portal_sso.php', [
            'company' => $token,
            'state' => $state,
            'return' => $return,
            'hint' => $hint,
            'reauth' => 1,
        ]));
    }
    if ($impersonating) {
        throw new moodle_exception(
            'invalidarguments', 'error', '', null,
            'That sign-in was not valid for this organisation'
        );
    }
}

// IOMAD's SAML lookup matches users across the whole site with no company
// filter, so signing in through one tenant's IdP could otherwise vouch for
// another tenant's learner.
if (!$DB->record_exists('local_iomad_company_users', [
        'userid' => $USER->id,
        'companyid' => $companyid,
        'suspended' => 0,
    ])) {
    throw new moodle_exception(
        'invalidarguments', 'error', '', null,
        'That sign-in was not valid for this organisation'
    );
}

unset(
    $SESSION->privacient_sso_return,
    $SESSION->privacient_sso_state,
    $SESSION->privacient_sso_hint,
    $SESSION->privacient_sso_forceauthn,
    $SESSION->privacient_sso_forced,
    $SESSION->wantsurl
);

$payload = json_encode([
    'email' => \core_text::strtolower(trim((string) $USER->email)),
    'firstname' => (string) $USER->firstname,
    'lastname' => (string) $USER->lastname,
    'state' => $state,
]);
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

$target = new moodle_url($return, [
    'p' => base64_encode($payload),
    't' => $timestamp,
    's' => 'sha256=' . $signature,
]);
redirect($target);
