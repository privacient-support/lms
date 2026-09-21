<?php
/**
 * Company-specific SAML 2.0 service-provider metadata.
 *
 * Public: identity providers fetch this without a Moodle login. The company
 * is the trailing path (`/metadata.php/4`), which is also the entity ID.
 */
require_once(__DIR__ . '/../../../config.php');

$companyid = \local_privacient\saml_sp::bootstrap();
$token = \local_privacient\saml_sp::token($companyid);

require_once($CFG->dirroot . '/auth/iomadsaml2/locallib.php');

$download = optional_param('download', '', PARAM_RAW);
if ($download) {
    header('Content-Disposition: attachment; filename=privacient-sp-' . $token . '.xml');
}

// Force a rebuild so a company never receives another company's cached XML.
$file = $iomadsaml2auth->get_file_sp_metadata_file();
@unlink($file);

$xml = auth_iomadsaml2_get_sp_metadata($CFG->wwwroot);

header('Content-Type: text/xml; charset=utf-8');
echo $xml;
