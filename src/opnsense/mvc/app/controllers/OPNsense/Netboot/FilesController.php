<?php

namespace OPNsense\Netboot;

use OPNsense\Base\IndexController;

/**
 * Page controller for Services -> Netboot -> Files.
 *
 * Routes:
 *   /ui/netboot/files            -> indexAction
 *
 * This page is mostly JS -- the Volt template renders the chrome and the
 * client-side code talks to Api/FilesController for the actual operations:
 *   - GET    /api/netboot/files/list?path=...
 *   - POST   /api/netboot/files/upload
 *   - POST   /api/netboot/files/delete
 *   - POST   /api/netboot/files/mkdir
 *   - POST   /api/netboot/files/fetch_url
 *   - POST   /api/netboot/files/bootstrap_netboot_xyz
 *   - GET    /api/netboot/files/download?path=...
 */
class FilesController extends IndexController
{
    public function indexAction()
    {
        $this->view->title = gettext('Netboot: Files');
        $this->view->pick('OPNsense/Netboot/files');
    }
}
