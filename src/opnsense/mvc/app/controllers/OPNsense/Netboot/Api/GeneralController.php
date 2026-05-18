<?php

namespace OPNsense\Netboot\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/**
 * REST CRUD over the Netboot model (OPNsense.netboot.*).
 *
 * Inherited from ApiMutableModelControllerBase:
 *   GET    /api/netboot/general/get          -> getAction (returns the full model)
 *   POST   /api/netboot/general/set          -> setAction (writes the model, validates)
 *
 * The OPNsense convention is that controllers don't render daemon configs
 * themselves -- saving the model writes config.xml, and a separate
 * configctl call (here: 'netboot reload') re-renders the templates and
 * restarts the services. The Volt view's Save button does both.
 */
class GeneralController extends ApiMutableModelControllerBase
{
    // The JSON envelope key used in both directions of the API:
    //   GET  /api/netboot/general/get -> {"netboot": {"general": {...}, "sftp": {...}}}
    //   POST /api/netboot/general/set <- {"netboot": {"general": {...}, "sftp": {...}}}
    //
    // The OPNsense form binder (setFormData / getFormData in www/js/opnsense.js)
    // walks an input element's id token-by-token through this envelope, so all
    // form field ids in forms/general.xml MUST start with "netboot." to match.
    // Picking 'netboot' here (not 'general') avoids a name collision with the
    // <general> section inside the model; if both layers were 'general' the
    // walker would descend once and then never find the field's section.
    protected static $internalModelName = 'netboot';
    protected static $internalModelClass = 'OPNsense\Netboot\Netboot';
}
