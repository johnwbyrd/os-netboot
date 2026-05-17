PLUGIN_NAME=		netboot
PLUGIN_VERSION=		0.1.0
PLUGIN_COMMENT=		Netboot (PXE/iPXE) infrastructure: TFTP + HTTP + SFTP ingress + GUI file manager
PLUGIN_DEPENDS=		tftp-hpa
PLUGIN_MAINTAINER=	johnwbyrd@gmail.com
PLUGIN_WWW=		https://github.com/johnwbyrd/os-netboot

# This file is meant to be built from within a checkout of opnsense/plugins,
# at ftp/netboot/Makefile. The CI workflow (.github/workflows/build-release.yml)
# clones opnsense/plugins and copies this directory into ftp/netboot/ before
# invoking `make package`. Local builds follow the same procedure -- see
# README.md "Build from source".
.include "../../Mk/plugins.mk"
