#!/bin/sh
#
# Fetch the two netboot.xyz iPXE bootstrap binaries into the content root
# so the firewall can serve PXE clients of either firmware type without
# the admin having to think about it. Called by the GUI's "Bootstrap
# netboot.xyz" button via configctl.
#
# Idempotent: re-running overwrites existing files (so this also serves as
# a "refresh from upstream" action when netboot.xyz publishes a new build).
#
# Resolves the content root from config.xml so a non-default root is
# respected.

set -eu

CONFIG="/conf/config.xml"
DEFAULT_ROOT="/var/netboot"

# Pull the content root from config.xml. If the netboot section isn't there
# yet (plugin not configured), fall back to the default.
CONTENT_ROOT=$(
    /usr/local/bin/xmllint --xpath 'string(/opnsense/OPNsense/netboot/general/content_root)' "${CONFIG}" 2>/dev/null || true
)
: "${CONTENT_ROOT:=${DEFAULT_ROOT}}"
if [ -z "${CONTENT_ROOT}" ]; then
    CONTENT_ROOT="${DEFAULT_ROOT}"
fi

# Make sure the root exists and is writable.
if [ ! -d "${CONTENT_ROOT}" ]; then
    /usr/bin/install -d -o _netboot -g _netboot -m 0755 "${CONTENT_ROOT}"
fi

UPSTREAM="https://boot.netboot.xyz/ipxe"
TMP=$(/usr/bin/mktemp -d -t netboot.XXXXXX)
trap 'rm -rf "${TMP}"' EXIT INT TERM

fetch_one()
{
    name="$1"
    url="${UPSTREAM}/${name}"
    echo "Fetching ${url} ..."
    # Capture fetch's stderr so we can surface its specific error (timeout,
    # 404, TLS failure, DNS, etc.) rather than just "failed to fetch".
    fetch_err=$(/usr/bin/fetch -o "${TMP}/${name}" "${url}" 2>&1) || {
        echo "ERROR: could not download ${url}" >&2
        echo "       fetch(1) said: ${fetch_err}" >&2
        echo "       Expected: an HTTPS connection to boot.netboot.xyz returning a 200 response." >&2
        echo "       Likely causes: (a) the firewall has no WAN connectivity right now," >&2
        echo "       (b) outbound HTTPS to boot.netboot.xyz is blocked by an egress rule," >&2
        echo "       (c) DNS for boot.netboot.xyz is not resolving, or (d) netboot.xyz is" >&2
        echo "       transiently down. Test from the OPNsense shell:" >&2
        echo "         fetch -o /dev/null https://boot.netboot.xyz/ipxe/${name}" >&2
        return 1
    }
    # Atomic move so we don't leave a half-written file in the served tree
    # if something interrupts us between download and finalize.
    /bin/mv -f "${TMP}/${name}" "${CONTENT_ROOT}/${name}"
    /usr/sbin/chown _netboot:_netboot "${CONTENT_ROOT}/${name}"
    /bin/chmod 0644 "${CONTENT_ROOT}/${name}"
    size=$(/usr/bin/stat -f %z "${CONTENT_ROOT}/${name}")
    echo "  -> ${CONTENT_ROOT}/${name} (${size} bytes)"
}

fetch_one netboot.xyz.kpxe
fetch_one netboot.xyz.efi

echo "Done. Both BIOS (.kpxe) and UEFI (.efi) bootstrap binaries are now in ${CONTENT_ROOT}."
