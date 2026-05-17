<?php

namespace OPNsense\Netboot\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Service control endpoints for the three Netboot daemons.
 *
 *   POST /api/netboot/service/start           -> startAction
 *   POST /api/netboot/service/stop            -> stopAction
 *   POST /api/netboot/service/restart         -> restartAction
 *   GET  /api/netboot/service/status          -> statusAction
 *   POST /api/netboot/service/reconfigure     -> reconfigureAction
 *   POST /api/netboot/service/bootstrap_netboot_xyz
 *
 * 'reconfigure' is the canonical OPNsense pattern for "user clicked Save":
 *   1. render templates from the freshly-saved model
 *   2. restart the affected daemons
 * It's the verb the Volt view binds to the Save button.
 *
 * Internally we group the three daemons (tftpd, http, sftp) under one logical
 * 'netboot' service for status purposes -- the status indicator on the page
 * is green iff all enabled daemons are running.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = 'OPNsense\Netboot\Netboot';
    protected static $internalServiceTemplate = 'OPNsense/Netboot';
    protected static $internalServiceEnabled = 'general.enabled';
    protected static $internalServiceName = 'netboot';

    /**
     * Aggregate status across the three daemons. Returns "running" iff every
     * enabled daemon is running, "stopped" if none are, otherwise "partial".
     */
    public function statusAction()
    {
        $backend = new Backend();
        $model = $this->getModel();

        $enabled = (string)$model->general->enabled === '1';
        if (!$enabled) {
            return ['status' => 'disabled'];
        }

        $services = ['tftpd', 'http'];
        if ((string)$model->sftp->enabled === '1') {
            $services[] = 'sftp';
        }

        $running = 0;
        $details = [];
        foreach ($services as $name) {
            $out = trim($backend->configdRun("netboot status_{$name}"));
            $is_running = (stripos($out, 'is running') !== false);
            $details[$name] = $is_running ? 'running' : 'stopped';
            if ($is_running) {
                $running++;
            }
        }

        $status = ($running === count($services)) ? 'running'
                : (($running === 0) ? 'stopped' : 'partial');

        return ['status' => $status, 'detail' => $details];
    }

    /**
     * Render templates and restart all three daemons in dependency order.
     * Wired to the Save button in the Volt view.
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('The "reconfigure" action requires POST. Received %s. Reconfigure mutates daemon state (runs setup, re-renders templates, restarts services) and is intentionally not exposed as GET. Have the GUI call $.post(...) or use -X POST with curl.'),
                    $this->request->getMethod()
                ),
            ];
        }

        $backend = new Backend();
        $model = $this->getModel();

        // 1. Ensure runtime preconditions (service user, content root,
        //    support dirs). Idempotent.
        $backend->configdRun('netboot setup');

        // 2. Render templates from the freshly-saved config.
        $backend->configdRun('template reload OPNsense/Netboot');

        $enabled = (string)$model->general->enabled === '1';

        // 3. Stop everything first (idempotent if already stopped).
        $backend->configdRun('netboot stop_sftp');
        $backend->configdRun('netboot stop_http');
        $backend->configdRun('netboot stop_tftpd');

        // 4. If enabled, start what should be running.
        if ($enabled) {
            $backend->configdRun('netboot start_tftpd');
            $backend->configdRun('netboot start_http');
            if ((string)$model->sftp->enabled === '1') {
                $backend->configdRun('netboot start_sftp');
            }
        }

        return ['status' => 'ok'];
    }

    /**
     * Server-side fetch of both netboot.xyz iPXE binaries into the content
     * root. Idempotent -- safe to re-run to refresh after netboot.xyz
     * publishes a new build.
     */
    public function bootstrapNetbootXyzAction()
    {
        if (!$this->request->isPost()) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('The "bootstrap_netboot_xyz" action requires POST. Received %s. This action fetches files from the internet and writes them into the content root, so it is not exposed as a GET. Have the GUI call $.post(...) or use -X POST with curl.'),
                    $this->request->getMethod()
                ),
            ];
        }
        $backend = new Backend();
        $output = $backend->configdRun('netboot bootstrap_netboot_xyz');
        return ['status' => 'ok', 'output' => $output];
    }
}
