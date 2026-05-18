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
 *   - GET    /api/netboot/files/download?path=...
 *
 * The Bootstrap-presets endpoints live on the Service controller:
 *   - GET    /api/netboot/service/bootstrap_presets
 *   - POST   /api/netboot/service/bootstrap   {preset: "netboot_xyz" | "ipxe"}
 */
class FilesController extends IndexController
{
    public function indexAction()
    {
        $this->view->title = gettext('Netboot: Files');
        $this->view->pick('OPNsense/Netboot/files');
    }
}
