<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * The learner's own company branding, for the intro slate the video player
 * shows before a training video starts (play.php).
 *
 * The logo and brand colour live in the Privacient console, not in Moodle, so
 * they are fetched from it server-to-server over the same signed channel the
 * progress callback uses (HMAC-SHA256 over "{timestamp}.{body}" with
 * `callbacksecret`). The browser never gets a URL for the logo: the console only
 * answers signed requests, so nobody can walk company ids to learn who its
 * customers are.
 *
 * Answers are cached per company, so a video open costs no network call after
 * the first. A console that cannot be reached is not fatal: the slate falls back
 * to the company name IOMAD already holds, and the failure is retried after a
 * minute rather than on every page open.
 */
class branding {

    /** How long a good answer from the console is trusted, in seconds. */
    private const TTL = 600;

    /** How long a failed fetch is remembered before trying again. */
    private const FAILURE_TTL = 60;

    /** The product's own colour, used when a company has not chosen one. */
    public const DEFAULT_COLOUR = '#1ea0f1';

    /** Decoded logos larger than this are ignored rather than inlined. */
    private const MAX_LOGO_BYTES = 1536 * 1024;

    /**
     * The company this learner is taking this course as.
     *
     * Preference order: a company the learner belongs to that also owns the
     * course (the normal case — courses are published per campaign for one
     * company); then any company the learner belongs to; then the course's own
     * company, so an administrator previewing the module still sees whose
     * training it is. 0 when none applies, in which case no slate is shown.
     */
    public static function company_for(int $userid, int $courseid): int {
        global $DB;

        $id = $DB->get_field_sql(
            "SELECT cu.companyid
               FROM {local_iomad_company_users} cu
               JOIN {local_iomad_company_courses} cc
                 ON cc.companyid = cu.companyid AND cc.courseid = :courseid
              WHERE cu.userid = :userid AND cu.suspended = 0
           ORDER BY cu.id ASC",
            ['courseid' => $courseid, 'userid' => $userid],
            IGNORE_MULTIPLE
        );
        if ($id) {
            return (int) $id;
        }

        $id = $DB->get_field_sql(
            "SELECT companyid FROM {local_iomad_company_users}
              WHERE userid = :userid AND suspended = 0
           ORDER BY id ASC",
            ['userid' => $userid],
            IGNORE_MULTIPLE
        );
        if ($id) {
            return (int) $id;
        }

        $id = $DB->get_field_sql(
            "SELECT companyid FROM {local_iomad_company_courses}
              WHERE courseid = :courseid
           ORDER BY id ASC",
            ['courseid' => $courseid],
            IGNORE_MULTIPLE
        );
        return (int) $id;
    }

    /**
     * Branding for one company: ['name' => string, 'colour' => '#rrggbb',
     * 'ink' => '#rrggbb', 'logo' => data URI or null], or null when there is
     * nothing to show (no company, or a company with no name).
     */
    public static function for_company(int $companyid): ?array {
        global $DB;

        if ($companyid <= 0) {
            return null;
        }

        $cache = \cache::make('local_privacient', 'branding');
        $hit = $cache->get($companyid);
        if (is_array($hit) && ($hit['expires'] ?? 0) > time()) {
            return $hit['branding'];
        }

        $fetched = self::fetch($companyid);
        $branding = $fetched;
        if ($branding === null) {
            // The console is unreachable or does not know this company: still
            // name the company, from the record IOMAD mirrors from the console.
            $name = (string) $DB->get_field('local_iomad_companies', 'name', ['id' => $companyid]);
            $branding = trim($name) !== ''
                ? ['name' => trim($name), 'colour' => self::DEFAULT_COLOUR, 'logo' => null]
                : null;
        }
        if ($branding !== null) {
            $branding['ink'] = self::ink_for($branding['colour']);
        }

        $cache->set($companyid, [
            'expires' => time() + ($fetched === null ? self::FAILURE_TTL : self::TTL),
            'branding' => $branding,
        ]);
        return $branding;
    }

    /** Ask the console for this company's branding; null on any failure. */
    private static function fetch(int $companyid): ?array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $url = self::endpoint();
        $secret = (string) get_config('local_privacient', 'callbacksecret');
        if ($url === '' || $secret === '') {
            return null;
        }

