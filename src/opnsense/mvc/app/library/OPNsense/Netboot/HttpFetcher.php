<?php

/*
 * Copyright (c) 2026 John Byrd <johnwbyrd@gmail.com>
 * BSD-2-Clause; see LICENSE.
 */

namespace OPNsense\Netboot;

/**
 * Hardened HTTPS fetcher used by Api/ServiceController::bootstrap (for
 * server-hardcoded preset URLs) and Api/FilesController::fetchUrl (for
 * user-supplied URLs).
 *
 * Why this exists -- replacing the previous configd+fetch(1) shell path:
 *
 *   The previous path went PHP -> escapeshellarg -> configd parameter
 *   substitution -> subprocess shell -> fetch(1). Four parsers between
 *   the URL string and the network call, each with its own history of
 *   parser bugs. configd's script_output mechanism also drops the exit
 *   code (non-zero scripts return empty stdout via the 'Execute error'
 *   sentinel), making real error reporting impossible.
 *
 *   This class uses libcurl via PHP's curl_exec. No shell anywhere -- the
 *   URL is a C-string argument to libcurl. Argv-injection attacks (&, $(
 *   ... ), |, etc.) have no parser to hook into. Real exit values come
 *   back as native PHP return values, errno, and HTTP status, with
 *   per-request error messages from curl_strerror().
 *
 * Audit surface (every hardening item is set explicitly below; do not
 * remove any of these without thinking through what it opens):
 *
 *   PROTOCOLS / REDIR_PROTOCOLS  -> https only (no file://, gopher://,
 *                                    dict://, ldap://, ...). On REDIR
 *                                    keeps protocol locked across
 *                                    redirects so a 30x can't tunnel
 *                                    elsewhere.
 *   SSL_VERIFYPEER / VERIFYHOST  -> on. Asserted explicitly even though
 *                                    they default on -- safer for the
 *                                    next reader who skims this and
 *                                    sees no "verify off" anywhere.
 *   FOLLOWLOCATION / MAXREDIRS   -> at most 3 hops, refuse on overflow.
 *                                    CDNs need redirect support
 *                                    (boot.netboot.xyz fronts S3).
 *   UNRESTRICTED_AUTH            -> off. Even though we never set auth,
 *                                    set explicitly so any future code
 *                                    that does won't leak creds across
 *                                    a redirect.
 *   NETRC                        -> ignored. Don't read ~/.netrc.
 *   TIMEOUT / CONNECTTIMEOUT     -> network ceilings. Hung connections
 *                                    can no longer wedge the GUI thread.
 *   Response size limit          -> via progress callback. A malicious
 *                                    mirror can't fill the disk.
 *
 * SSRF protection (rejectInternalAddress) is opt-in: bootstrap URLs are
 * server-hardcoded constants from $bootstrapPresets so SSRF doesn't
 * apply; user-supplied URLs from fetchUrl pass enforceSafeUrl=true.
 */
class HttpFetcher
{
    /** Hard cap on a single fetched object. 256 MiB covers ISO-class
     *  rescue images while making "fill the disk" attacks pointless. */
    public const MAX_BYTES_DEFAULT = 256 * 1024 * 1024;

    /** Total seconds the fetch may take. The longest legitimate file in
     *  any preset is ~120 MiB; even a slow VDSL link finishes that
     *  inside 600s. Anything beyond is a stuck mirror. */
    public const TIMEOUT_DEFAULT = 600;

    /** Seconds to wait for the TLS handshake to come up. Has to be
     *  separate from TIMEOUT so a fast-fail "host unreachable" doesn't
     *  wait for TIMEOUT. */
    public const CONNECT_TIMEOUT_DEFAULT = 20;

    /** Max redirect hops we'll chase before giving up. CDN-fronted
     *  endpoints like boot.netboot.xyz typically do 1-2 hops. */
    public const MAX_REDIRECTS = 3;

