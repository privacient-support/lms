<?php
/**
 * Company-specific Assertion Consumer Service.
 *
 * Azure POSTs the assertion here. Binding the company from the URL first is
 * what lets SimpleSAMLphp load that company's IdP metadata when the POST
 * arrives with no Moodle session cookie.
 */
require_once(__DIR__ . '/../../../config.php');

\local_privacient\saml_sp::bootstrap();

$_SERVER['PATH_INFO'] = '/' . $iomadsaml2auth->spname;

try {
    $config = \SimpleSAML\Configuration::getInstance();
    $session = \SimpleSAML\Session::getSessionFromRequest();
    $controller = new \SimpleSAML\Module\saml\Controller\ServiceProvider($config, $session);
    $acs = $controller->assertionConsumerService($iomadsaml2auth->spname);
    $acs->sendContent();
} catch (Exception $e) {
    throw new iomadsaml2_exception($e->getMessage(), $e->getTraceAsString());
}
