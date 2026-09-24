<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * Facts about a video activity's file that the server can know without
 * trusting the browser — today, its running time.
 *
 * progress.php uses the duration to refuse a completion reported sooner than
 * the video could have been watched at normal speed. The player already blocks
 * skipping ahead, but that runs in the learner's browser; this is the check a
 * learner cannot switch off from the developer console.
 */
class video {

    /**
     * Duration of a video activity, cached per course module: the player
     * reports every few seconds, and re-reading the file's headers for each
     * report would be wasted work for a value that does not change.
     */
    public static function duration_for_cm(int $cmid): ?float {
        $cache = \cache::make('local_privacient', 'videoduration');
        $hit = $cache->get($cmid);
        if (is_array($hit) && array_key_exists('d', $hit)) {
            return $hit['d'];
        }
        $duration = self::duration_for_context(\context_module::instance($cmid));
        $cache->set($cmid, ['d' => $duration]);
        return $duration;
    }

    /** Duration in seconds of a video activity's file, or null if unknown. */
    public static function duration_for_context(\context_module $context): ?float {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false
        );
        $file = reset($files);
        return $file ? self::duration($file) : null;
    }

    /**
     * Running time of an MP4/MOV (ISO base media) file, read from the movie
     * header (`moov` → `mvhd`). Only the box headers are read; the media data
     * is skipped over, so this costs a handful of small reads even for a large
     * file, wherever the encoder put the `moov` box. Anything else — WebM, a
     * damaged file, a storage backend whose handle cannot seek — returns null,
     * and the caller falls back to its older, duration-free checks.
     */
    public static function duration(\stored_file $file): ?float {
        $handle = $file->get_content_file_handle();
        if (!$handle) {
            return null;
        }
        try {
            $moov = self::find_box($handle, 0, (int) $file->get_filesize(), 'moov');
            if (!$moov) {
                return null;
            }
            $mvhd = self::find_box($handle, $moov[0], $moov[1], 'mvhd');
            if (!$mvhd || fseek($handle, $mvhd[0]) !== 0) {
                return null;
            }
            $body = fread($handle, 32);
            if ($body === false || strlen($body) < 20) {
                return null;
            }
            if (ord($body[0]) === 1) {
                // Version 1: 64-bit creation/modification times and duration.
                if (strlen($body) < 32) {
                    return null;
                }
                $timescale = unpack('N', substr($body, 20, 4))[1];
                $duration = unpack('J', substr($body, 24, 8))[1];
            } else {
                $timescale = unpack('N', substr($body, 12, 4))[1];
                $duration = unpack('N', substr($body, 16, 4))[1];
            }
            if ($timescale <= 0 || $duration <= 0) {
                return null;
            }
            return $duration / $timescale;
        } catch (\Throwable $e) {
            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * [content start, end] of the first box of `$type` directly within
     * [$from, $to), or null. Handles 64-bit sizes and the "to end" size of 0.
     */
    private static function find_box($handle, int $from, int $to, string $type): ?array {
        $pos = $from;
        for ($guard = 0; $pos + 8 <= $to && $guard < 10000; $guard++) {
            if (fseek($handle, $pos) !== 0) {
                return null;
            }
            $header = fread($handle, 8);
            if ($header === false || strlen($header) < 8) {
                return null;
            }
            $size = unpack('N', substr($header, 0, 4))[1];
            $boxtype = substr($header, 4, 4);
            $headerlength = 8;
            if ($size === 1) {
                $large = fread($handle, 8);
                if ($large === false || strlen($large) < 8) {
                    return null;
                }
                $size = unpack('J', $large)[1];
                $headerlength = 16;
            } else if ($size === 0) {
                $size = $to - $pos;
            }
            if ($size < $headerlength) {
                return null; // Corrupt: a box cannot be smaller than its header.
            }
            if ($boxtype === $type) {
                return [$pos + $headerlength, min($pos + $size, $to)];
            }
            $pos += $size;
        }
        return null;
    }
}
