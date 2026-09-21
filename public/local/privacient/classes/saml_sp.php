<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * Per-company SAML service-provider endpoints.
 *
 * IOMAD's plugin publishes one SP for the whole LMS (same entity ID, ACS and
 * metadata URL for every company). Multi-tenant products like Infosec IQ give
 * each customer their own, so Azure/Okta apps do not collide and so the ACS
 * POST itself names the company — which is otherwise lost on a cross-site
 * POST that carries no session cookie.
 *
 * URLs are `/local/privacient/sp/{metadata|acs|logout}.php/{token}`, where the
 * token is a random 32-character value held against the company. The company
 * id is deliberately NOT in the URL: these endpoints are public, so a serial
 * id would let anyone walk `/1`, `/2`, `/3` and read every customer's SP
 * metadata, learn how many customers exist, and post assertions at a tenant
 * they were never given. A token carries the same routing information without
 * being guessable or countable.
 *
 * The token identifies a tenant, it does not authenticate anyone. Trust still
 * comes from the signed assertion and, on the portal side, from the HMAC
 * handoff — so a leaked token grants nothing beyond reading public metadata.
 */
class saml_sp {

    /** Config key prefix, under the `local_privacient` plugin. */
    private const TOKEN_PREFIX = 'sptoken_';

    /** Hex characters in a token. 32 = 128 bits, far past guessing. */
    private const TOKEN_LENGTH = 32;

    /**
     * The NameID format to ask identity providers for.
     *
     * `auth_iomadsaml2` matches Moodle accounts on email address (`mdlattr`),
     * and learners are provisioned from the console roster by email, so the
     * assertion has to name one. The plugin's own default asks for a transient
     * NameID: an opaque, per-session value. Entra ID obliges, sends no email
     * claim alongside it, and the login fails with "logged in successfully as
     * '7zr9jup9...=' but do not have an account in Moodle".
     */
    public const NAMEID_EMAIL = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';

