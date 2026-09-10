<?php
/**
 * Privacient console integration for IOMAD.
 *
 * Exposes the one operation IOMAD performs internally but does not publish over
 * its web-service API: deleting a company. The console needs it so that
 * deleting a tenant removes that customer from every system, not just from the
 * control plane.
 */
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_privacient';
$plugin->version   = 2026091015;
$plugin->requires  = 2025100600;   // Moodle 5.1
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.10.0';