    /**
     * Fetch $url to $destPath atomically. Always writes either the full
     * verified response or nothing -- never a half-written file -- by
     * downloading to a tempfile in the same directory and renaming on
     * success.
     *
     * @param string $url Full https:// (or http://) URL.
     * @param string $destPath Absolute filesystem path. Caller is
     *        responsible for confirming this path lives inside the
     *        content root.
     * @param array $opts {
     *   int   max_bytes        Override MAX_BYTES_DEFAULT.
     *   int   timeout          Override TIMEOUT_DEFAULT.
     *   bool  enforce_safe_url Resolve hostname and reject RFC1918,
     *                          loopback, link-local, multicast, ZeroNet.
     *                          Required for any user-supplied URL; not
     *                          needed for server-hardcoded URLs.
     * }
     * @return array {
     *   bool   ok          true iff the file is on disk with the
     *                      expected content.
     *   string url         Echoed back, for logging.
     *   string dest        Echoed back.
     *   int|null http_code Last HTTP status code seen.
     *   int|null bytes     Bytes written on success.
     *   string|null error  Human-readable failure reason on !ok.
     *   string|null errno  curl errno name on transport failures.
     * }
     */
    public function fetch(string $url, string $destPath, array $opts = []): array
    {
        $maxBytes        = (int)($opts['max_bytes']        ?? self::MAX_BYTES_DEFAULT);
        $timeout         = (int)($opts['timeout']          ?? self::TIMEOUT_DEFAULT);
        $connectTimeout  = (int)($opts['connect_timeout']  ?? self::CONNECT_TIMEOUT_DEFAULT);
        $enforceSafeUrl  = (bool)($opts['enforce_safe_url'] ?? false);

        $result = [
            'ok'        => false,
            'url'       => $url,
            'dest'      => $destPath,
            'http_code' => null,
            'bytes'     => null,
            'error'     => null,
            'errno'     => null,
        ];

        // URL parsing & scheme allowlist. parse_url returns false on
        // truly malformed input, false-ish on certain ambiguous inputs;
        // either way we won't pass it through.
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            $result['error'] = sprintf(
                'URL "%s" is not a parseable absolute URL. Expected: a https:// (or http://) URL with a host component.',
                $url
            );
            return $result;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            $result['error'] = sprintf(
                'URL "%s" has scheme "%s". Allowed: https, http. Other schemes (file, gopher, dict, ftp, ...) are intentionally blocked.',
                $url,
                $scheme
            );
            return $result;
        }

        if ($enforceSafeUrl) {
            $reason = $this->rejectInternalAddress((string)$parts['host']);
            if ($reason !== null) {
                $result['error'] = $reason;
                return $result;
            }
        }