        $payload = json_encode(['companyid' => $companyid]);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        // `ignoresecurity` for the same reason as the progress callback: this
        // URL is an administrator setting, and the console normally sits on a
        // private address beside Moodle that the cURL blocklist would refuse.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Privacient-Timestamp: ' . $timestamp,
            'X-Privacient-Signature: sha256=' . $signature,
        ]);
        // Tight timeouts: this runs while a learner waits for the player to
        // open, and a slow console must cost them seconds at most, once.
        $response = $curl->post($url, $payload, [
            'CURLOPT_CONNECTTIMEOUT' => 2,
            'CURLOPT_TIMEOUT' => 4,
        ]);
        if ((int) ($curl->get_info()['http_code'] ?? 0) !== 200) {
            return null;
        }

        $body = json_decode((string) $response, true);
        if (!is_array($body) || empty($body['success'])) {
            return null;
        }
        $name = trim((string) ($body['companyName'] ?? ''));
        if ($name === '') {
            return null;
        }
        return [
            'name' => $name,
            'colour' => self::clean_colour($body['brandColour'] ?? null) ?? self::DEFAULT_COLOUR,
            'logo' => self::clean_logo($body['logo'] ?? null),
        ];
    }

    /**
     * Where to ask. An explicit `brandingurl` setting wins; otherwise it is
     * derived from `callbackurl`, whose sibling it is
     * (.../api/internal/training/progress -> .../api/internal/training/branding/),
     * so an install that already pushes progress needs no new configuration.
     * The trailing slash matters: the console canonicalises to one and a
     * redirected POST would lose its body.
     */
    private static function endpoint(): string {
        $explicit = trim((string) get_config('local_privacient', 'brandingurl'));
        if ($explicit !== '') {
            return $explicit;
        }
        $callback = trim((string) get_config('local_privacient', 'callbackurl'));
        if ($callback === '') {
            return '';
        }
        $derived = preg_replace('~/progress/?(\?.*)?$~', '/branding/', $callback);
        return ($derived !== null && $derived !== $callback) ? $derived : '';
    }

    /** A strict #rrggbb, or null. Anything else never reaches the page's CSS. */
    private static function clean_colour($value): ?string {
        $value = is_string($value) ? trim($value) : '';
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    /**
     * A data URI for an image of an allowed type, re-encoded from the decoded
     * bytes so nothing but clean base64 is ever written into the page.
     */
    private static function clean_logo($logo): ?string {
        if (!is_array($logo)) {
            return null;
        }
        $mime = (string) ($logo['mimeType'] ?? '');
        if (!in_array($mime, ['image/webp', 'image/png', 'image/jpeg'], true)) {
            return null;
        }
        $bytes = base64_decode((string) ($logo['data'] ?? ''), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_LOGO_BYTES) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * The branded intro slate: the company's logo (or its name, when none is
     * uploaded), "Training for <Company>", a Start control and the
     * progress bar the intro fills. Shared by the video player (play.php), where
     * it covers the video, and the SCORM entry page (scorm.php), where it fronts
     * mod_scorm's player.
     *
     * With `$href`, Start is a real link to it, so the page still works without
     * script (the script intercepts it to play the intro first); without, it is
     * a button the page's script drives.
     */
    public static function slate_html(array $branding, string $label, ?\moodle_url $href = null): string {
        $companyname = format_string($branding['name']);

        $out = \html_writer::start_div('pv-slate', [
            'id' => 'privacient-slate',
            // Both values were validated as strict #rrggbb in fetch()/ink_for().
            'style' => '--pv-brand: ' . $branding['colour'] . '; --pv-ink: ' . $branding['ink'] . ';',
        ]);
        $out .= \html_writer::start_div('pv-slate-card');
        $kicker = \html_writer::tag('p', get_string('introkicker', 'local_privacient'), ['class' => 'pv-slate-kicker']);
        if (!empty($branding['logo'])) {
            // Logo, then "Training for <Company>" beneath it.
            $out .= \html_writer::empty_tag('img', [
                'class' => 'pv-slate-logo',
                'src' => $branding['logo'],
                'alt' => get_string('intrologoalt', 'local_privacient', $companyname),
            ]);
            $out .= $kicker;
            $out .= \html_writer::tag('p', $companyname, ['class' => 'pv-slate-company']);
        } else {
            // No logo uploaded: the company's name, set large, stands in as the
            // mark — once, under the kicker, rather than repeated beneath itself.
            $out .= $kicker;
            $out .= \html_writer::div($companyname, 'pv-slate-mark');
        }

        $content = '&#9654;&nbsp; ' . s($label);
        $attributes = ['class' => 'pv-slate-start', 'id' => 'privacient-start'];
        $out .= $href
            ? \html_writer::link($href, $content, $attributes)
            : \html_writer::tag('button', $content, $attributes + ['type' => 'button']);

        $out .= \html_writer::end_div();
        $out .= \html_writer::div('', 'pv-slate-bar', ['aria-hidden' => 'true']);
        $out .= \html_writer::end_div();
        return $out;
    }

    /** Styles for slate_html(). The page supplies the positioned frame it sits in. */
    public static function slate_css(): string {
        return <<<'CSS'
<style>
  .pv-slate {
    position: absolute; inset: 0; z-index: 2;
    display: flex; align-items: center; justify-content: center;
    background: radial-gradient(circle at 50% 38%, #ffffff 0%, #f4f7fb 72%);
    border-top: 6px solid var(--pv-brand);
    transition: opacity .4s ease;
  }
  .pv-slate-card {
    display: flex; flex-direction: column; align-items: center;
    gap: 6px; padding: 24px;
  }
  .pv-slate-logo {
    display: block; max-width: min(260px, 60%); max-height: 150px;
    width: auto; height: auto; object-fit: contain; margin-bottom: 10px;
  }
  .pv-slate-mark {
    font-size: 1.9rem; font-weight: 700; color: #18181b; margin-bottom: 10px;
  }
  .pv-slate-kicker {
    margin: 0; font-size: .78rem; letter-spacing: .09em;
    text-transform: uppercase; color: #71717a;
  }
  .pv-slate-company { margin: 0; font-size: 1.15rem; font-weight: 600; color: #18181b; }
  .pv-slate-start {
    display: inline-block; margin-top: 18px; padding: 12px 24px;
    border: 0; border-radius: 999px;
    background: var(--pv-brand); color: var(--pv-ink);
    font: 600 1rem/1 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    cursor: pointer; box-shadow: 0 6px 18px rgba(24, 24, 27, .18);
    transition: transform .15s ease, opacity .2s ease, box-shadow .15s ease;
  }
  /* As a link (SCORM), beat the theme's link colour and underline. */
  .pv-slate a.pv-slate-start,
  .pv-slate a.pv-slate-start:hover,
  .pv-slate a.pv-slate-start:focus { color: var(--pv-ink); text-decoration: none; }
  .pv-slate-start:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(24, 24, 27, .22); }
  .pv-slate-start:focus-visible { outline: 3px solid var(--pv-brand); outline-offset: 3px; }
  .pv-slate-bar {
    position: absolute; left: 0; bottom: 0; height: 4px; width: 0;
    background: var(--pv-brand);
  }
  /* The intro: the button gives way, the logo settles in, the bar fills. */
  .pv-slate.is-intro .pv-slate-start { opacity: 0; pointer-events: none; transform: translateY(6px); }
  .pv-slate.is-intro .pv-slate-logo,
  .pv-slate.is-intro .pv-slate-mark { animation: pv-logo-in .9s cubic-bezier(.2, .8, .2, 1) both; }
  .pv-slate.is-intro .pv-slate-bar { width: 100%; transition: width var(--pv-intro-ms, 2600ms) linear; }
  .pv-slate.is-leaving { opacity: 0; }
  @keyframes pv-logo-in {
    from { opacity: 0; transform: scale(.86); }
    to { opacity: 1; transform: scale(1); }
  }
  @media (prefers-reduced-motion: reduce) {
    .pv-slate, .pv-slate * { animation: none !important; transition: none !important; }
  }
  @media (max-width: 600px) {
    .pv-slate-card { padding: 16px; gap: 4px; }
    .pv-slate-logo { max-height: 96px; max-width: 70%; margin-bottom: 8px; }
    .pv-slate-mark { font-size: 1.45rem; }
    .pv-slate-start { margin-top: 12px; padding: 11px 20px; }
  }
</style>
CSS;
    }

    /** Dark or white text, whichever reads on the brand colour. */
    public static function ink_for(string $hex): string {
        $hex = ltrim($hex, '#');
        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel(substr($hex, 0, 2))
            + 0.7152 * $channel(substr($hex, 2, 2))
            + 0.0722 * $channel(substr($hex, 4, 2));
        // Same threshold the console uses (lib/brand-palette.ts), so a
        // company's buttons read the same in both places.
        return $luminance > 0.45 ? '#18181b' : '#ffffff';
    }
}
