<?php

namespace OPNsense\Netboot;

use OPNsense\Base\IndexController;

/**
 * Page controller for Services -> Netboot -> General.
 *
 * Routes:
 *   /ui/netboot/general          -> indexAction
 *
 * Form fields are described in mvc/app/controllers/OPNsense/Netboot/forms/general.xml
 * and bound to the OPNsense.netboot.* config tree via the Netboot model.
 *
 * No business logic lives here -- this controller just renders the Volt
 * view and lets the JS in the view talk to the API controllers.
 */
class GeneralController extends IndexController
{
    public function indexAction()
    {
        $this->view->title = gettext('Netboot: General');
        $this->view->generalForm = $this->getForm('general');
        $this->view->pick('OPNsense/Netboot/general');
    }
}