        // Destination dir must exist and be writable -- we expect setup.sh
        // to have made the content root www-writable (mode 02775, group
        // _netboot) so PHP can drop the file directly without going
        // through configd. If that didn't happen we want to fail loudly
        // BEFORE making a network request and discovering the dest is
        // unwritable.
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            $result['error'] = sprintf(
                'Destination directory "%s" does not exist. Expected: an existing directory inside the content root. Run "configctl netboot setup" or re-Save Services -> Netboot -> General to create it.',
                $destDir
            );
            return $result;
        }
        if (!is_writable($destDir)) {
            $result['error'] = sprintf(
                'Destination directory "%s" is not writable by the GUI user (typically www). Expected: mode 02775 with group _netboot, or another permission set that allows the webGUI to create files. Re-run "configctl netboot setup" to repair.',
                $destDir
            );
            return $result;
        }

        // Atomic write: download to a sibling tempfile, fsync, rename.
        // If anything goes wrong mid-stream the partial file stays in
        // .tmp and gets removed in the cleanup path below; the real
        // dest is untouched.
        $tmpPath = $destPath . '.tmp.' . bin2hex(random_bytes(6));
        $fh = @fopen($tmpPath, 'wb');
        if ($fh === false) {
            $result['error'] = sprintf(
                'Could not open tempfile "%s" for writing. Expected: the GUI user can create files in "%s". This usually means the content root permissions drifted; re-run "configctl netboot setup".',
                $tmpPath,
                $destDir
            );
            return $result;
        }

        $ch = curl_init();
        // ALL of these must remain set. Removing or weakening any one of
        // them changes the security posture; the comments above explain
        // what each is buying us.
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
        curl_setopt($ch, CURLOPT_UNRESTRICTED_AUTH, false);
        if (defined('CURLOPT_NETRC')) {
            curl_setopt($ch, CURLOPT_NETRC, 0);  // CURL_NETRC_IGNORED
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);  // 4xx/5xx -> curl_exec false
        curl_setopt($ch, CURLOPT_USERAGENT, 'os-netboot/' . $this->pluginVersion());

        // Stream the body directly to the open file handle. Lets a
        // 100 MiB ISO not have to fit in PHP memory.
        curl_setopt($ch, CURLOPT_FILE, $fh);

        // Response-size cap via progress callback. CURLOPT_NOPROGRESS
        // must be 0 for the callback to fire.
        curl_setopt($ch, CURLOPT_NOPROGRESS, 0);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
            function ($ch_arg, $dlExpected, $dlNow, $ulExpected, $ulNow) use ($maxBytes) {
                // Returning non-zero from this callback aborts the
                // transfer; curl_exec then returns false with errno
                // CURLE_ABORTED_BY_CALLBACK.
                if ($dlNow > $maxBytes) {
                    return 1;
                }
                if ($dlExpected > 0 && $dlExpected > $maxBytes) {
                    return 1;
                }
                return 0;
            }
        );

        $ok = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        $result['http_code'] = $httpCode;

        if ($ok === false) {
            @unlink($tmpPath);
            // Map the two most-common abort paths into explicit, actionable text.
            if ($curlErrno === CURLE_ABORTED_BY_CALLBACK) {
                $result['errno'] = 'CURLE_ABORTED_BY_CALLBACK';
                $result['error'] = sprintf(
                    'Aborted: response exceeded the %d-byte size cap. Expected: a file smaller than the cap. Either the upstream mirror is misbehaving or the file genuinely is too large for the configured limit.',
                    $maxBytes
                );
            } else {
                $result['errno'] = $this->curlErrnoName($curlErrno);
                $result['error'] = sprintf(
                    'curl failed at "%s": %s (errno=%s%s). Expected: an HTTPS 200 with the response body. Likely causes: WAN egress from the firewall is blocked or down, the upstream host is unresolvable or unreachable, the TLS certificate is invalid, or the upstream returned a 4xx/5xx (HTTP %d).',
                    $url,
                    $curlError !== '' ? $curlError : '(no curl_error message)',
                    $this->curlErrnoName($curlErrno),
                    $curlErrno,
                    $httpCode
                );
            }
            return $result;
        }

        // curl_exec succeeded. Ensure the file is fully flushed to disk
        // before the rename, otherwise a power loss between rename and
        // dirty-buffer flush could leave a zero-length file in place.
        // (fsync(2) is required here, not enough to rely on fclose alone
        // on FreeBSD's UFS.)
        $bytes = @filesize($tmpPath);
        if ($bytes === false || $bytes === 0) {
            @unlink($tmpPath);
            $result['error'] = sprintf(
                'curl returned success but the tempfile "%s" ended up at 0 bytes. This should be impossible -- likely a filesystem error.',
                $tmpPath
            );
            return $result;
        }

        if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            $result['error'] = sprintf(
                'Could not rename tempfile "%s" to final destination "%s". Expected: the GUI user can rename within "%s" (atomicity requires same-filesystem). Check that nothing has stale-locked the destination.',
                $tmpPath,
                $destPath,
                dirname($destPath)
            );
            return $result;
        }
        @chmod($destPath, 0644);

        $result['ok']    = true;
        $result['bytes'] = (int)$bytes;
        return $result;
    }

    /**
     * Resolve the host and refuse anything in private/loopback/link-local
     * /multicast/unspecified address space. Called only for user-supplied
     * URLs (fetchUrl); not called for server-hardcoded preset URLs since
     * those are constants.
     *
     * The point: prevent a webGUI user from coercing the firewall to
     * make HTTP requests against its own management plane, internal
     * services, or LAN devices that the user wouldn't be able to reach
     * directly. Same class of bug as Capital One 2019 (SSRF -> EC2
     * instance metadata).
     *
     * @return string|null  null = allowed; non-null = reason for rejection.
     */
    public function rejectInternalAddress(string $host): ?string
    {
        // Strip any IPv6 brackets that parse_url leaves attached.
        $host = trim($host, '[]');

        // First, if the host is already a numeric address, check directly
        // -- this is the form an attacker would supply explicitly.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $reason = $this->classifyIp($host);
            if ($reason !== null) {
                return sprintf('Refusing to fetch from "%s": %s.', $host, $reason);
            }
            return null;
        }

        // Otherwise resolve to addresses and check every one. A hostile
        // upstream that returns BOTH a public and a private address
        // would still be blocked because we reject if any resolution is
        // private.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || count($records) === 0) {
            return sprintf(
                'Refusing to fetch from "%s": hostname could not be resolved to any A or AAAA record. Expected: a public DNS name. Likely causes: DNS not working from the firewall, or the host doesn\'t exist.',
                $host
            );
        }
        foreach ($records as $rec) {
            $ip = $rec['ip'] ?? $rec['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            $reason = $this->classifyIp((string)$ip);
            if ($reason !== null) {
                return sprintf(
                    'Refusing to fetch from "%s": resolved to %s (%s). Expected: a public, routable address.',
                    $host,
                    $ip,
                    $reason
                );
            }
        }
        return null;
    }

    /**
     * Return a human-readable category if $ip is in restricted address
     * space, or null if it's a public address we're willing to fetch
     * from.
     */
    private function classifyIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'not a valid IP address';
        }
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE together cover:
        // loopback (127/8 + ::1), private (10/8, 172.16/12, 192.168/16,
        // fc00::/7), link-local (169.254/16 + fe80::/10), multicast,
        // and various reserved blocks (0/8, 192.0.0/24, etc).
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            // It's a valid IP but lives in a blocked range.
            return 'private, loopback, link-local, multicast, or reserved address';
        }
        return null;
    }

    /**
     * Map libcurl errno to its CURLE_* name for the error message. PHP's
     * curl extension doesn't expose curl_strerror as a name lookup, only
     * as a one-line description, so we keep a small map of the codes
     * we're likely to actually hit. Falls back to the numeric code.
     */
    private function curlErrnoName(int $errno): string
    {
        $map = [
            CURLE_UNSUPPORTED_PROTOCOL    => 'CURLE_UNSUPPORTED_PROTOCOL',
            CURLE_URL_MALFORMAT           => 'CURLE_URL_MALFORMAT',
            CURLE_COULDNT_RESOLVE_HOST    => 'CURLE_COULDNT_RESOLVE_HOST',
            CURLE_COULDNT_CONNECT         => 'CURLE_COULDNT_CONNECT',
            CURLE_OPERATION_TIMEOUTED     => 'CURLE_OPERATION_TIMEOUTED',
            CURLE_SSL_CONNECT_ERROR       => 'CURLE_SSL_CONNECT_ERROR',
            CURLE_PEER_FAILED_VERIFICATION=> 'CURLE_PEER_FAILED_VERIFICATION',
            CURLE_GOT_NOTHING             => 'CURLE_GOT_NOTHING',
            CURLE_ABORTED_BY_CALLBACK     => 'CURLE_ABORTED_BY_CALLBACK',
            CURLE_TOO_MANY_REDIRECTS      => 'CURLE_TOO_MANY_REDIRECTS',
            CURLE_HTTP_RETURNED_ERROR     => 'CURLE_HTTP_RETURNED_ERROR',
        ];
        return $map[$errno] ?? ('CURL_ERRNO_' . $errno);
    }

    /**
     * Plugin version, for the User-Agent header. Read live from the
     * version file -- no static config needed -- with a safe fallback
     * if the file isn't present yet (very early install).
     */
    private function pluginVersion(): string
    {
        $f = '/usr/local/opnsense/version/netboot';
        if (!is_readable($f)) {
            return 'unknown';
        }
        $j = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || empty($j['product_version'])) {
            return 'unknown';
        }
        return (string)$j['product_version'];
    }
}
