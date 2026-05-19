<?php

namespace OPNsense\Netboot\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Config;
use OPNsense\Netboot\HttpFetcher;
use OPNsense\Netboot\PathResolver;

/**
 * File management endpoints over the Netboot content root.
 *
 * Routes:
 *   GET  /api/netboot/files/list?path=<rel>           -> list directory
 *   POST /api/netboot/files/upload                    -> multipart upload
 *   POST /api/netboot/files/delete  {"path": "..."}   -> delete one file/dir
 *   POST /api/netboot/files/mkdir   {"path": "..."}   -> create subdirectory
 *   POST /api/netboot/files/fetch_url {"url": "...", "name": "..."}
 *   GET  /api/netboot/files/download?path=<rel>       -> stream file
 *
 * Every path the client sends is treated as untrusted. We resolve it
 * relative to the configured content root, realpath() the result, and
 * REJECT anything that escapes the root. No '..' shenanigans, no
 * symlink-out, no absolute paths from the client.
 *
 * fetch_url is special because the URL is also user-supplied. It goes
 * through the same audited libcurl wrapper as bootstrap (HttpFetcher),
 * with enforce_safe_url=true so the wrapper additionally resolves the
 * hostname and rejects RFC1918, loopback, link-local, multicast, and
 * reserved address space -- preventing the webGUI user from coercing
 * the firewall into making HTTP requests at its own management plane
 * or LAN devices.
 *
 * Error messages: every failure path includes (a) the specific value
 * that was rejected and (b) what was expected. "Path not found" alone
 * never appears -- the GUI surfaces these messages to the operator and
 * a vague string forces them to guess.
 */
class FilesController extends ApiControllerBase
{
    /**
     * @return string absolute, canonical path to the content root, or '' if not configured.
     */
    private function contentRoot(): string
    {
        $cfg = Config::getInstance()->object();
        $root = (string)($cfg->OPNsense->netboot->general->content_root ?? '/var/netboot');
        $resolved = realpath($root);
        return $resolved !== false ? $resolved : '';
    }

    /**
     * Resolve a user-supplied relative path within the content root.
     * Thin wrapper around OPNsense\Netboot\PathResolver::within so the
     * security-critical logic lives in a separately unit-testable class
     * (see tests/PathResolverTest.php).
     */
    private function resolveWithin(string $relPath, bool $mustExist = true): string
    {
        $root = $this->contentRoot();
        if ($root === '') {
            return '';
        }
        return PathResolver::within($root, $relPath, $mustExist);
    }

    /**
     * Build a standard "method not allowed" failure. The Files API endpoints
     * that mutate state require POST; GET is reserved for safe reads.
     */
    private function mustBePost(string $action): array
    {
        return [
            'status'  => 'failed',
            'message' => sprintf(
                gettext('The "%s" action requires POST. Received %s. Have the GUI call $.post(...) or use -X POST with curl.'),
                $action,
                $this->request->getMethod()
            ),
        ];
    }

    /**
     * Build a "content root missing or unconfigured" failure.
     */
    private function rootUnavailable(): array
    {
        $cfg = Config::getInstance()->object();
        $configured = (string)($cfg->OPNsense->netboot->general->content_root ?? '/var/netboot');
        return [
            'status'  => 'failed',
            'message' => sprintf(
                gettext('The Netboot content root is not accessible. Configured value is "%s"; expected an existing absolute directory. Open Services -> Netboot -> General, set Content root to an existing path, and Save (which runs "configctl netboot setup" to create it if missing).'),
                $configured
            ),
        ];
    }

