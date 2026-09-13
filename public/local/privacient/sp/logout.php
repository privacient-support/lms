<?php
/**
 * Company-specific Single Logout Service.
 */
require_once(__DIR__ . '/../../../config.php');

\local_privacient\saml_sp::bootstrap();

$_SERVER['PATH_INFO'] = '/' . $iomadsaml2auth->spname;

try {
    $session = \SimpleSAML\Session::getSessionFromRequest();
    if (!is_null($session->getAuthState($iomadsaml2auth->spname))) {
        $session->registerLogoutHandler(
            $iomadsaml2auth->spname,
            '\auth_iomadsaml2\api',
            'logout_from_idp_front_channel'
        );
    }
    $config = \SimpleSAML\Configuration::getInstance();
    $session = \SimpleSAML\Session::getSessionFromRequest();
    $controller = new \SimpleSAML\Module\saml\Controller\ServiceProvider($config, $session);
    $acs = $controller->singleLogoutService($iomadsaml2auth->spname);
    $acs->sendContent();
} catch (Exception $e) {
    redirect(new moodle_url('/'));
}
