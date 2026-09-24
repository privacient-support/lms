<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * Where a learner has got to in one video, kept between visits so they can
 * resume instead of starting again.
 *
 * Stored as a small JSON user preference per video activity:
 *   pos  — where playback was when last reported (the resume point)
 *   far  — the furthest point reached by actually playing
 *   acc  — seconds of real playing time, accumulated across every visit
 *   beat — when the player last reported
 *   play — whether it was playing at that report
 *
 * The player reports ("beats") every few seconds while playing, on pause and
 * when the learner leaves. Everything that matters for completion is measured
 * here, on the server's clock, rather than taken from the browser:
 *
 *   - `acc` grows by the real time between two consecutive beats, but only
 *     while playing, and only when they are close together — paused time and
 *     time away never count.
 *   - `far` never moves back, and never moves forward faster than the time just
 *     credited (plus a few seconds' slack), whatever the browser claims. So
 *     skipping ahead, 2x playback or a forged request cannot put a learner
 *     further through a video than they could really have watched.
 *
 * progress.php completes the video only when `far` and `acc` clear the
 * threshold against the video's real running time.
 */
class watch_state {

    private const PREF = 'local_privacient_resume_';

    /**
     * A beat more than this many seconds after the previous one starts the
     * clock afresh rather than crediting the gap. Generous enough for a
     * backgrounded tab whose events are throttled; short enough that closing
     * the laptop does not count as watching.
     */
    public const MAX_GAP = 90;

    /** How far `far` may move past the time credited, per beat. */
    private const SLACK = 3;

    /** The saved state, or zeros for a learner who has not started. */
    public static function get(int $cmid, int $userid): array {
        $raw = get_user_preferences(self::PREF . $cmid, '', $userid);
        $data = json_decode((string) $raw, true);
        $data = is_array($data) ? $data : [];
        return [
            'pos' => max(0.0, (float) ($data['pos'] ?? 0)),
            'far' => max(0.0, (float) ($data['far'] ?? 0)),
            'acc' => max(0, (int) ($data['acc'] ?? 0)),
            'beat' => max(0, (int) ($data['beat'] ?? 0)),
            'play' => !empty($data['play']),
        ];
    }

    /**
     * Record a report from the player and return the state as accepted.
     *
     * @param float $pos where playback is now, as the player says
     * @param float $far the furthest point it says was reached
     * @param bool $playing whether it is playing now (false on pause / leaving)
     * @param float|null $duration the video's real running time, if known
     */
    public static function beat(
        int $cmid,
        int $userid,
        float $pos,
        float $far,
        bool $playing,
        ?float $duration
    ): array {
        $state = self::get($cmid, $userid);
        $now = time();

        // Credit the time since the last report only if the player was playing
        // then and has reported again promptly: continuous watching.
        $gap = $now - $state['beat'];
        $credit = ($state['beat'] > 0 && $state['play'] && $gap >= 0 && $gap <= self::MAX_GAP)
            ? $gap : 0;
        $state['acc'] += $credit;

        // The furthest point may only advance as fast as real time allows.
        $limit = $state['far'] + $credit + self::SLACK;
        if ($duration !== null && $duration > 0) {
            $limit = min($limit, $duration);
        }
        $state['far'] = round(min(max($state['far'], $far), $limit), 1);
        // The resume point can never be beyond what was reached.
        $state['pos'] = round(min(max(0.0, $pos), $state['far']), 1);
        $state['beat'] = $now;
        $state['play'] = $playing;

        set_user_preference(self::PREF . $cmid, json_encode($state), $userid);
        return $state;
    }

    /** Forget the state: the video is complete, so a revisit starts at 0. */
    public static function clear(int $cmid, int $userid): void {
        unset_user_preference(self::PREF . $cmid, $userid);
    }
}
