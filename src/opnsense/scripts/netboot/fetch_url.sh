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
# A separate bootstrap_netboot_xyz.sh handles the "fetch both netboot.xyz
# binaries" one-click case so that flow stays simple and doesn't have to
# go through this generic path.

set -eu

URL="${1:?usage: $0 <url> <relative-target-path>}"
REL_TARGET="${2:?usage: $0 <url> <relative-target-path>}"

case "${URL}" in
    http://*|https://*) ;;
    *) echo "ERROR: only http:// and https:// URLs accepted" >&2 ; exit 2 ;;
esac

case "${REL_TARGET}" in
    /*|*..*|"") echo "ERROR: relative path required, no traversal" >&2 ; exit 2 ;;
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
    echo "ERROR: could not resolve target directory" >&2
    exit 2
fi
case "${REAL_DEST_DIR}/" in
    "${REAL_ROOT}/"*|"${REAL_ROOT}/") ;;
    *) echo "ERROR: destination escapes content root" >&2 ; exit 2 ;;
esac

# Sanitize the filename.
case "${DEST_NAME}" in
    "" | .*) echo "ERROR: invalid filename" >&2 ; exit 2 ;;
esac

TMP=$(/usr/bin/mktemp -p "${REAL_DEST_DIR}" ".fetch.XXXXXX")
trap 'rm -f "${TMP}"' EXIT INT TERM

echo "Fetching ${URL} -> ${REAL_DEST_DIR}/${DEST_NAME} ..."
if ! /usr/bin/fetch -q -o "${TMP}" "${URL}"; then
    echo "ERROR: fetch failed" >&2
    exit 1
fi

/bin/mv -f "${TMP}" "${REAL_DEST_DIR}/${DEST_NAME}"
/usr/sbin/chown _netboot:_netboot "${REAL_DEST_DIR}/${DEST_NAME}"
/bin/chmod 0644 "${REAL_DEST_DIR}/${DEST_NAME}"

size=$(/usr/bin/stat -f %z "${REAL_DEST_DIR}/${DEST_NAME}")
echo "Done. ${REAL_DEST_DIR}/${DEST_NAME} (${size} bytes)"
