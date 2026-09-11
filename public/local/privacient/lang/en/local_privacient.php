<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Privacient console integration';
$string['progresscallback'] = 'Push training progress to the Privacient console';
$string['privacy:metadata'] = 'The Privacient console integration plugin does not store any personal data.';

// Video player (play.php).
$string['watchprogress'] = 'Watched {$a}% — this activity is marked complete once you have watched it through.';
$string['watchcomplete'] = 'Completed. Thanks for watching the full video.';
$string['watchfailed'] = 'We could not record your completion. Please finish the video, or reload the page and try again.';
$string['backtocourse'] = 'Back to the course';
$string['backtotraining'] = 'Back to my training';
// Shown in the browser tab; keep in step with the portal's title template.
$string['portaltitle'] = 'Security Training';

// The questionnaire landing page — our own, in place of mod_quiz's view.php.
$string['quizbadge'] = 'Questionnaire';
$string['quizintro'] = 'A short check of what you have taken in. Answer each question, then submit.';
$string['quizstepof'] = 'Question {$a->current} of {$a->total}';
$string['quizquestions'] = 'Questions';
$string['quizpassmark'] = 'Pass mark';
$string['quizattempts'] = 'Attempts';
$string['quizattemptsleft'] = '{$a} left';
$string['quizattemptsunlimited'] = 'Unlimited';
$string['quizstart'] = 'Start';
$string['quizretake'] = 'Try again';
$string['quizresume'] = 'Continue';
$string['quizresumenote'] = 'You have an attempt in progress — carry on where you left off.';
$string['quizpassed'] = 'Passed. Nothing more to do here.';
$string['quizfailed'] = 'You need {$a}% to pass. Have another go.';
$string['quizexhausted'] = 'You have used all your attempts. Speak to your administrator if you need another.';