    /**
     * This company's public SP token, minted on first use.
     *
     * Stored rather than derived from the id, so it cannot be recomputed by
     * anyone who learns the algorithm, and so rotating a site secret never
     * silently invalidates every customer's IdP configuration.
     */
    public static function token(int $companyid): string {
        if ($companyid <= 0) {
            throw new \coding_exception('SAML SP tokens are per company');
        }

        $existing = get_config('local_privacient', self::TOKEN_PREFIX . $companyid);
        if (is_string($existing) && self::is_token($existing)) {
            return $existing;
        }

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH / 2));
        set_config(self::TOKEN_PREFIX . $companyid, $token, 'local_privacient');
        return $token;
    }

    /** Shape check, so a lookup never runs on arbitrary path junk. */
    public static function is_token(string $value): bool {
        return (bool) preg_match('/^[0-9a-f]{' . self::TOKEN_LENGTH . '}$/', $value);
    }

    /**
     * The company a token belongs to, or 0.
     *
     * One query: `get_config` returns the whole plugin's settings, so the
     * reverse lookup costs no more than reading a single key would.
     */
    public static function company_id_for_token(string $token): int {
        global $DB;

        if (!self::is_token($token)) {
            return 0;
        }

        $all = (array) get_config('local_privacient');
        $prefixlen = strlen(self::TOKEN_PREFIX);
        foreach ($all as $name => $value) {
            if (strpos($name, self::TOKEN_PREFIX) !== 0 || !is_string($value)) {
                continue;
            }
            // Constant-time compare: this runs on an unauthenticated endpoint.
            if (!hash_equals($value, $token)) {
                continue;
            }
            $companyid = (int) substr($name, $prefixlen);
            if ($companyid > 0 && $DB->record_exists('local_iomad_companies', ['id' => $companyid])) {
                return $companyid;
            }
        }
        return 0;
    }

    /**
     * The addresses a company's IdP administrator needs, plus the token and
     * the portal's sign-in entry point built from it.
     *
     * @return array{token: string, entityid: string, metadataurl: string,
     *               acsurl: string, slsurl: string, loginurl: string}
     */
    public static function urls(int $companyid): array {
        global $CFG;

        $token = self::token($companyid);
        $root = rtrim($CFG->wwwroot, '/');
        $base = $root . '/local/privacient/sp';
        $metadata = "{$base}/metadata.php/{$token}";

        return [
            'token' => $token,
            'entityid' => $metadata,
            'metadataurl' => $metadata,
            'acsurl' => "{$base}/acs.php/{$token}",
            'slsurl' => "{$base}/logout.php/{$token}",
            'loginurl' => "{$root}/local/privacient/portal_sso.php?company={$token}",
        ];
    }

    /**
     * Site-wide entity ID the plugin used before SP URLs were per company.
     */
    public static function legacy_entity_id(): string {
        global $CFG;
        return rtrim($CFG->wwwroot, '/') . '/auth/iomadsaml2/sp/metadata.php';
    }

    /**
     * Persist this company's entity ID so IOMAD's own form and SimpleSAMLphp
     * `spentityid_{id}` setting stay in step with the URLs we publish.
     */
    public static function ensure_entity_id(int $companyid): string {
        $urls = self::urls($companyid);
        $current = (string) get_config('auth_iomadsaml2', "spentityid_{$companyid}");

        // Replace the site-wide default and the earlier id-in-the-path form;
        // leave anything an administrator chose deliberately alone.
        $stale = $current === ''
            || $current === self::legacy_entity_id()
            || (bool) preg_match('#/local/privacient/sp/metadata\.php/\d+$#', $current);

        if ($stale) {
            set_config("spentityid_{$companyid}", $urls['entityid'], 'auth_iomadsaml2');
            return $urls['entityid'];
        }
        return $current;
    }

    /**
     * The company this request is for, from its SP token.
     *
     * Only the token forms are accepted on the SP endpoints. `companyid` is
     * still read for the portal hand-off page, which is ours and is reached
     * with a session rather than by an identity provider.
     */
    public static function company_id_from_request(): int {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $pathinfo = (string) ($_SERVER['PATH_INFO'] ?? '');

        foreach ([$pathinfo, $_GET['company'] ?? '', $_POST['company'] ?? ''] as $raw) {
            $raw = trim((string) $raw, "/ \t\n\r\0\x0B");
            if ($raw !== '' && self::is_token($raw)) {
                $companyid = self::company_id_for_token($raw);
                if ($companyid > 0) {
                    return $companyid;
                }
            }
        }

        // Apache may pass the token in the URI rather than PATH_INFO.
        if (preg_match('#/(?:metadata|acs|logout)\.php/([0-9a-f]{' . self::TOKEN_LENGTH . '})#', $uri, $m)) {
            $companyid = self::company_id_for_token($m[1]);
            if ($companyid > 0) {
                return $companyid;
            }
        }

        return 0;
    }

    /**
     * Put the company into IOMAD's session *before* `auth_iomadsaml2` is
     * constructed, so IdP metadata, entity ID and certificates are this
     * tenant's rather than the site-wide defaults.
     */
    public static function bind_from_request(): int {
        global $CFG, $SESSION;

        // Cheap guard: this runs from the auth plugin's constructor on every
        // page, and only our own endpoints can carry a token.
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, '/local/privacient/') === false
            && empty($_GET['company']) && empty($_POST['company'])) {
            return 0;
        }

        $companyid = self::company_id_from_request();
        if ($companyid > 0) {
            $SESSION->currenteditingcompany = $companyid;
            $CFG->foundcompanyid = $companyid;
        }
        return $companyid;
    }

    /**
     * Roster users are created as `manual` (they never hold a Moodle
     * password). IOMAD's SAML plugin refuses those unless `anyauth` is on
     * or their auth type is `iomadsaml2` — which produces "logged in
     * successfully but are not authorized to access Moodle".
     */
    public static function allow_roster_sso(int $companyid): void {
        global $DB;

        set_config("anyauth_{$companyid}", 1, 'auth_iomadsaml2');
        set_config("nameidpolicy_{$companyid}", self::NAMEID_EMAIL, 'auth_iomadsaml2');
        // Learners have no Moodle password. Dual login would land them on
        // /auth/iomadsaml2/login.php after Azure instead of the portal.
        set_config(
            "duallogin_{$companyid}",
            \auth_iomadsaml2\admin\iomadsaml2_settings::OPTION_DUAL_LOGIN_NO,
            'auth_iomadsaml2'
        );
        // Site default is also 1; an admin had turned it off, which blocked
        // every pre-provisioned learner when ACS had no company in session.
        if ((string) get_config('auth_iomadsaml2', 'anyauth') === '0') {
            set_config('anyauth', 1, 'auth_iomadsaml2');
        }

        $userids = $DB->get_fieldset_select(
            'local_iomad_company_users', 'userid', 'companyid = ?', [$companyid]
        );
        if (!$userids) {
            return;
        }
        list($in, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select(
            'user',
            'auth',
            'iomadsaml2',
            "auth = :manual AND deleted = 0 AND id $in",
            ['manual' => 'manual'] + $params
        );
    }

    /**
     * Where IOMAD should send the signed identity after SAML.
     *
     * `portalurl` is sometimes stored as the dashboard path; the callback
     * always lives at origin + /api/portal/auth/saml/callback/.
     */
    public static function frontend_callback_url(string $portal): string {
        $parts = parse_url($portal) ?: [];
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin . '/api/portal/auth/saml/callback/';
    }

    /**
     * The portal's own sign-in page, derived from the configured portal URL.
     */
    public static function frontend_login_url(string $portal): string {
        $parts = parse_url($portal) ?: [];
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin . '/portal/login/';
    }

    /**
     * Send a failed portal sign-in back to the portal, and never return.
     *
     * A learner who started at the portal must not be left on a Moodle error
     * page. Moodle is the system this whole flow exists to keep them out of:
     * they have no account there to reason about, the page names products they
     * have never heard of, and it offers them no way onwards. The reason is
     * carried to the portal's login page, which already knows how to show one.
     *
     * Only portal-initiated flows are diverted. Staff debugging SAML from
     * inside Moodle still need Moodle's own error page.
     */
    public static function fail_to_portal(string $msg): void {
        global $SESSION;

        if (empty($SESSION->privacient_sso_return)) {
            return;
        }
        $portal = trim((string) get_config('local_privacient', 'portalurl'));
        if ($portal === '') {
            return;
        }

        unset(
            $SESSION->privacient_sso_return,
            $SESSION->privacient_sso_state,
            $SESSION->wantsurl
        );

        // The IdP's own wording is aimed at an administrator and can name
        // internal identifiers, so it is logged rather than shown.
        debugging('Privacient portal SAML sign-in failed: ' . $msg, DEBUG_DEVELOPER);

        redirect(new \moodle_url(self::frontend_login_url($portal), [
            'error' => 'Your organisation signed you in, but we could not match'
                . ' you to a training account. Please contact your IT team.',
        ]));
    }

    /** Forget a deleted company's token so it can never be resolved again. */
    public static function forget(int $companyid): void {
        unset_config(self::TOKEN_PREFIX . $companyid, 'local_privacient');
    }

    /**
     * Load Moodle + SimpleSAMLphp for a company-scoped SP endpoint.
     */
    public static function bootstrap(): int {
        global $CFG, $DB;

        $companyid = self::bind_from_request();
        if ($companyid <= 0 || !$DB->record_exists('local_iomad_companies', ['id' => $companyid])) {
            // Deliberately the same answer for a malformed token, an unknown
            // one and a deleted company: distinguishing them would turn this
            // into an oracle for which tokens exist.
            throw new \moodle_exception(
                'invalidarguments', 'error', '', null, 'Unknown service provider'
            );
        }
        self::ensure_entity_id($companyid);
        require_once($CFG->dirroot . '/auth/iomadsaml2/setup.php');
        return $companyid;
    }
}
