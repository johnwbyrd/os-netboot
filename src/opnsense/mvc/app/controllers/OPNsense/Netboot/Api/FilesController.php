<?php

namespace OPNsense\Netboot\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
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

    public function listAction()
    {
        $rel = (string)$this->request->get('path', 'string', '');
        $dir = $this->resolveWithin($rel, true);
        if ($dir === '' || !is_dir($dir)) {
            return ['status' => 'failed', 'message' => gettext('Path not found.')];
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
            return ['status' => 'failed', 'message' => gettext('Use POST.')];
        }
        $relDir = (string)$this->request->getPost('path', 'string', '');
        $destDir = $this->resolveWithin($relDir, true);
        if ($destDir === '' || !is_dir($destDir)) {
            return ['status' => 'failed', 'message' => gettext('Destination directory not found.')];
        }

        $results = [];
        foreach ($this->request->getUploadedFiles() as $upload) {
            $name = basename($upload->getName());
            if (!PathResolver::isSafeName($name)) {
                $results[] = ['name' => $upload->getName(), 'status' => 'rejected'];
                continue;
            }
            $target = $destDir . '/' . $name;
            // Atomic move: upload to tempfile beside target, fsync, rename.
            $tmp = $target . '.upload.' . bin2hex(random_bytes(4));
            if (!$upload->moveTo($tmp)) {
                $results[] = ['name' => $name, 'status' => 'failed'];
                continue;
            }
            @chmod($tmp, 0644);
            @chown($tmp, '_netboot');
            @chgrp($tmp, '_netboot');
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                $results[] = ['name' => $name, 'status' => 'failed'];
                continue;
            }
            $results[] = ['name' => $name, 'status' => 'ok', 'size' => filesize($target)];
        }

        return ['status' => 'ok', 'results' => $results];
    }

    public function deleteAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('Use POST.')];
        }
        $rel = (string)$this->request->getPost('path', 'string', '');
        if ($rel === '') {
            return ['status' => 'failed', 'message' => gettext('Empty path.')];
        }
        $target = $this->resolveWithin($rel, true);
        if ($target === '' || $target === $this->contentRoot()) {
            return ['status' => 'failed', 'message' => gettext('Refusing to delete that target.')];
        }
        if (is_dir($target)) {
            // Only allow rmdir on empty directories from this endpoint.
            // Recursive delete intentionally not exposed in v0.1.
            if (count(scandir($target)) !== 2) {
                return ['status' => 'failed', 'message' => gettext('Directory not empty.')];
            }
            if (!@rmdir($target)) {
                return ['status' => 'failed', 'message' => gettext('Could not remove directory.')];
            }
        } else {
            if (!@unlink($target)) {
                return ['status' => 'failed', 'message' => gettext('Could not remove file.')];
            }
        }
        return ['status' => 'ok'];
    }

    public function mkdirAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('Use POST.')];
        }
        $rel = (string)$this->request->getPost('path', 'string', '');
        if ($rel === '' || PathResolver::containsTraversal(ltrim($rel, '/'))) {
            return ['status' => 'failed', 'message' => gettext('Invalid path.')];
        }
        $target = $this->resolveWithin($rel, false);
        if ($target === '') {
            return ['status' => 'failed', 'message' => gettext('Path escapes content root.')];
        }
        if (file_exists($target)) {
            return ['status' => 'failed', 'message' => gettext('Already exists.')];
        }
        if (!@mkdir($target, 0755, true)) {
            return ['status' => 'failed', 'message' => gettext('Could not create directory.')];
        }
        @chown($target, '_netboot');
        @chgrp($target, '_netboot');
        return ['status' => 'ok'];
    }

    public function fetchUrlAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('Use POST.')];
        }
        $url  = (string)$this->request->getPost('url', 'string', '');
        $name = (string)$this->request->getPost('name', 'string', '');
        $rel  = (string)$this->request->getPost('path', 'string', '');

        if ($url === '' || !preg_match('#^https?://#', $url)) {
            return ['status' => 'failed', 'message' => gettext('Only http(s) URLs accepted.')];
        }
        if ($name === '') {
            $name = basename(parse_url($url, PHP_URL_PATH) ?? 'download');
        }
        if (!PathResolver::isSafeName($name)) {
            return ['status' => 'failed', 'message' => gettext('Invalid filename.')];
        }
        $destDir = $this->resolveWithin($rel, true);
        if ($destDir === '' || !is_dir($destDir)) {
            return ['status' => 'failed', 'message' => gettext('Destination directory not found.')];
        }

        $backend = new Backend();
        $relTarget = ltrim(($rel === '' ? '' : $rel . '/') . $name, '/');
        $output = $backend->configdpRun('netboot fetch_url', [$url, $relTarget]);
        return ['status' => 'ok', 'output' => $output];
    }

    public function downloadAction()
    {
        $rel = (string)$this->request->get('path', 'string', '');
        $target = $this->resolveWithin($rel, true);
        if ($target === '' || !is_file($target)) {
            return ['status' => 'failed', 'message' => gettext('File not found.')];
        }
        $name = basename($target);
        $this->response->setHeader('Content-Type', 'application/octet-stream');
        $this->response->setHeader('Content-Length', (string)filesize($target));
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $this->response->setContent(file_get_contents($target));
        return null;
    }
}
