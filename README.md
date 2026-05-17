# os-netboot

Netboot (PXE/iPXE) infrastructure plugin for OPNsense.  One content root,
served simultaneously by **TFTP** (firmware-stage bootstrap), **HTTP**
(everything iPXE chainloads thereafter), and optionally **SFTP** (admin
bulk ingress).  A web GUI file manager handles per-file upload, download,
delete, and server-side fetch-from-URL — so you can pull files directly
onto the firewall without shell access after the initial install.

## Why this plugin exists

Setting up netboot on OPNsense currently means: install the community
`os-tftp` plugin, discover it doesn't start at boot, SSH in to put files in
`/usr/local/tftp`, manually wire DHCP boot entries with arch-aware tags,
manually add firewall rules.  `os-netboot` does all of that in one place,
properly, and after install everything happens in the web GUI.

## Features (v0.1.0)

| Concern                       | What you get                                                                                                                  |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| TFTP                          | `in.tftpd` from tftp-hpa, IPv4+IPv6, secure mode (chroot), starts at boot                                                     |
| HTTP                          | Dedicated `lighttpd` instance on a configurable port (default 8069)                                                           |
| SFTP ingress (optional)       | Dedicated `sshd` instance, SFTP-only, chrooted, key-based auth, separate from your management SSH                             |
| File management — web GUI     | Browse / upload (drag-drop) / download / delete / **fetch-from-URL** (paste a link, firewall pulls the file server-side)      |
| DHCP boot wiring helper       | One-click populates BIOS and UEFI boot entries in Dnsmasq, with arch-aware tags, pointing at the Netboot listen address       |
| Firewall integration          | Pass rules for TFTP/HTTP/SFTP on the chosen listen interfaces are added automatically                                         |
| Boot lifecycle                | `_configure()` `bootup` hook — actually starts at boot (the os-tftp bug that started this project)                            |
| HA                            | `_xmlrpc_sync()` for replicating settings to the secondary firewall                                                           |

---

## Install

> **Heads-up.**  Until `os-netboot` is accepted into the official OPNsense
> plugins repository, the very first install requires running **one shell
> command** on the firewall.  This is a limitation of OPNsense's package
> manager — it only installs plugins from configured mirrors.  Once we're
> upstream you'll install from **System → Firmware → Plugins** with two
> clicks, like any other community plugin.  Everything *after* install is
> already pure GUI.

### What you need

- OPNsense 26.1 or newer.
- A one-time way to run a single command on the firewall (covered in Step 1).
- An interface where you want netboot to be available — usually your LAN.

### Step 1.  Get a shell on the firewall (one time)

Pick whichever path is easier for you.

**A. Console.**  If you have a keyboard and monitor — or serial / IPMI / iLO
— on the OPNsense box: at the menu, press `8` and Enter to drop into a
shell.

**B. Temporarily enable SSH.**  In the OPNsense GUI, go to **System →
Settings → Administration**.  Scroll to **Secure Shell** and check the
three boxes:

1. **Enable Secure Shell**
2. **Permit root user login**
3. **Permit password login**

Scroll to the bottom and click **Save**.  Then from any computer on your
LAN: `ssh root@<OPNsense IP>`, enter your root password.

After install completes, **come back to this page and uncheck those three
boxes** and Save again, so SSH is off.

### Step 2.  Install os-netboot

At the OPNsense shell — whichever way you got there — run:

```
pkg add https://github.com/johnwbyrd/os-netboot/releases/download/v0.1.0/os-netboot-0.1.0.txz
```

That's the entire shell portion.  Type `exit` (console) or close the SSH
session.  If you enabled SSH for this, **go disable it again now** (Step 1B,
reversed).

### Step 3.  Configure (all GUI from here on)

In the OPNsense GUI, navigate to **Services → Netboot → General**:

1. Check **Enable**.
2. Pick **Listen interfaces** — typically your LAN.
3. Leave the rest at defaults (`/var/netboot`, HTTP port 8069) unless you
   have reason to change them.
4. Click **Save**.

The TFTP and HTTP daemons start automatically.  The page header shows a
green status dot per running service.

### Step 4.  Put files in (also all GUI)

Go to **Services → Netboot → Files**.  Two paths to get content in:

