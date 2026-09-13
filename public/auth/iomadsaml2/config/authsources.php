<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * SSP auth sources which inherits from Moodle config
 *
 * @package    auth_iomadsaml2
 * @copyright  Brendan Heywood <brendan@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use auth_iomadsaml2\ssl_signing_algorithm;
use local_iomad\iomad;

defined('MOODLE_INTERNAL') || die();

global $iomadsaml2auth, $CFG, $SITE, $SESSION, $DB;

$config = [];

$baseurl = optional_param('baseurl', $CFG->wwwroot, PARAM_URL);

$idpentityid = null;
if (!empty($SESSION->iomadsaml2idp) && array_key_exists($SESSION->iomadsaml2idp, $iomadsaml2auth->metadataentities)) {
    $idpentityid = $iomadsaml2auth->metadataentities[$SESSION->iomadsaml2idp]->entityid;
} else if (!empty($iomadsaml2auth->metadataentities)) {
    // Case for specifying no $SESSION IdP, select the first configured IdP as the default.
    $idpentityid = reset($iomadsaml2auth->metadataentities)->entityid;
}

$defaultspentityid = "$baseurl/auth/iomadsaml2/sp/metadata.php";
$spurls = null;

// Process requested attributes.
$attributes = [];
$attributesrequired = [];

foreach (explode(PHP_EOL, $iomadsaml2auth->config->requestedattributes ?? "") as $attr) {
    $attr = trim($attr);
    if (empty($attr)) {
        continue;
    }
    if (substr($attr, -2, 2) === ' *') {
        $attr = substr($attr, 0, -2);
        $attributesrequired[] = $attr;
    }
    $attributes[] = $attr;
}
// Moodle language code does not always map to the iso code, which is preferable for xml:lang attributes.
$lang = get_string('iso6391', 'core_langconfig');

// IOMAD
$companyid = iomad::get_my_companyid(context_system::instance(), false);
if ($companyid > 0) {
    $postfix = "_$companyid";
} else {
    $postfix = "";
}

$orgname = $SITE->shortname;
$orgdisplay = $SITE->fullname;
if ($companyid > 0 && class_exists(\local_privacient\saml_sp::class)) {
    $spurls = \local_privacient\saml_sp::urls((int) $companyid);
    $defaultspentityid = $spurls['entityid'];
    $company = $DB->get_record('local_iomad_companies', ['id' => $companyid], 'name,shortname');
    if ($company) {
        $orgname = $company->shortname;
        $orgdisplay = $company->name;
    }
}

$config[$iomadsaml2auth->spname] = [
    'saml:SP',
    'entityID' => !empty($iomadsaml2auth->config->spentityid) ? $iomadsaml2auth->config->spentityid : $defaultspentityid,
    'discoURL' => !empty($CFG->auth_iomadsaml2_disco_url) ? $CFG->auth_iomadsaml2_disco_url : null,
    'idp' => empty($CFG->auth_iomadsaml2_disco_url) ? $idpentityid : null,
    'NameIDPolicy' => ['Format' => $iomadsaml2auth->config->nameidpolicy, 'AllowCreate' => true],
    'OrganizationName' => array(
        $lang => $orgname,
    ),
    'OrganizationDisplayName' => array(
        $lang => $orgdisplay,
    ),
    'OrganizationURL' => array(
        $lang => $baseurl,
    ),
    'privatekey' => $iomadsaml2auth->spname . '.pem',
    'privatekey_pass' => (static function() use ($postfix) {
        $pass = get_config('auth_iomadsaml2', 'privatekeypass' . $postfix);
        if ($pass === false) {
            $pass = get_config('auth_iomadsaml2', 'privatekeypass');
        }
        return is_string($pass) ? $pass : null;
    })(),
    'certificate' => $iomadsaml2auth->spname . '.crt',
    'sign.logout' => true,
    'redirect.sign' => true,
    'signature.algorithm' => $iomadsaml2auth->config->signaturealgorithm,
    'WantAssertionsSigned' => $iomadsaml2auth->config->wantassertionssigned == 1,

    'name' => [
        $lang => $SITE->fullname,
    ],
    'attributes' => $attributes,
    'attributes.required' => $attributesrequired,
];

if (is_array($spurls)) {
    $config[$iomadsaml2auth->spname]['AssertionConsumerService'] = [[
        'index' => 0,
        'Binding' => \SAML2\Constants::BINDING_HTTP_POST,
        'Location' => $spurls['acsurl'],
    ]];
    $config[$iomadsaml2auth->spname]['SingleLogoutServiceLocation'] = $spurls['slsurl'];
    $config[$iomadsaml2auth->spname]['SingleLogoutServiceBinding'] = [
        \SAML2\Constants::BINDING_HTTP_REDIRECT,
    ];
}

if (!empty($iomadsaml2auth->config->assertionsconsumerservices)) {
    $config[$iomadsaml2auth->spname]['acs.Bindings'] = explode(',', $iomadsaml2auth->config->assertionsconsumerservices);
}

if (!empty($iomadsaml2auth->config->authncontext)) {
    $config[$iomadsaml2auth->spname]['AuthnContextClassRef'] = $iomadsaml2auth->config->authncontext;
}

/*
 * If we're configured to expose the nameid as an attribute, set this authproc filter up
 * the nameid value appears under the attribute "nameid"
 */
if ($iomadsaml2auth->config->nameidasattrib) {
    $config[$iomadsaml2auth->spname]['authproc'] = array(
        20 => array(
            'class' => 'saml:NameIDAttribute',
            'format' => '%V',
        ),
    );
}
