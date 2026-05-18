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
 *   GET  /api/netboot/service/bootstrap_presets   -> bootstrapPresetsAction (catalog of presets)
 *   POST /api/netboot/service/bootstrap           -> bootstrapAction       ({preset: "..."})
 *   POST /api/netboot/service/bootstrap_netboot_xyz                        (legacy alias)
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
     * Catalog of one-click iPXE bootstrap presets the GUI can fetch. The
     * key is what we pass to `configctl netboot bootstrap <key>`, which the
     * script then translates into upstream URLs and on-disk filenames.
     *
     * Adding a preset here is a two-line change:
     *   1) add the case to scripts/netboot/bootstrap.sh
     *   2) add the row here
     * No GUI change needed -- the Files page reads this list at load time
     * to render the dropdown.
     */
    private static $bootstrapPresets = [
        'netboot_xyz' => [
            'label' => 'netboot.xyz (recommended)',
            'description' => 'Fetches netboot.xyz.kpxe (BIOS) and netboot.xyz.efi (UEFI). At PXE boot, chains to the public netboot.xyz menu -- OS installers, rescue tools, memtest. Requires WAN connectivity from your PXE clients at boot time.',
        ],
        'ipxe' => [
            'label' => 'Stock iPXE (advanced)',
            'description' => 'Fetches undionly.kpxe and ipxe.efi from boot.ipxe.org, saved locally as ipxe.kpxe / ipxe.efi. These drop to the iPXE shell at boot; pair with your own menu.ipxe in the content root if you want a menu. For users who don\'t want to depend on netboot.xyz at boot time.',
        ],
    ];

    /**
     * GET /api/netboot/service/bootstrap_presets
     * Returns the catalog (key => label/description) so the GUI can render
     * the dropdown without duplicating the list client-side.
     */
    public function bootstrapPresetsAction()
    {
        return ['status' => 'ok', 'presets' => self::$bootstrapPresets];
    }

    /**
     * GET /api/netboot/service/diag
     * Self-check: confirm the on-disk files this controller and its
     * configd actions depend on. Exists specifically so we don't have to
     * shell into the box to answer "is the right pkg installed and did
     * its files get extracted properly?" -- the GUI can call this and
     * show a yes/no table. Kept intentionally trivial: no side effects,
     * no privileged data, just stat() on a handful of known paths.
     */
    public function diagAction()
    {
        $paths = [
            'scripts.setup'         => '/usr/local/opnsense/scripts/netboot/setup.sh',
            'scripts.bootstrap'     => '/usr/local/opnsense/scripts/netboot/bootstrap.sh',
            'scripts.fetch_url'     => '/usr/local/opnsense/scripts/netboot/fetch_url.sh',
            'actions.netboot'       => '/usr/local/opnsense/service/conf/actions.d/actions_netboot.conf',
            'plugin.version'        => '/usr/local/opnsense/version/netboot',
        ];
        $result = [];
        foreach ($paths as $key => $p) {
            $result[$key] = [
                'path'       => $p,
                'exists'     => file_exists($p),
                'is_file'    => is_file($p),
                'executable' => is_executable($p),
                'size'       => file_exists($p) ? filesize($p) : null,
            ];
        }

        // Try running a short configd action and capture whatever comes
        // back. This is the same path Bootstrap takes; if it returns
        // empty here too the problem is in configd <-> script execution,
        // not in our PHP code.
        $backend = new Backend();
        $probe   = $backend->configdRun('netboot setup');
        $result['probe.setup_output'] = (string)$probe;
        $result['probe.setup_output_len'] = strlen((string)$probe);

        // Read the version file directly so we can confirm which build is
        // actually on the box.
        $result['plugin.version_content'] = file_exists($paths['plugin.version'])
            ? trim((string)file_get_contents($paths['plugin.version']))
            : null;

        return ['status' => 'ok', 'diag' => $result];
    }

    /**
     * Server-side fetch of an iPXE bootstrap preset (BIOS .kpxe + UEFI .efi)
     * into the content root. Idempotent -- safe to re-run to refresh after
     * the upstream publishes a new build.
     *
     * POST /api/netboot/service/bootstrap   {"preset": "netboot_xyz"}
     *
     * The legacy bootstrap_netboot_xyz endpoint stays as a thin alias for
     * older URLs that may be cached in browser histories.
     */
    public function bootstrapAction()
    {
        if (!$this->request->isPost()) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('The "bootstrap" action requires POST. Received %s. This action fetches files from the internet and writes them into the content root, so it is intentionally not exposed as a GET. Have the GUI call $.post(...) or use -X POST with curl.'),
                    $this->request->getMethod()
                ),
            ];
        }
        return $this->runBootstrap((string)$this->request->getPost('preset', 'string', 'netboot_xyz'));
    }

    /**
     * Shared implementation of bootstrapAction / bootstrapNetbootXyzAction.
     * Validates the preset, runs setup (idempotent), runs the bootstrap
     * script, returns a result envelope with the captured output and a
     * meaningful status/message on failure.
     */
    private function runBootstrap($preset)
    {
        if (!array_key_exists($preset, self::$bootstrapPresets)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Unknown bootstrap preset "%s". Expected one of: %s. The GUI Quick start menu and Api/ServiceController.php::$bootstrapPresets must list the same keys; if you reached this from the GUI, your browser may be running cached JS -- hard-reload (Ctrl-Shift-R) and try again.'),
                    $preset,
                    implode(', ', array_keys(self::$bootstrapPresets))
                ),
            ];
        }

        $backend = new Backend();

        // Belt-and-suspenders: setup is also auto-run on plugin install
        // (+POST_INSTALL.post) and on reconfigure, but if someone has
        // managed to delete the content root or the service user out from
        // under us, do it again here so the next call to fetch doesn't
        // explode on a missing chown target.
        $backend->configdRun('netboot setup');

        // configdRun returns the script's combined stdout/stderr as a
        // string. An empty string means the script exited 0 with no
        // output (unusual) or that configd couldn't run it at all (more
        // likely on a broken install). Either way the GUI should surface
        // that rather than claiming success.
        $output = $backend->configdRun('netboot bootstrap ' . escapeshellarg($preset));
        if (trim((string)$output) === '') {
            return [
                'status'  => 'failed',
                'output'  => '',
                'message' => sprintf(
                    gettext('The bootstrap script for preset "%s" produced no output. Expected progress lines (Fetching ..., -> /var/netboot/...). Likely causes: configd cannot exec the script (check /var/log/configd.log), the action is not yet registered (re-run "configctl template reload OPNsense/Netboot" or restart configd), or the script crashed before its first echo. Check /var/log/configd.log on the firewall.'),
                    $preset
                ),
            ];
        }

        // Heuristic: the success path ends with "Done. ..." on stdout. Any
        // "ERROR:" line means the script failed somewhere even though
        // configd captured the message.
        $failed = (strpos($output, "ERROR:") !== false);
        return [
            'status' => $failed ? 'failed' : 'ok',
            'output' => $output,
            'preset' => $preset,
        ];
    }

    /**
     * Backwards-compatible alias for the old single-preset endpoint.
     * Equivalent to POST /api/netboot/service/bootstrap with preset=netboot_xyz.
     */
    public function bootstrapNetbootXyzAction()
    {
        if (!$this->request->isPost()) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('The "bootstrap_netboot_xyz" action requires POST. Received %s.'),
                    $this->request->getMethod()
                ),
            ];
        }
        return $this->runBootstrap('netboot_xyz');
    }
}