    public function listAction()
    {
        $rel = (string)$this->request->get('path', 'string', '');
        if ($this->contentRoot() === '') {
            return $this->rootUnavailable();
        }
        $dir = $this->resolveWithin($rel, true);
        if ($dir === '') {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Cannot list "%s": the path does not exist inside the content root, or it tries to escape the root via "..", an absolute path, or a symlink that resolves outside. Expected: a relative path that resolves to an existing directory under "%s".'),
                    $rel,
                    $this->contentRoot()
                ),
            ];
        }
        if (!is_dir($dir)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Cannot list "%s": the path resolves to "%s", which is not a directory. Expected: a directory. Use the download action on individual files.'),
                    $rel,
                    $dir
                ),
            ];
        }

        $entries = [];
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs = $dir . '/' . $name;
            $entries[] = [
                'name'   => $name,
                'is_dir' => is_dir($abs),
                'size'   => is_file($abs) ? filesize($abs) : 0,
                'mtime'  => filemtime($abs),
            ];
        }

        usort($entries, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return ['status' => 'ok', 'path' => $rel, 'entries' => $entries];
    }

    public function uploadAction()
    {
        if (!$this->request->isPost()) {
            return $this->mustBePost('upload');
        }
        if ($this->contentRoot() === '') {
            return $this->rootUnavailable();
        }
        $relDir = (string)$this->request->getPost('path', 'string', '');
        $destDir = $this->resolveWithin($relDir, true);
        if ($destDir === '' || !is_dir($destDir)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Cannot upload into "%s": the destination directory does not exist under the content root "%s", or the path escapes it. Expected: a relative directory that already exists. Use mkdir first if you need a new subdirectory.'),
                    $relDir,
                    $this->contentRoot()
                ),
            ];
        }

        $results = [];
        foreach ($this->request->getUploadedFiles() as $upload) {
            $original = $upload->getName();
            $name = basename($original);
            if (!PathResolver::isSafeName($name)) {
                $results[] = [
                    'name'    => $original,
                    'status'  => 'rejected',
                    'message' => sprintf(
                        gettext('Filename "%s" is rejected. Expected: a non-empty name that does not start with a dot and contains no slashes, backslashes, or control characters. Rename and retry.'),
                        $original
                    ),
                ];
                continue;
            }
            $target = $destDir . '/' . $name;
            $tmp = $target . '.upload.' . bin2hex(random_bytes(4));
            if (!$upload->moveTo($tmp)) {
                $results[] = [
                    'name'    => $name,
                    'status'  => 'failed',
                    'message' => sprintf(
                        gettext('Failed to write uploaded content to staging path "%s". Expected: writable directory at "%s" owned by _netboot. Fix: re-save Netboot settings to run "configctl netboot setup", which resets the content-root ownership.'),
                        $tmp,
                        $destDir
                    ),
                ];
                continue;
            }
            @chmod($tmp, 0644);
            @chown($tmp, '_netboot');
            @chgrp($tmp, '_netboot');
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                $results[] = [
                    'name'    => $name,
                    'status'  => 'failed',
                    'message' => sprintf(
                        gettext('Wrote staging file "%s" but could not atomically rename to "%s". Expected: same-filesystem rename permission. Fix: ensure the content root is not a cross-mount symlink target.'),
                        $tmp,
                        $target
                    ),
                ];
                continue;
            }
            $results[] = ['name' => $name, 'status' => 'ok', 'size' => filesize($target)];
        }

        return ['status' => 'ok', 'results' => $results];
    }

    public function deleteAction()
    {
        if (!$this->request->isPost()) {
            return $this->mustBePost('delete');
        }
        if ($this->contentRoot() === '') {
            return $this->rootUnavailable();
        }
        $rel = (string)$this->request->getPost('path', 'string', '');
        if ($rel === '') {
            return [
                'status'  => 'failed',
                'message' => gettext('Delete called with an empty path. Expected: a non-empty relative path to a file or empty subdirectory under the content root.'),
            ];
        }
        $target = $this->resolveWithin($rel, true);
        if ($target === '') {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Cannot delete "%s": the path does not exist inside the content root, or it tries to escape via "..", an absolute path, or a symlink resolving outside.'),
                    $rel
                ),
            ];
        }
        if ($target === $this->contentRoot()) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Refusing to delete the content root itself ("%s"). The root is created and owned by os-netboot; uninstall the plugin to remove it.'),
                    $this->contentRoot()
                ),
            ];
        }
        if (is_dir($target)) {
            if (count(scandir($target)) !== 2) {
                return [
                    'status'  => 'failed',
                    'message' => sprintf(
                        gettext('Cannot delete "%s": directory is not empty. Expected: an empty directory (recursive delete is intentionally not exposed in v0.1; delete the contents first, then the directory).'),
                        $rel
                    ),
                ];
            }
            if (!@rmdir($target)) {
                $err = error_get_last()['message'] ?? 'unknown';
                return [
                    'status'  => 'failed',
                    'message' => sprintf(
                        gettext('rmdir("%s") failed: %s. Expected: writable parent directory and removable empty target.'),
                        $target,
                        $err
                    ),
                ];
            }
        } else {
            if (!@unlink($target)) {
                $err = error_get_last()['message'] ?? 'unknown';
                return [
                    'status'  => 'failed',
                    'message' => sprintf(
                        gettext('unlink("%s") failed: %s. Expected: writable parent directory.'),
                        $target,
                        $err
                    ),
                ];
            }
        }
        return ['status' => 'ok'];
    }

    public function mkdirAction()
    {
        if (!$this->request->isPost()) {
            return $this->mustBePost('mkdir');
        }
        if ($this->contentRoot() === '') {
            return $this->rootUnavailable();
        }
        $rel = (string)$this->request->getPost('path', 'string', '');
        if ($rel === '') {
            return [
                'status'  => 'failed',
                'message' => gettext('mkdir called with an empty path. Expected: a non-empty relative path for the new directory under the content root.'),
            ];
        }
        if (PathResolver::containsTraversal(ltrim($rel, '/'))) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('mkdir path "%s" contains a ".." segment. Expected: a relative path without traversal components.'),
                    $rel
                ),
            ];
        }
        $target = $this->resolveWithin($rel, false);
        if ($target === '') {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('mkdir path "%s" would create a directory outside the content root "%s". Expected: a relative path whose parent exists and stays inside the root.'),
                    $rel,
                    $this->contentRoot()
                ),
            ];
        }
        if (file_exists($target)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Cannot create "%s": a file or directory already exists at that path (resolves to "%s"). Pick a different name or delete the existing entry first.'),
                    $rel,
                    $target
                ),
            ];
        }
        if (!@mkdir($target, 0755, true)) {
            $err = error_get_last()['message'] ?? 'unknown';
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('mkdir("%s") failed: %s. Expected: writable parent directory owned by _netboot.'),
                    $target,
                    $err
                ),
            ];
        }
        @chown($target, '_netboot');
        @chgrp($target, '_netboot');
        return ['status' => 'ok'];
    }

    public function fetchUrlAction()
    {
        if (!$this->request->isPost()) {
            return $this->mustBePost('fetch_url');
        }
        if ($this->contentRoot() === '') {
            return $this->rootUnavailable();
        }
        $url  = (string)$this->request->getPost('url', 'string', '');
        $name = (string)$this->request->getPost('name', 'string', '');
        $rel  = (string)$this->request->getPost('path', 'string', '');

        if ($url === '') {
            return [
                'status'  => 'failed',
                'message' => gettext('fetch_url called with an empty URL. Expected: a non-empty http:// or https:// URL.'),
            ];
        }
        if (!preg_match('#^https?://#', $url)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('URL "%s" has an unsupported scheme. Expected: http:// or https://. Other protocols (ftp, file, ...) are intentionally not supported -- if you need them, mirror to an HTTP server first.'),
                    $url
                ),
            ];
        }
        if ($name === '') {
            $name = basename(parse_url($url, PHP_URL_PATH) ?? 'download');
        }
        if (!PathResolver::isSafeName($name)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Cannot save fetched URL under filename "%s". Expected: a non-empty name that does not start with a dot and contains no slashes, backslashes, or control characters. Use the "name" field to override the auto-derived filename.'),
                    $name
                ),
            ];
        }
        $destDir = $this->resolveWithin($rel, true);
        if ($destDir === '' || !is_dir($destDir)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('fetch_url destination directory "%s" does not exist inside the content root "%s", or the path escapes it. Expected: a relative directory that already exists. Use mkdir first.'),
                    $rel,
                    $this->contentRoot()
                ),
            ];
        }

        // Hardened PHP libcurl fetch. enforce_safe_url=true is required
        // here because the URL is user-supplied -- without that flag a
        // webGUI operator could coerce the firewall to make HTTP
        // requests against its own management plane, internal services,
        // or other LAN devices they wouldn't otherwise be able to reach
        // (SSRF). The same audit checklist that gates server-hardcoded
        // bootstrap URLs (protocols allowlist, TLS verify, redirect cap,
        // timeouts, size cap) gates this path; the SSRF check is the
        // only additional restriction here.
        $destPath = $destDir . DIRECTORY_SEPARATOR . $name;
        $fetcher = new HttpFetcher();
        $res = $fetcher->fetch($url, $destPath, ['enforce_safe_url' => true]);

        if (!$res['ok']) {
            return [
                'status'    => 'failed',
                'url'       => $res['url'],
                'name'      => $name,
                'http_code' => $res['http_code'],
                'errno'     => $res['errno'],
                'message'   => $res['error'],
            ];
        }
        return [
            'status'    => 'ok',
            'url'       => $res['url'],
            'name'      => $name,
            'bytes'     => $res['bytes'],
            'http_code' => $res['http_code'],
        ];
    }

    public function downloadAction()
    {
        if ($this->contentRoot() === '') {
            return $this->rootUnavailable();
        }
        $rel = (string)$this->request->get('path', 'string', '');
        if ($rel === '') {
            return [
                'status'  => 'failed',
                'message' => gettext('Download called with an empty path. Expected: a relative path to a file under the content root.'),
            ];
        }
        $target = $this->resolveWithin($rel, true);
        if ($target === '') {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Cannot download "%s": the path does not exist inside the content root, or it tries to escape via "..", an absolute path, or a symlink resolving outside.'),
                    $rel
                ),
            ];
        }
        if (!is_file($target)) {
            return [
                'status'  => 'failed',
                'message' => sprintf(
                    gettext('Cannot download "%s": the path resolves to "%s", which is not a regular file. Expected: a regular file (not a directory or symlink to elsewhere). Use the list action to navigate directories.'),
                    $rel,
                    $target
                ),
            ];
        }
        $name = basename($target);
        $this->response->setHeader('Content-Type', 'application/octet-stream');
        $this->response->setHeader('Content-Length', (string)filesize($target));
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $this->response->setContent(file_get_contents($target));
        return null;
    }
}
