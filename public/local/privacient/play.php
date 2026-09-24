<?php
/**
 * Play a video activity and complete it only once it has actually been watched.
 *
 * Moodle's `mod_resource` has exactly one automatic completion condition —
 * "student must view this activity" — which fires the instant the page opens.
 * For compliance training that is worthless: it certifies that someone clicked
 * a link, not that they watched anything.
 *
 * So the resource is created with MANUAL tracking (see publish_content.php),
 * which stops Moodle completing it on view, and learners are routed here
 * instead of to mod/resource/view.php. This page reports coverage back to
 * progress.php, which is the only thing that marks the activity complete.
 *
 * Coverage is measured as the set of whole seconds actually played, so seeking
 * to the end fills one bucket rather than the whole bar.
 */
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'resource');
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/resource:view', $context);
$resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);

// The playable file: mod_resource keeps one file per activity in `content`.
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
if (empty($files)) {
    throw new moodle_exception('filenotfound', 'error');
}
$file = reset($files);
$fileurl = moodle_url::make_pluginfile_url(
    $context->id, 'mod_resource', 'content', $resource->revision,
    $file->get_filepath(), $file->get_filename()
);

$threshold = (int) (get_config('local_privacient', 'watchthreshold') ?: 95);

// The learner's company, for the branded intro before the video starts: it is
// what tells a client's staff this training was put together for them. Purely
// cosmetic, so any failure here (console down, cache misconfigured) simply
// means no slate — it must never stop the video from playing.
$branding = null;
try {
    $branding = \local_privacient\branding::for_company(
        \local_privacient\branding::company_for((int) $USER->id, (int) $course->id)
    );
} catch (\Throwable $e) {
    debugging('Video intro branding unavailable: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

$completion = new completion_info($course);
$alreadydone = false;
if ($completion->is_enabled($cm)) {
    $data = $completion->get_data($cm, false, $USER->id);
    $alreadydone = in_array((int) $data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
}

// Where this learner got to on an earlier visit, so the player can resume
// there — with the progress they had and the skip-ahead lock set to how far
// they really got. A completed video has nothing to resume: it opens at the
// start, free to scrub, for review.
$resumeat = 0.0;
$resumefar = 0.0;
$startpct = 0;
if (!$alreadydone) {
    $saved = \local_privacient\watch_state::get((int) $cm->id, (int) $USER->id);
    $resumefar = $saved['far'];
    // A few seconds in is not worth a "welcome back"; start them at the top.
    $resumeat = $saved['pos'] >= 5 ? $saved['pos'] : 0.0;
    try {
        $duration = \local_privacient\video::duration_for_cm((int) $cm->id);
    } catch (\Throwable $e) {
        $duration = null;
    }
    if ($duration && $resumefar > 0) {
        $startpct = (int) min(100, floor($resumefar / $duration * 100));
    }
}

$PAGE->set_url('/local/privacient/play.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
// 'embedded' strips Moodle's navigation, header and branding entirely. The
// learner's chrome belongs to the Privacient portal; this page is only the
// player, so showing Moodle's would be showing them a system they never signed
// in to and cannot use.
$PAGE->set_pagelayout('embedded');
// `false` suppresses Moodle's " | <site shortname>" suffix. Learners arrive
// from the portal, whose tabs read "<page> - Security Training"; a tab that
// suddenly named a different system would read as having left the product.
$PAGE->set_title(
    format_string($cm->name) . ' - ' . get_string('portaltitle', 'local_privacient'),
    false
);
$PAGE->set_heading(format_string($course->fullname));

// Drop Moodle's activity header. It is not merely redundant with the title
// below it — it also renders the manual completion toggle, and that button
// marks the module done on a single click, without watching anything. Leaving
// it on this page would hand every learner a one-click bypass of the very
// thing the player exists to measure.
$PAGE->activityheader->disable();

echo $OUTPUT->header();

// The 'embedded' layout deliberately drops Moodle's navigation, and with it the
// theme's container and grid rules — so this page must bring its own. Styling
// it here rather than leaning on the theme also keeps it looking like the
// portal the learner came from, instead of like Moodle with the chrome removed.
echo <<<'CSS'
<style>
  body, #page, #region-main { background: #f4f7fb; }
  .pv-wrap {
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 20px 56px;
    text-align: center;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  .pv-title { font-size: 1.75rem; line-height: 1.25; font-weight: 600; color: #18181b; margin: 0 0 8px; }
  .pv-intro { color: #52525b; font-size: .95rem; margin: 0 auto 28px; max-width: 620px; }
  .pv-video {
    position: relative;
    background: #000; border-radius: 12px; overflow: hidden;
    box-shadow: 0 8px 28px rgba(24, 24, 27, .18);
  }
  .pv-video video { display: block; width: 100%; height: auto; }
  /* Until the slate is dismissed the frame keeps a 16:9 height of its own, so
     the slate never collapses to the video's pre-metadata height. */
  .pv-video.has-slate { min-height: clamp(300px, 56.25vw, 506px); }
  .pv-status {
    margin: 20px auto 0; max-width: 620px; padding: 12px 16px;
    border-radius: 10px; font-size: .92rem;
  }
  .pv-status.is-progress { background: #e6f4fe; color: #14506e; }
  .pv-status.is-done { background: #e7f7ee; color: #14532d; }
  .pv-status.is-failed { background: #fef3c7; color: #78350f; }
  .pv-back {
    display: inline-block; margin-top: 28px; padding: 10px 18px;
    border: 1px solid #d4d4d8; border-radius: 10px; background: #fff;
    color: #3f3f46; font-size: .9rem; font-weight: 500; text-decoration: none;
  }
  .pv-back:hover { background: #f4f4f5; color: #18181b; text-decoration: none; }
  /* Shown briefly when a forward seek or a faster speed is refused. */
  .pv-seeknotice {
    margin: 12px auto 0; max-width: 620px; padding: 10px 14px;
    border-radius: 10px; font-size: .88rem;
    background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa;
  }
  .pv-seeknotice[hidden] { display: none; }
  /* The same slot, informational: "Welcome back — picking up at 2:31". */
  .pv-seeknotice.is-info { background: #eef6ff; color: #1e3a5f; border-color: #bfdbfe; }
  @media (max-width: 600px) {
    .pv-wrap { padding: 24px 14px 40px; }
    .pv-title { font-size: 1.4rem; }
  }
</style>
CSS;
if ($branding) {
    echo \local_privacient\branding::slate_css();
}

echo html_writer::start_div('pv-wrap');
echo html_writer::tag('h1', format_string($cm->name), ['class' => 'pv-title']);

if (trim(strip_tags($resource->intro)) !== '') {
    echo html_writer::div(
        format_module_intro('resource', $resource, $cm->id),
        'pv-intro'
    );
}

echo html_writer::start_div('pv-video' . ($branding ? ' has-slate' : ''));

if ($branding) {
    // Covers the player until the learner presses Start; the script below then
    // plays a short logo intro and hands over to the video. Without script the
    // slate is hidden (the <noscript> rule), so the plain player still works.
    echo '<noscript><style>.pv-slate{display:none}.pv-video.has-slate{min-height:0}</style></noscript>';
    echo \local_privacient\branding::slate_html($branding, get_string('introstart', 'local_privacient'));
}

echo html_writer::empty_tag('video', [
    'id' => 'privacient-player',
    'src' => $fileurl->out(false),
    'controls' => 'controls',
    // `noplaybackrate` removes Chrome's speed menu; the script below also
    // refuses faster speeds set any other way, since playing at 2x is just
    // fast-forwarding by another name.
    'controlsList' => 'nodownload noplaybackrate',
    'preload' => 'metadata',
    'playsinline' => 'playsinline',
    'data-cmid' => $cm->id,
    'data-threshold' => $threshold,
    'data-sesskey' => sesskey(),
    'data-endpoint' => (new moodle_url('/local/privacient/progress.php'))->out(false),
    'data-watchurl' => (new moodle_url('/local/privacient/watch.php'))->out(false),
    'data-done' => $alreadydone ? 1 : 0,
    'data-resume' => $resumeat,
    'data-furthest' => $resumefar,
]);
echo html_writer::end_div();

echo html_writer::div(
    $alreadydone
        ? get_string('watchcomplete', 'local_privacient')
        : get_string('watchprogress', 'local_privacient', (string) $startpct),
    'pv-status ' . ($alreadydone ? 'is-done' : 'is-progress'),
    ['id' => 'privacient-status', 'role' => 'status', 'aria-live' => 'polite']
);
// Filled and shown by the script when a skip ahead or faster speed is refused.
echo html_writer::div('', 'pv-seeknotice', [
    'id' => 'privacient-seeknotice', 'role' => 'status', 'aria-live' => 'polite', 'hidden' => 'hidden',
]);

// Back to the portal, not to Moodle's course page: the course page is exactly
// the Moodle surface this whole flow exists to keep learners out of. In the
// native app there is no portal to go back to — it closes the player itself —
// so the link is left out entirely rather than leading somewhere confusing.
if (!\local_privacient\app_client::is_app_request()) {
    $portal = trim((string) get_config('local_privacient', 'portalurl'));
    echo html_writer::link(
        $portal !== '' ? $portal : (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        get_string('backtotraining', 'local_privacient'),
        ['class' => 'pv-back']
    );
}

echo html_writer::end_div();

// Strings the inline module needs, resolved server-side.
$PAGE->requires->strings_for_js(
    ['watchprogress', 'watchcomplete', 'watchfailed', 'noskipahead', 'nofasterspeed', 'resumefrom'],
    'local_privacient'
);
$PAGE->requires->js_amd_inline(<<<'JS'
require(['core/str'], function(str) {
    var video = document.getElementById('privacient-player');
    var status = document.getElementById('privacient-status');
    if (!video || !status) {
        return;
    }

    // True while the branded intro runs. Coverage tracking below ignores this
    // window: the unlock trick plays the video for an instant under the slate,
    // and that must not show as progress before the learner has seen anything.
    var introRunning = false;

    // Where to pick up: the learner's position from an earlier visit, 0 for
    // the top. Saved by the progress reports further down (watch.php).
    var resumeAt = parseFloat(video.dataset.resume) || 0;

    // Branded intro. Start -> the company's logo settles in for a moment ->
    // the video plays. Only on the first start; replays go straight to video.
    var slate = document.getElementById('privacient-slate');
    var start = document.getElementById('privacient-start');
    if (slate && start) {
        var reduced = window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var introMs = reduced ? 1200 : 2600;
        var introDone = false;

        var handOver = function() {
            introDone = true;
            slate.classList.add('is-leaving');
            window.setTimeout(function() {
                if (slate.parentNode) {
                    slate.parentNode.classList.remove('has-slate');
                    slate.parentNode.removeChild(slate);
                }
                introRunning = false;
                video.muted = false;
                var playing = video.play();
                if (playing && playing.catch) {
                    // Blocked anyway (a strict autoplay policy): the native
                    // controls are uncovered now, so the learner presses play.
                    playing.catch(function() { video.focus(); });
                }
            }, reduced ? 0 : 400);
        };

        start.addEventListener('click', function() {
            start.disabled = true;
            introRunning = true;
            // Safari and iOS only allow a later programmatic play() on an
            // element that was first played from a user gesture. Play muted
            // for an instant inside this click to earn that, then rewind to
            // where the learner is starting (the top, or their resume point) —
            // the slate covers the frame, and tracking ignores the intro.
            video.muted = true;
            var unlock = video.play();
            var rewind = function() {
                if (introDone) {
                    return; // The real playback has begun; leave it alone.
                }
                video.pause();
                try {
                    video.currentTime = resumeAt;
                } catch (e) {
                    // Not seekable yet; the resume below positions it later.
                }
            };
            if (unlock && unlock.then) {
                unlock.then(rewind, function() {});
            } else {
                rewind();
            }
            slate.style.setProperty('--pv-intro-ms', introMs + 'ms');
            slate.classList.add('is-intro');
            window.setTimeout(handOver, introMs);
        });
    }

    // No fast-forwarding. `furthest` is the furthest point reached by actually
    // playing; a seek beyond it is snapped back there. Rewinding is always
    // allowed, and once the video is completed (now, or on an earlier visit)
    // the learner may scrub freely to review it. A faster speed is
    // fast-forwarding by another name, so speeds above 1x are refused too.
    // This is the player's half; progress.php independently refuses a
    // completion reported sooner than the video could have been watched.
    var locked = video.dataset.done !== '1';
    // Resuming, the lock starts at how far they really got last time (the
    // server only accepts `furthest` advancing at real-time speed).
    var furthest = parseFloat(video.dataset.furthest) || 0;
    var lastTime = resumeAt;
    var lastWall = Date.now();
    var notice = document.getElementById('privacient-seeknotice');
    var noticeTimer = null;

    // `info` shows it in the calmer, informational style (the resume note).
    var showNotice = function(key, param, info) {
        if (!notice) {
            return;
        }
        str.get_string(key, 'local_privacient', param).done(function(s) {
            notice.textContent = s;
            notice.classList.toggle('is-info', !!info);
            notice.hidden = false;
            window.clearTimeout(noticeTimer);
            noticeTimer = window.setTimeout(function() { notice.hidden = true; }, info ? 7000 : 4000);
        });
    };

    // Advance `furthest` only by real playback: the position may move no
    // further than the wall-clock time since the last sample allows (plus a
    // second of slack). Measured against the clock rather than a fixed step so
    // a backgrounded tab, whose timeupdate events are throttled, still counts.
    var track = function() {
        var now = Date.now();
        var t = video.currentTime;
        if (!video.seeking) {
            var allowed = ((now - lastWall) / 1000) * Math.min(1, video.playbackRate || 1) + 1;
            if (t > furthest && t - lastTime <= allowed) {
                furthest = t;
            }
        }
        lastTime = t;
        lastWall = now;
    };

    video.addEventListener('seeking', function() {
        if (!locked || introRunning) {
            return;
        }
        if (video.currentTime > furthest + 1) {
            video.currentTime = furthest;
            showNotice('noskipahead');
        }
    });

    video.addEventListener('ratechange', function() {
        if (locked && video.playbackRate > 1) {
            video.playbackRate = 1;
            showNotice('nofasterspeed');
        }
    });

    var threshold = parseInt(video.dataset.threshold, 10) || 95;
    var sent = video.dataset.done === '1';
    // Whole seconds actually played. A Set, so re-watching does not inflate it
    // and a seek to the end adds exactly one second.
    var seen = {};
    var seenCount = 0;
    // Resuming: every second up to `furthest` was watched on an earlier visit —
    // with skipping ahead locked, playing is the only way it was reached.
    if (furthest > 0) {
        for (var s = 0; s <= Math.floor(furthest); s++) {
            seen[s] = true;
            seenCount++;
        }
    }

    // Progress reports: where the learner is and how far they have got, so a
    // later visit resumes here. Every 5 s while playing (driven by timeupdate,
    // which is not throttled like timers in a background tab), on play and
    // pause, and on leaving the page. Stops once the video is complete or the
    // completion report is on its way — nothing to resume after that.
    var watchUrl = video.dataset.watchurl;
    var lastBeat = 0;
    var beatBody = function(playing) {
        var body = new FormData();
        body.append('cmid', video.dataset.cmid);
        body.append('sesskey', video.dataset.sesskey);
        body.append('pos', String(video.currentTime));
        body.append('far', String(furthest));
        body.append('playing', playing ? '1' : '0');
        return body;
    };
    var beat = function(playing) {
        if (!locked || sent || introRunning || !watchUrl) {
            return;
        }
        lastBeat = Date.now();
        fetch(watchUrl, {method: 'POST', body: beatBody(playing), credentials: 'same-origin', keepalive: true})
            .catch(function() {});
    };
    video.addEventListener('play', function() { beat(true); });
    video.addEventListener('pause', function() { beat(false); });
    window.addEventListener('pagehide', function() {
        if (!locked || sent || !watchUrl) {
            return;
        }
        // Leaving: sendBeacon survives the page unloading; fetch may not.
        if (navigator.sendBeacon) {
            navigator.sendBeacon(watchUrl, beatBody(false));
        } else {
            beat(false);
        }
    });

    // Put the learner back where they left off, and say so.
    var clock = function(sec) {
        var m = Math.floor(sec / 60);
        var r = Math.floor(sec % 60);
        return m + ':' + (r < 10 ? '0' : '') + r;
    };
    if (resumeAt > 0 && locked) {
        var applyResume = function() {
            var target = video.duration
                ? Math.min(resumeAt, Math.max(0, video.duration - 1)) : resumeAt;
            try {
                video.currentTime = target;
            } catch (e) {
                return;
            }
            lastTime = target;
            showNotice('resumefrom', clock(target), true);
        };
        if (video.readyState >= 1) {
            applyResume();
        } else {
            video.addEventListener('loadedmetadata', applyResume, {once: true});
        }
    }

    var pct = function() {
        var total = Math.floor(video.duration || 0);
        if (!total) {
            return 0;
        }
        return Math.min(100, Math.round((seenCount / total) * 100));
    };

    var render = function(value) {
        str.get_string('watchprogress', 'local_privacient', String(value)).done(function(s) {
            if (!sent) {
                status.textContent = s;
            }
        });
    };

    var report = function() {
        if (sent) {
            return;
        }
        sent = true;
        var body = new FormData();
        body.append('cmid', video.dataset.cmid);
        body.append('sesskey', video.dataset.sesskey);
        body.append('watched', String(pct()));
        // The final position report rides with it, so the server judges the
        // learner's true furthest point rather than one a beat stale.
        body.append('pos', String(video.currentTime));
        body.append('far', String(furthest));
        fetch(video.dataset.endpoint, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.complete) {
                    locked = false; // Watched: free to review from here on.
                }
                var key = data && data.complete ? 'watchcomplete' : 'watchfailed';
                status.className = 'pv-status ' + (data && data.complete ? 'is-done' : 'is-failed');
                return str.get_string(key, 'local_privacient').done(function(s) {
                    status.textContent = s;
                });
            })
            .catch(function() {
                sent = false; // Let 'ended' try again.
            });
    };

    video.addEventListener('timeupdate', function() {
        if (introRunning) {
            return;
        }
        track();
        if (!video.paused && Date.now() - lastBeat >= 5000) {
            beat(true);
        }
        var second = Math.floor(video.currentTime);
        if (!seen[second]) {
            seen[second] = true;
            seenCount++;
        }
        var value = pct();
        render(value);
        if (value >= threshold) {
            report();
        }
    });

    // Short or oddly-encoded files may never reach the threshold by sampling.
    video.addEventListener('ended', report);
});
JS);

echo $OUTPUT->footer();
