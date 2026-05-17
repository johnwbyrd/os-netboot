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
    echo "Fetching ${UPSTREAM}/${name}..."
    if ! /usr/bin/fetch -q -o "${TMP}/${name}" "${UPSTREAM}/${name}"; then
        echo "ERROR: failed to fetch ${UPSTREAM}/${name}" >&2
        return 1
    fi
    # Atomic move so we don't leave a half-written file in the served tree
    # if something interrupts us.
    /bin/mv -f "${TMP}/${name}" "${CONTENT_ROOT}/${name}"
    /usr/sbin/chown _netboot:_netboot "${CONTENT_ROOT}/${name}"
    /bin/chmod 0644 "${CONTENT_ROOT}/${name}"
    size=$(/usr/bin/stat -f %z "${CONTENT_ROOT}/${name}")
    echo "  -> ${CONTENT_ROOT}/${name} (${size} bytes)"
}

fetch_one netboot.xyz.kpxe
fetch_one netboot.xyz.efi

echo "Done. Both BIOS (.kpxe) and UEFI (.efi) bootstrap binaries are now in ${CONTENT_ROOT}."
