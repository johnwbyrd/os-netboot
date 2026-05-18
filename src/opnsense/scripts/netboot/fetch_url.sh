#!/bin/sh
#
# Server-side fetch of a URL into the netboot content root.
#
# Invoked via configctl from Api/FilesController.php (fetchUrlAction):
#   configctl netboot fetch_url '<URL>' '<REL_TARGET_PATH>'
#
# Both arguments come from the GUI and are treated as untrusted. We:
#   - require http(s) URL only
#   - require REL_TARGET_PATH to be a non-empty, non-traversal-y relative path
#   - resolve against the configured content root from config.xml
#   - verify the resolved destination stays inside the content root
#   - download to a tempfile in the same directory, then atomic-rename
#
# A separate bootstrap.sh handles the curated one-click "fetch both BIOS
# and UEFI iPXE binaries from preset X" case (netboot.xyz, stock iPXE,
# etc.) so that flow stays simple and doesn't have to go through this
# generic path.

set -eu

URL="${1:?usage: $0 <url> <relative-target-path>}"
REL_TARGET="${2:?usage: $0 <url> <relative-target-path>}"

case "${URL}" in
    http://*|https://*) ;;
    *)  echo "ERROR: URL \"${URL}\" has an unsupported scheme." >&2
        echo "       Expected: a URL beginning with http:// or https://. Other schemes" >&2
        echo "       (ftp, file, scp, ...) are intentionally not supported -- if you need" >&2
        echo "       them, mirror the file to an HTTP server first." >&2
        exit 2 ;;
esac

case "${REL_TARGET}" in
    "") echo "ERROR: target path is empty." >&2
        echo "       Expected: a non-empty relative path under the content root, e.g." >&2
        echo "       'netboot.xyz.efi' or 'images/clonezilla.iso'." >&2
        exit 2 ;;
    /*) echo "ERROR: target path \"${REL_TARGET}\" is absolute." >&2
        echo "       Expected: a relative path (no leading slash). The script always" >&2
        echo "       resolves under the configured content root." >&2
        exit 2 ;;
    *..*) echo "ERROR: target path \"${REL_TARGET}\" contains '..'." >&2
        echo "       Expected: a relative path without traversal components." >&2
        exit 2 ;;
esac

CONFIG="/conf/config.xml"
DEFAULT_ROOT="/var/netboot"
CONTENT_ROOT=$(
    /usr/local/bin/xmllint --xpath 'string(/opnsense/OPNsense/netboot/general/content_root)' "${CONFIG}" 2>/dev/null || true
)
if [ -z "${CONTENT_ROOT}" ]; then
    CONTENT_ROOT="${DEFAULT_ROOT}"
fi

# Canonicalize the destination and verify it stays under the content root.
DEST="${CONTENT_ROOT}/${REL_TARGET}"
DEST_DIR=$(dirname "${DEST}")
DEST_NAME=$(basename "${DEST}")
REAL_DEST_DIR=$(/usr/bin/realpath "${DEST_DIR}" 2>/dev/null || true)
REAL_ROOT=$(/usr/bin/realpath "${CONTENT_ROOT}" 2>/dev/null || true)
if [ -z "${REAL_DEST_DIR}" ] || [ -z "${REAL_ROOT}" ]; then
    echo "ERROR: could not resolve destination directory." >&2
    echo "       Target path:        ${REL_TARGET}" >&2
    echo "       Computed parent:    ${DEST_DIR}" >&2
    echo "       Configured root:    ${CONTENT_ROOT}" >&2
    echo "       Expected: both the configured content root and the destination's" >&2
    echo "       parent directory to exist on disk. Re-save Netboot settings to run" >&2
    echo "       'configctl netboot setup' (which creates the root), or call mkdir" >&2
    echo "       on the Files page to create the parent." >&2
    exit 2
fi
case "${REAL_DEST_DIR}/" in
    "${REAL_ROOT}/"*|"${REAL_ROOT}/") ;;
    *)  echo "ERROR: resolved destination \"${REAL_DEST_DIR}\" is outside the content root \"${REAL_ROOT}\"." >&2
        echo "       Expected: a relative path whose canonical resolution stays under the root." >&2
        echo "       This usually means a symlink at one of the path components points" >&2
        echo "       outside the root; relocate or remove the symlink." >&2
        exit 2 ;;
esac

# Sanitize the filename.
case "${DEST_NAME}" in
    "") echo "ERROR: empty filename in target path \"${REL_TARGET}\"." >&2
        echo "       Expected: a non-empty filename as the last component of the path." >&2
        exit 2 ;;
    .*) echo "ERROR: filename \"${DEST_NAME}\" starts with a dot." >&2
        echo "       Expected: a name that does not start with a dot. Dotfiles are" >&2
        echo "       not served by the file manager and would never be useful as PXE" >&2
        echo "       boot content; if you have a legitimate need, rename without the dot." >&2
        exit 2 ;;
esac

TMP=$(/usr/bin/mktemp -p "${REAL_DEST_DIR}" ".fetch.XXXXXX")
trap 'rm -f "${TMP}"' EXIT INT TERM

echo "Fetching ${URL} -> ${REAL_DEST_DIR}/${DEST_NAME} ..."
# Capture fetch's stderr to surface its specific failure (HTTP code, timeout,
# DNS, TLS handshake, etc.) rather than just "fetch failed".
fetch_err=$(/usr/bin/fetch -o "${TMP}" "${URL}" 2>&1) || {
    echo "ERROR: could not download ${URL}." >&2
    echo "       fetch(1) said: ${fetch_err}" >&2
    echo "       Expected: an HTTP(S) connection returning a 200 with a non-empty body." >&2
    echo "       Likely causes: (a) no WAN connectivity from the firewall right now," >&2
    echo "       (b) outbound HTTPS is blocked, (c) the URL host's DNS does not" >&2
    echo "       resolve, or (d) the target URL itself is a 404 or 5xx. Verify from" >&2
    echo "       the OPNsense shell with:  fetch -o /dev/null ${URL}" >&2
    exit 1
}

/bin/mv -f "${TMP}" "${REAL_DEST_DIR}/${DEST_NAME}"
/usr/sbin/chown _netboot:_netboot "${REAL_DEST_DIR}/${DEST_NAME}"
/bin/chmod 0644 "${REAL_DEST_DIR}/${DEST_NAME}"

size=$(/usr/bin/stat -f %z "${REAL_DEST_DIR}/${DEST_NAME}")
echo "Done. ${REAL_DEST_DIR}/${DEST_NAME} (${size} bytes)"
