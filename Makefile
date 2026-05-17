PLUGIN_NAME=		netboot
PLUGIN_VERSION=		0.1.0
PLUGIN_COMMENT=		Netboot (PXE/iPXE) infrastructure: TFTP + HTTP + SFTP ingress + GUI file manager
PLUGIN_DEPENDS=		tftp-hpa
PLUGIN_MAINTAINER=	johnwbyrd@gmail.com
PLUGIN_WWW=		https://github.com/johnwbyrd/os-netboot

# Force the empty suffix instead of letting Mk/plugins.mk default to "-devel".
# OPNsense's Firmware Plugins UI (Core/Api/FirmwareController.php, around
# line 873) hides any plugin whose name ends in '-devel' unless the OPNsense
# install is set to Type: Development -- the common "Type: Community" install
# never sees -devel plugins in the list, even with "Show community plugins"
# enabled. So the suffix is a visibility kill-switch, not just a UX label.
# We override to ship as plain os-netboot.
PLUGIN_SUFFIX=

# This file is meant to be built from within a checkout of opnsense/plugins,
# at ftp/netboot/Makefile. The CI workflow (.github/workflows/build-release.yml)
# clones opnsense/plugins and copies this directory into ftp/netboot/ before
# invoking `make package`. Local builds follow the same procedure -- see
# README.md "Build from source".
.include "../../Mk/plugins.mk"
