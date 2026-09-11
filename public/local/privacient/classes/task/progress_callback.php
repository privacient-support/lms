<?php
namespace local_privacient\task;

defined('MOODLE_INTERNAL') || die();

/**
 * POST one progress change to the console, signed the same way IOMAD's own
 * sync client signs: HMAC-SHA256 over "{timestamp}.{body}".
 */
class progress_callback extends \core\task\adhoc_task {

    public function get_name(): string {
        return get_string('progresscallback', 'local_privacient');
    }

    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');

        $data = (array) $this->get_custom_data();
        $url = get_config('local_privacient', 'callbackurl');
        $secret = get_config('local_privacient', 'callbacksecret');
        if (!$url || !$secret) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $data['userid']], 'id, email', IGNORE_MISSING);
        if (!$user) {
            return;
        }

        $payload = json_encode([
            'email' => $user->email,
            'courseid' => (int) $data['courseid'],
            'status' => $data['status'],
            'score' => $data['score'],
            'seconds' => isset($data['seconds']) && $data['seconds'] !== null
                ? (int) $data['seconds']
                : null,
            'occurred' => (int) $data['occurred'],
        ]);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        // `ignoresecurity` is deliberate, and narrower than the alternative.
        //
        // Moodle's cURL helper blocks private address ranges to stop an
        // attacker turning a user-supplied URL into a request against internal
        // infrastructure. This URL is not user-supplied: it is a site
        // administrator setting, and the console it points at normally *is* on
        // a private address beside Moodle — so the check blocks the intended
        // destination and nothing else.
        //
        // The alternative, allowing that range or port in
        // `curlsecurityblockedhosts` / `curlsecurityallowedport`, would relax it
        // for every other feature that fetches a URL, including ones that do
        // take user input. Scoping the exception to this one admin-configured
        // call is the smaller hole.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Privacient-Timestamp: ' . $timestamp,
            'X-Privacient-Signature: sha256=' . $signature,
        ]);
        $response = $curl->post($url, $payload);

        $code = $curl->get_info()['http_code'] ?? 0;
        if ($code < 200 || $code >= 300) {
            // Throwing makes Moodle retry with its own backoff. The response
            // body is included because "HTTP 0" alone sent the last diagnosis
            // hunting the network when the real answer was in the error text.
            $detail = $curl->error ?: substr((string) $response, 0, 200);
            throw new \moodle_exception(
                'error', 'moodle', '', null,
                "Progress callback to {$url} failed with HTTP {$code}: {$detail}"
            );
        }
    }
}
