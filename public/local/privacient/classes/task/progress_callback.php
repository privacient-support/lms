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
            'occurred' => (int) $data['occurred'],
        ]);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Privacient-Timestamp: ' . $timestamp,
            'X-Privacient-Signature: sha256=' . $signature,
        ]);
        $curl->post($url, $payload);

        $code = $curl->get_info()['http_code'] ?? 0;
        if ($code < 200 || $code >= 300) {
            // Throwing makes Moodle retry with its own backoff.
            throw new \moodle_exception(
                'error', 'moodle', '', null,
                "Progress callback failed with HTTP {$code}"
            );
        }
    }
}