- **Drag-and-drop upload.**  Drop files onto the list.  Subdirectories are
  supported via the "New folder" button.
- **Fetch from URL.**  Click **Fetch from URL**, paste a link (for example
  `https://boot.netboot.xyz/ipxe/netboot.xyz.kpxe`), and the firewall pulls
  the file directly onto disk.  This is the right way to seed your netboot
  tree with the netboot.xyz BIOS and UEFI bootstrap binaries — no laptop
  download + re-upload round-trip.

### Step 5 (optional).  Auto-wire Dnsmasq DHCP boot entries

If you use OPNsense's built-in Dnsmasq DNS / DHCP service for your LAN, the
Netboot page has a **Wire up DHCP boot entries** button.  It creates BIOS
and UEFI arch-aware boot tags and file entries pointing at your Netboot
listen address — the same thing you'd otherwise click through manually in
Services → Dnsmasq → DHCP boot.

### Step 6 (optional).  SFTP ingress for power users

If you want to push files in via SFTP / rsync rather than the upload UI:

1. **Services → Netboot → General**, scroll to **SFTP**.
2. Check **Enable SFTP ingress**.
3. Paste your SSH **public key(s)** into the Authorized Keys box, one per
   line.  (Public keys only — no passwords ever.)
4. (Optional) Change the SFTP port (default 2069 — *not* 22, which is your
   management SSH).
5. Save.

Then from any machine with the matching private key:

```
sftp -P 2069 _netboot@<OPNsense IP>
```

You'll land chrooted in the content root.

---

## What happens during install / removal

`pkg add` does the standard FreeBSD package install:

- Drops files in `/usr/local/opnsense/...` (the plugin's GUI controllers
  and templates) and `/usr/local/etc/inc/plugins.inc.d/netboot.inc`
  (registration with OPNsense's plugin framework).
- Installs rc.d scripts at `/usr/local/etc/rc.d/netboot_{tftpd,http,sftp}`.
- Creates the `_netboot` system user (no shell, no password).
- Creates `/var/netboot/` (the default content root) and `/var/db/netboot/`
  (for SFTP host keys and authorized_keys).
- Pulls in `tftp-hpa` as a dependency if it isn't already installed.

`pkg delete os-netboot` removes the plugin and stops the services, but
preserves `/var/netboot` (your content), `/var/db/netboot` (SFTP keys), and
the `<netboot>` section of `config.xml` (your settings).  Re-install picks
up where you left off.  To wipe completely:

```
pkg delete os-netboot
rm -rf /var/netboot /var/db/netboot
# also remove the <netboot> section in System → Configuration → Backups → restore
```

## Troubleshooting

- **Service won't start.**  System → Log Files → General — search for
  `netboot_`.  The most common cause is a stale rendered config; click
  **Save** again on the Netboot General page to re-render templates.
- **Clients don't PXE boot.**  From a client on the same network as the
  listen interface: `tftp <netboot-ip>` then `get netboot.xyz.kpxe`.  If
  that times out, check the listen interface is correct and the Netboot
  firewall rules under **Firewall → Rules → \[interface\]** are present
  and green.  If it succeeds, the issue is on the DHCP side — verify the
  DHCP boot entries exist with the right `next-server` and filename.
- **Upload fails.**  Likely a permissions issue on the content root.  At
  shell: `chown -R _netboot:_netboot /var/netboot`.

## Build from source

You need a FreeBSD or OPNsense build host with `pkg install -y bsdmake git`.

```
git clone https://github.com/opnsense/plugins opnsense-plugins
git clone https://github.com/johnwbyrd/os-netboot
mkdir -p opnsense-plugins/ftp/netboot
cp -R os-netboot/. opnsense-plugins/ftp/netboot/
cd opnsense-plugins/ftp/netboot
make package
ls -la work/pkg/*.txz
```

CI builds on every tagged release via `.github/workflows/build-release.yml`;
the resulting `.txz` is attached to the matching GitHub Release.

## Upstream

Once stable, this plugin will be submitted to `opnsense/plugins` at
`ftp/netboot/` for inclusion in the official OPNsense community plugins
repository.  Track progress at
[the upstream PR](https://github.com/opnsense/plugins/pulls?q=netboot)
when it's filed.

## License

BSD-2-Clause.  See [LICENSE](LICENSE).
