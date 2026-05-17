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
    protected static $internalModelName = 'general';
    protected static $internalModelClass = 'OPNsense\Netboot\Netboot';
}
