<?php

namespace OPNsense\Netboot\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Netboot\HttpFetcher;

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
 * Bootstrap is implemented in PHP using the audited libcurl wrapper at
 * mvc/app/library/OPNsense/Netboot/HttpFetcher.php. No shell, no configd
 * action -- the privileged step is the one-time setgid directory setup
 * by setup.sh, not each individual fetch.
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
     * Catalog of one-click iPXE bootstrap presets the GUI can fetch.
     * Server-defined so the URLs aren't user-controllable (no SSRF
     * surface at this entry point -- see HttpFetcher::rejectInternalAddress
     * which is only called for FilesController::fetchUrl).
     *
     * Each entry's 'files' is a list of (remote_url, local_name) pairs.
     * remote_url is fetched verbatim with the hardened HttpFetcher; the
     * local_name is the filename the binary lands under in the content
     * root. local_name is constrained by PathResolver::isSafeName when
     * we run the fetch.
     *
     * Adding a preset is a single-file edit here. The GUI dropdown is
     * populated from this list via /api/netboot/service/bootstrap_presets,
     * so no JS change is needed.
     */
    private static $bootstrapPresets = [
        'netboot_xyz' => [
            'label' => 'netboot.xyz (recommended)',
            'description' => 'Fetches netboot.xyz.kpxe (BIOS) and netboot.xyz.efi (UEFI). At PXE boot, chains to the public netboot.xyz menu -- OS installers, rescue tools, memtest. Requires WAN connectivity from your PXE clients at boot time.',
            'files' => [
                ['https://boot.netboot.xyz/ipxe/netboot.xyz.kpxe', 'netboot.xyz.kpxe'],
                ['https://boot.netboot.xyz/ipxe/netboot.xyz.efi',  'netboot.xyz.efi'],
            ],
        ],
        'ipxe' => [
            'label' => 'Stock iPXE (advanced)',
            'description' => 'Fetches undionly.kpxe and ipxe.efi from boot.ipxe.org, saved locally as ipxe.kpxe / ipxe.efi. These drop to the iPXE shell at boot; pair with your own menu.ipxe in the content root if you want a menu. For users who don\'t want to depend on netboot.xyz at boot time.',
            'files' => [
                ['https://boot.ipxe.org/undionly.kpxe', 'ipxe.kpxe'],
                ['https://boot.ipxe.org/ipxe.efi',     'ipxe.efi'],
            ],
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
     * Server-side fetch of an iPXE bootstrap preset (BIOS .kpxe + UEFI .efi)
     * into the content root. Idempotent -- safe to re-run to refresh after
     * the upstream publishes a new build.
     *
     * POST /api/netboot/service/bootstrap   {"preset": "netboot_xyz"}
     *
     * Implementation: hardened PHP libcurl via OPNsense\Netboot\HttpFetcher.
     * No shell, no configd action -- the previous shell+configd path lost
     * exit codes through configd's lossy script_output protocol and was
     * also four parsers deep (PHP -> escapeshellarg -> configd shlex ->
     * subprocess shell -> fetch(1)) for the URL string. PHP libcurl is a
     * single C-string call with real return values and is the same
     * library OPNsense's own firmware updater uses.
     *
     * Why this is allowed to write to /var/netboot without configd:
     * setup.sh makes the content root mode 02775 (setgid) with group
     * _netboot and owner www. PHP-written files inherit the _netboot
     * group; mode 0644 by default; daemons keep their accustomed read
     * access. See setup.sh for the longer rationale.
     */
    public function bootstrapAction()
    {
        if (!$this->request->isPost()) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('The "bootstrap" action requires POST. Received %s. This action writes files into the content root and reaches out to the internet, so it is intentionally not exposed as a GET. Have the GUI call $.post(...) or use -X POST with curl.'),
                    $this->request->getMethod()
                ),
            ];
        }
        return $this->runBootstrap((string)$this->request->getPost('preset', 'string', 'netboot_xyz'));
    }

    /**
     * Backwards-compatible alias for the old single-preset endpoint, kept
     * because earlier docs and browser histories may still reference it.
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

    /**
     * Shared implementation of bootstrap* actions.
     *
     * Looks up the preset, ensures the content root exists, then drives
     * HttpFetcher for each (URL, local-name) pair in the preset. Returns
     * one result entry per file so the GUI can show per-file success/
     * failure rather than collapsing to a single ok/failed verdict.
     *
     * Per-file failures DO NOT abort the run -- if the BIOS binary
     * downloads but the UEFI one fails, the BIOS file stays in place
     * and the result envelope reports exactly that. The GUI colors the
     * output box red if any file failed.
     */
    private function runBootstrap($preset)
    {
        if (!array_key_exists($preset, self::$bootstrapPresets)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Unknown bootstrap preset "%s". Expected one of: %s. The GUI Quick start dropdown is populated from /api/netboot/service/bootstrap_presets; if you reached this from the GUI, your browser may be running cached JS -- hard-reload (Ctrl-Shift-R) and try again.'),
                    $preset,
                    implode(', ', array_keys(self::$bootstrapPresets))
                ),
            ];
        }

        // Read content root from the saved model (default /var/netboot).
        // Resolve to an absolute realpath so we can sanity-check that the
        // assembled file paths stay inside it.
        $model = $this->getModel();
        $contentRoot = '';
        if (isset($model->general->content_root)) {
            $contentRoot = (string)$model->general->content_root;
        }
        if ($contentRoot === '') {
            $contentRoot = '/var/netboot';
        }

        // Belt-and-suspenders: setup is also wired into +POST_INSTALL.post
        // and runs on every reconfigureAction, but if someone has
        // managed to wipe the content root out from under us we'd rather
        // self-heal than fail on a "destination not writable" error
        // from HttpFetcher. configd setup is fast and idempotent.
        if (!is_dir($contentRoot) || !is_writable($contentRoot)) {
            (new Backend())->configdRun('netboot setup');
        }

        $realRoot = realpath($contentRoot);
        if ($realRoot === false || !is_dir($realRoot)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Content root "%s" does not resolve to an existing directory. Expected: an absolute directory path that setup.sh has created (or is willing to create) and made writable by the webGUI user. Open Services -> Netboot -> General and Save.'),
                    $contentRoot
                ),
            ];
        }

        $fetcher = new HttpFetcher();
        $files   = [];
        $anyFailed = false;

        foreach (self::$bootstrapPresets[$preset]['files'] as $pair) {
            list($url, $localName) = $pair;
            // Defensive: the local_name is server-defined here but the
            // realpath check below catches any future bug that lets a
            // ../ in.
            $dest = $realRoot . DIRECTORY_SEPARATOR . $localName;
            $destReal = $this->confineToRoot($dest, $realRoot);
            if ($destReal === null) {
                $files[] = [
                    'url'   => $url,
                    'name'  => $localName,
                    'ok'    => false,
                    'error' => sprintf(
                        gettext('Refusing to write "%s": resolved destination falls outside the content root "%s". This is an internal error -- the preset definition contains a path with ".." or an absolute component.'),
                        $localName,
                        $realRoot
                    ),
                ];
                $anyFailed = true;
                continue;
            }

            // No SSRF check on the bootstrap path -- the URL list is a
            // server-side constant. Public hosts (boot.netboot.xyz,
            // boot.ipxe.org) are explicitly safe and don't need the
            // dns_get_record dance, which would just slow this down.
            $res = $fetcher->fetch($url, $destReal, ['enforce_safe_url' => false]);
            $files[] = [
                'url'       => $res['url'],
                'name'      => $localName,
                'dest'      => $res['dest'],
                'ok'        => $res['ok'],
                'bytes'     => $res['bytes'],
                'http_code' => $res['http_code'],
                'errno'     => $res['errno'],
                'error'     => $res['error'],
            ];
            if (!$res['ok']) {
                $anyFailed = true;
            }
        }

        return [
            'status' => $anyFailed ? 'failed' : 'ok',
            'preset' => $preset,
            'files'  => $files,
        ];
    }

    /**
     * Return $path's realpath iff it resolves inside $root, else null.
     * Used to prove that an assembled destination cannot escape the
     * content root via "..", symlinks, or absolute components in a
     * preset definition. If the path doesn't exist yet (the file we're
     * about to write doesn't exist), we resolve its parent instead and
     * re-attach the basename.
     */
    private function confineToRoot(string $path, string $root): ?string
    {
        $parent = dirname($path);
        $base   = basename($path);
        $realParent = realpath($parent);
        if ($realParent === false) {
            return null;
        }
        $candidate = $realParent . DIRECTORY_SEPARATOR . $base;
        $rootWithSep = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($candidate, $rootWithSep, strlen($rootWithSep)) !== 0
            && $candidate !== $root
        ) {
            return null;
        }
        return $candidate;
    }
}
