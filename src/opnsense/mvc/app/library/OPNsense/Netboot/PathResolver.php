<?php

namespace OPNsense\Netboot;

/**
 * Static helpers for resolving and validating filesystem paths supplied
 * by untrusted clients against a fixed content root.
 *
 * Lives in mvc/app/library/ rather than the controller so it has zero
 * Phalcon dependencies and can be unit-tested with plain PHPUnit (no
 * full OPNsense bootstrap required).
 *
 * Security model: every method that accepts a client-supplied path
 * returns '' (empty string) on rejection. Callers MUST treat empty
 * return as "do nothing further with this request." Rejection causes:
 *   - root not configured or invalid
 *   - any '..' in the input
 *   - absolute paths
 *   - resolved path escapes the root (defeats symlink trickery)
 */
class PathResolver
{
    /**
     * Resolve a user-supplied relative path within a fixed root.
     *
     * @param string $absRoot   Absolute path to the content root. Must
     *                          exist; will be realpath()ed internally.
     * @param string $relPath   Untrusted relative path from the client.
     * @param bool   $mustExist If true, the candidate path must exist
     *                          (for read/list/delete). If false, only
     *                          the parent must exist (for upload/mkdir).
     * @return string           Absolute, canonical path within the root,
     *                          or '' if rejected.
     */
    public static function within(string $absRoot, string $relPath, bool $mustExist = true): string
    {
        $root = realpath($absRoot);
        if ($root === false) {
            return '';
        }

        // Strip leading slashes so the user can't pass an absolute path
        // and have it concatenate weirdly.
        $relPath = ltrim($relPath, '/');

        // Cheap traversal reject -- any '..' segment is invalid. This
        // catches obvious attacks before we touch the filesystem.
        if ($relPath !== '' && self::containsTraversal($relPath)) {
            return '';
        }

        $candidate = ($relPath === '') ? $root : $root . '/' . $relPath;

        if ($mustExist) {
            $real = realpath($candidate);
            if ($real === false) {
                return '';
            }
        } else {
            // For destinations that don't exist yet (upload, mkdir),
            // canonicalize the parent and append the (validated) leaf.
            $parent = realpath(dirname($candidate));
            if ($parent === false) {
                return '';
            }
            $leaf = basename($candidate);
            if ($leaf === '' || $leaf === '.' || $leaf === '..') {
                return '';
            }
            $real = $parent . '/' . $leaf;
        }

        // Containment check: must be exactly the root or a descendant.
        // String prefix check on root + '/' defeats e.g. /var/netbootevil
        // matching /var/netboot.
        if ($real === $root) {
            return $real;
        }
        if (strpos($real, $root . '/') === 0) {
            return $real;
        }

        return '';
    }

    /**
     * Reject paths containing '..' as a whole segment. We do NOT reject
     * filenames that merely contain '..' as a substring (e.g. 'foo..bar')
     * because that's a legitimate filename.
     */
    public static function containsTraversal(string $relPath): bool
    {
        foreach (explode('/', $relPath) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate a user-supplied filename. Returns true if it's safe to
     * use as a leaf component (no slashes, no control chars, no leading
     * dot, not empty, not '.' or '..').
     */
    public static function isSafeName(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }
        if ($name[0] === '.') {
            return false;
        }
        // Reject path separators, backslashes, and control bytes.
        if (preg_match('/[\/\\\\\x00-\x1f]/', $name)) {
            return false;
        }
        return true;
    }
}
