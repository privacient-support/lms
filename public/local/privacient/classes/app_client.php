<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * Is this request coming from the native learner app's in-app course viewer?
 *
 * The app runs courses in a WebView that shares this session, so these pages
 * are reached by exactly the same URLs a browser uses and nothing in the request
 * distinguishes them — except the user agent, which the app extends with its own
 * tag (see APP_USER_AGENT_TAG in privacient-frontend/mobile/src/screens/CourseWebView.tsx).
 *
 * What changes is the way back out. In a browser, "Back to my training" returns
 * the learner to the portal, which is where they came from. In the app there is
 * no portal to return to: following that link loads a second, web copy of the
 * training list inside the course viewer, with the app's own screens still sitting
 * behind it — so the app hides the link and offers its own way to close.
 */
class app_client {

    /** Appended to the WebView's user agent by the app. */
    public const UA_TAG = 'PrivacientApp';

    public static function is_app_request(): bool {
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $agent !== '' && strpos($agent, self::UA_TAG) !== false;
    }
}
