#!/bin/sh
#
# Ensure os-netboot runtime preconditions exist:
#   - the _netboot system user (no shell, no password)
#   - content root and support directories with correct ownership
#
# Idempotent. Called by configd via 'configctl netboot setup' from the
# reconfigure controller, and also at install time from the plugin's
# rc-bootup hook (netboot_setup_runtime() in plugins.inc.d/netboot.inc
# does the same thing in PHP for the boot case).
#
# Honors the configured content_root from config.xml so a non-default
# root is set up correctly. Falls back to /var/netboot.

set -eu

CONFIG="/conf/config.xml"
DEFAULT_ROOT="/var/netboot"

CONTENT_ROOT=$(
    /usr/local/bin/xmllint --xpath 'string(/opnsense/OPNsense/netboot/general/content_root)' "${CONFIG}" 2>/dev/null || true
)
if [ -z "${CONTENT_ROOT}" ]; then
    CONTENT_ROOT="${DEFAULT_ROOT}"
fi

# Create the _netboot group + user if missing. UID/GID 387 picked from the
# IANA / FreeBSD reserved range; if it's taken we fall through to letting
# pw pick.
if ! /usr/sbin/pw group show _netboot >/dev/null 2>&1; then
    if ! /usr/sbin/pw groupadd _netboot -g 387 2>/dev/null; then
        /usr/sbin/pw groupadd _netboot
    fi
fi
if ! /usr/sbin/pw user show _netboot >/dev/null 2>&1; then
    if ! /usr/sbin/pw useradd _netboot -u 387 -g _netboot \
            -d "${CONTENT_ROOT}" -s /usr/sbin/nologin \
            -c "os-netboot service user" 2>/dev/null; then
        /usr/sbin/pw useradd _netboot -g _netboot \
            -d "${CONTENT_ROOT}" -s /usr/sbin/nologin \
            -c "os-netboot service user"
    fi
fi

# Directories with correct ownership/mode.
#  - Content root: _netboot:_netboot 0755. SFTP chroot target.
#  - /var/db/netboot: root:wheel 0700. Houses SFTP host keys + authorized_keys.
#    Must be root-owned and 0700 so sshd's StrictModes accepts it.
#  - /var/etc/netboot: root:wheel 0755. Where the template engine writes
#    rendered daemon configs.
#  - /var/log/netboot: _netboot:_netboot 0755. lighttpd error log destination.
set_dir() {
    dir="$1"
    owner="$2"
    group="$3"
    mode="$4"
    if [ ! -d "${dir}" ]; then
        /usr/bin/install -d -o "${owner}" -g "${group}" -m "${mode}" "${dir}"
    else
        /usr/sbin/chown "${owner}:${group}" "${dir}"
        /bin/chmod "${mode}" "${dir}"
    fi
}

set_dir "${CONTENT_ROOT}"    _netboot _netboot 0755
set_dir /var/db/netboot      root     wheel    0700
set_dir /var/etc/netboot     root     wheel    0755
set_dir /var/log/netboot     _netboot _netboot 0755

echo "os-netboot runtime setup complete. Content root: ${CONTENT_ROOT}"
