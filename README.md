# os-netboot

Netboot (PXE/iPXE) infrastructure plugin for OPNsense. One content root,
served simultaneously by **TFTP** (firmware-stage bootstrap), **HTTP**
(everything iPXE chainloads thereafter), and optionally **SFTP** (admin
bulk ingress). A web GUI file manager handles per-file upload, download,
delete, and server-side fetch-from-URL so you can pull files directly
onto the firewall without shell access.

## Why this plugin exists

Setting up netboot on OPNsense currently means:

1. Install the community `os-tftp` plugin
2. Discover it doesn't start at boot (community plugin gap)
3. SSH into the box to put files in `/usr/local/tftp`
4. Manually wire DHCP boot entries in Dnsmasq with arch-aware tags
5. Manually add firewall rules

This plugin does all of that in one place, properly, with no shell access
required after install.

## Features (v0.1.0)

| Concern | What you get |
|---|---|
| TFTP | `in.tftpd` from tftp-hpa, multi-interface, IPv6, secure mode (chroot) |
| HTTP | Dedicated `lighttpd` instance (reuses the lighttpd already shipped with OPNsense) on a configurable port |
| SFTP ingress (optional) | Dedicated `sshd` instance, SFTP-only, chrooted, separate from your management SSH |
| File management | Web GUI: list / upload / download / delete / fetch-from-URL |
| DHCP integration | One-click "wire up Dnsmasq" populates BIOS + UEFI boot entries with arch-match tags |
| Firewall | `_firewall()` hook auto-adds pass rules on chosen listen interfaces |
| Boot lifecycle | Proper `_configure()` `bootup` hook — actually starts at boot |
| HA | `_xmlrpc_sync()` for config replication |

## Install

```
pkg add https://github.com/johnwbyrd/os-netboot/releases/download/v0.1.0/os-netboot-0.1.0.txz
```

Then in the OPNsense GUI: **Services → Netboot → General**.

## Default ports

| Service | Port | Configurable |
|---|---|---|
| TFTP | UDP/69 | yes |
| HTTP | TCP/8069 | yes |
| SFTP | TCP/2069 | yes |

Default content root: `/var/netboot`.

## Build from source

You need a FreeBSD or OPNsense build host with the standard plugin build
prerequisites (`pkg install -y bsdmake git`).

```
git clone https://github.com/opnsense/plugins opnsense-plugins
git clone https://github.com/johnwbyrd/os-netboot
cd os-netboot
make package
ls -la work/pkg/*.txz
```

CI builds on every tagged release via `.github/workflows/build-release.yml`;
the resulting `.txz` is attached to the GitHub Release.

## Upstream

Eventual goal: PR into `opnsense/plugins` at `ftp/netboot/` so this lands
in the official OPNsense community plugins catalog.

## License

BSD-2-Clause. See [LICENSE](LICENSE).
