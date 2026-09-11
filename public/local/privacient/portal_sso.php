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
 */
require_once(__DIR__ . '/../../config.php');

$companyid = optional_param('companyid', 0, PARAM_INT);
$state = optional_param('state', '', PARAM_ALPHANUMEXT);
$return = optional_param('return', '', PARAM_URL);

$portal = trim((string) get_config('local_privacient', 'portalurl'));
$secret = (string) get_config('local_privacient', 'callbacksecret');
if ($portal === '' || $secret === '') {
    throw new moodle_exception(
        'invalidarguments', 'error', '', null,
        'The learner portal URL and callback secret must be configured first'
    );
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
    if ($companyid > 0) {
        $SESSION->currenteditingcompany = $companyid;
    }

    $self = new moodle_url('/local/privacient/portal_sso.php', [
        'companyid' => $companyid,
        'state' => $state,
        'return' => $return,
    ]);
    // Straight to SAML rather than Moodle's login form: learners have no Moodle
    // password and the form would be a dead end for them.
    redirect(new moodle_url('/auth/iomadsaml2/login.php', [
        'wantsurl' => $self->out_as_local_url(false),
    ]));
}

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
