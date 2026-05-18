#!/bin/sh
#
# Fetch a named iPXE bootstrap preset (BIOS .kpxe + UEFI .efi) into the
# content root. Invoked by the GUI via `configctl netboot bootstrap <preset>`.
#
# Why both files? PXE-booting clients send DHCP option 93 (Client System
# Architecture). The DHCP helper hands them netboot.xyz.kpxe for arch 0
# (legacy BIOS) and netboot.xyz.efi for arch 7/9 (UEFI x86_64). Fetching
# both with one button means "this firewall will netboot anything on the
# LAN, regardless of firmware type." That's the property we want.
#
# Presets:
#   netboot_xyz   netboot.xyz iPXE (default). Chains to the public
#                 boot.netboot.xyz menu at boot time -- the de-facto
#                 standard PXE menu, covers OS installers, rescue
#                 environments, memtest, etc.
#   ipxe          Stock iPXE from ipxe.org. No embedded menu -- drops
#                 to the iPXE shell. For admins who'll host their own
#                 menu.ipxe in the content root and chain to it.
#
# Idempotent: re-running overwrites in place. Use that to refresh after
# an upstream rebuild.

set -eu

PRESET="${1:-netboot_xyz}"

case "${PRESET}" in
    netboot_xyz)
        UPSTREAM_BASE="https://boot.netboot.xyz/ipxe"
        BIOS_REMOTE="netboot.xyz.kpxe"
        UEFI_REMOTE="netboot.xyz.efi"
        BIOS_LOCAL="netboot.xyz.kpxe"
        UEFI_LOCAL="netboot.xyz.efi"
        DESC="netboot.xyz iPXE (chains to boot.netboot.xyz menu)"
        ;;
    ipxe)
        UPSTREAM_BASE="https://boot.ipxe.org"
        BIOS_REMOTE="undionly.kpxe"
        UEFI_REMOTE="ipxe.efi"
        # Save under generic names so DHCP boot entries / docs can refer to
        # them without leaking the upstream filename.
        BIOS_LOCAL="ipxe.kpxe"
        UEFI_LOCAL="ipxe.efi"
        DESC="Stock iPXE (boot.ipxe.org). Drops to iPXE shell at boot -- pair with your own menu.ipxe."
        ;;
    *)
        echo "ERROR: unknown bootstrap preset '${PRESET}'." >&2
        echo "       Expected one of: netboot_xyz, ipxe." >&2
        echo "       To add a new preset, edit /usr/local/opnsense/scripts/netboot/bootstrap_netboot_xyz.sh" >&2
        echo "       and the matching case in the GUI Quick start menu." >&2
        exit 64   # EX_USAGE
        ;;
esac

CONFIG="/conf/config.xml"
DEFAULT_ROOT="/var/netboot"

CONTENT_ROOT=$(
    /usr/local/bin/xmllint --xpath 'string(/opnsense/OPNsense/netboot/general/content_root)' "${CONFIG}" 2>/dev/null || true
)
if [ -z "${CONTENT_ROOT}" ]; then
    CONTENT_ROOT="${DEFAULT_ROOT}"
fi

# Belt-and-suspenders: ensure the runtime user and directories exist. This
# is normally done by setup.sh at install time (and again on reconfigure),
# but if the admin has somehow wound up with content_root missing we'd
# rather self-heal than fail with a cryptic chown error.
if ! /usr/sbin/pw user show _netboot >/dev/null 2>&1 || [ ! -d "${CONTENT_ROOT}" ]; then
    /usr/local/opnsense/scripts/netboot/setup.sh >/dev/null
fi

TMP=$(/usr/bin/mktemp -d -t netboot.XXXXXX)
trap 'rm -rf "${TMP}"' EXIT INT TERM

fetch_one()
{
    remote="$1"
    local_name="$2"
    url="${UPSTREAM_BASE}/${remote}"
    echo "Fetching ${url} ..."
    # Capture fetch's stderr so we can surface its specific error (timeout,
    # 404, TLS failure, DNS, etc.) rather than just "failed to fetch".
    fetch_err=$(/usr/bin/fetch -o "${TMP}/${local_name}" "${url}" 2>&1) || {
        echo "ERROR: could not download ${url}" >&2
        echo "       fetch(1) said: ${fetch_err}" >&2
        echo "       Expected: an HTTPS connection to ${UPSTREAM_BASE} returning a 200 response." >&2
        echo "       Likely causes: (a) the firewall has no WAN connectivity right now," >&2
        echo "       (b) outbound HTTPS to the upstream host is blocked by an egress rule," >&2
        echo "       (c) DNS for the upstream host is not resolving, or (d) the upstream is" >&2
        echo "       transiently down. Test from the OPNsense shell:" >&2
        echo "         fetch -o /dev/null ${url}" >&2
        return 1
    }
    # Atomic move so we don't leave a half-written file in the served tree
    # if something interrupts us between download and finalize.
    /bin/mv -f "${TMP}/${local_name}" "${CONTENT_ROOT}/${local_name}"
    /usr/sbin/chown _netboot:_netboot "${CONTENT_ROOT}/${local_name}"
    /bin/chmod 0644 "${CONTENT_ROOT}/${local_name}"
    size=$(/usr/bin/stat -f %z "${CONTENT_ROOT}/${local_name}")
    echo "  -> ${CONTENT_ROOT}/${local_name} (${size} bytes)"
}

echo "Preset: ${PRESET} -- ${DESC}"
fetch_one "${BIOS_REMOTE}" "${BIOS_LOCAL}"
fetch_one "${UEFI_REMOTE}" "${UEFI_LOCAL}"

echo "Done. Wrote ${BIOS_LOCAL} (BIOS) and ${UEFI_LOCAL} (UEFI) into ${CONTENT_ROOT}."
