PLUGIN_NAME=		netboot
PLUGIN_VERSION=		0.1.0
PLUGIN_COMMENT=		Netboot (PXE/iPXE) infrastructure: TFTP + HTTP + SFTP ingress + GUI file manager
PLUGIN_DEPENDS=		tftp-hpa
PLUGIN_MAINTAINER=	johnwbyrd@gmail.com
PLUGIN_WWW=		https://github.com/johnwbyrd/os-netboot

# Build as a RELEASE-tier plugin, not the master-branch devel default.
#
# opnsense/plugins ships a devel.mk at the repo root that sets PLUGIN_DEVEL=yes
# whenever you build from master. Our CI clones opnsense/plugins@master, so by
# default our build inherits PLUGIN_DEVEL=yes. The conflict logic in
# Mk/plugins.mk then branches as:
#
#   .if "${PLUGIN_DEVEL}" != ""           # the devel branch
#       PLUGIN_CONFLICTS += ${PLUGIN_NAME}            # bare name; conflicts with
#       PLUGIN_PKGSUFFIX  = ${PLUGIN_SUFFIX}          # the release-tier name
#   .else                                  # the release branch
#       PLUGIN_CONFLICTS += ${PLUGIN_NAME}${PLUGIN_SUFFIX}   # name+suffix; conflicts
#       PLUGIN_PKGSUFFIX  =                            # with the devel-tier name
#   .endif
#
# Devel-tier build: pkg becomes 'os-netboot-devel', conflicts with 'os-netboot'.
# Release-tier build: pkg becomes 'os-netboot' (PLUGIN_PKGSUFFIX empty),
#                     conflicts with 'os-netboot-devel'.
#
# We want the release-tier flow because:
#
#   (a) OPNsense's Firmware Plugins UI (Core/Api/FirmwareController.php ~line
#       873) hides any plugin whose name ends in '-devel' unless the box is
#       set to Type: Development. Common Type: Community installs never see
#       -devel plugins in the GUI even with 'Show community plugins' on.
#
#   (b) The devel branch sets PLUGIN_CONFLICTS to PLUGIN_NAME bare. With
#       PLUGIN_SUFFIX=-devel that's fine (devel conflicts with release).
#       With PLUGIN_SUFFIX= empty the conflict becomes the OWN pkg name,
#       and register.php correctly removes the just-installed plugin from
#       the configured list -- resulting in '(misconfigured)' forever.
#
# Setting PLUGIN_DEVEL= here BEFORE the .include defines it to empty.
# devel.mk's 'PLUGIN_DEVEL?=yes' is then a no-op (already defined),
# plugins.mk takes the release branch, and everything lines up.
PLUGIN_DEVEL=

# This file is meant to be built from within a checkout of opnsense/plugins,
# at ftp/netboot/Makefile. The CI workflow (.github/workflows/build-release.yml)
# clones opnsense/plugins and copies this directory into ftp/netboot/ before
# invoking `make package`. Local builds follow the same procedure -- see
# README.md "Build from source".
.include "../../Mk/plugins.mk"
