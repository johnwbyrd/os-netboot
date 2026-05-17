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
| BIOS + UEFI out of the box    | One-click "Bootstrap netboot.xyz" fetches both `netboot.xyz.kpxe` (legacy BIOS) and `netboot.xyz.efi` (UEFI x86_64) into the content root. You don't pick one. |
| File management — web GUI     | Browse / upload (drag-drop) / download / delete / **fetch-from-URL** (paste a link, firewall pulls the file server-side)      |
| DHCP boot wiring helper       | One-click populates **both** BIOS and UEFI x86_64 boot entries in Dnsmasq, with arch-aware tags (DHCP option 93), pointing at the Netboot listen address       |
| Firewall integration          | Pass rules for TFTP/HTTP/SFTP on the chosen listen interfaces are added automatically                                         |
| Boot lifecycle                | `_configure()` `bootup` hook — actually starts at boot (the os-tftp bug that started this project)                            |
| HA                            | `_xmlrpc_sync()` for replicating settings to the secondary firewall                                                           |

---

## Install

`os-netboot` is distributed as a third-party OPNsense package repository.
After a **one-time** two-command setup on each firewall, install, upgrade,
and uninstall happen entirely in the OPNsense GUI — same UX as the official
community plugins.  This is the same pattern used by other established
third-party plugin repos like `mimugmail`'s.

### Step 1.  Add the repository (one time, on each firewall)

You need a shell session on the OPNsense firewall just for this step.  Two
paths:

**A. Console.**  Keyboard and monitor (or serial / IPMI / iLO) on the
OPNsense box.  At the menu, press `8` then Enter.

**B. Temporarily enable SSH.**  In the OPNsense GUI: **System → Settings →
Administration**.  Under "Secure Shell", check three boxes: **Enable Secure
Shell**, **Permit root user login**, **Permit password login**.  Save.
SSH in from a LAN computer as root.  **Disable these three boxes again
after Step 1 finishes.**

Once you have a shell, run:

```sh
fetch -o /usr/local/etc/ssl/os-netboot.pub  https://johnwbyrd.github.io/os-netboot/os-netboot.pub
fetch -o /usr/local/etc/pkg/repos/os-netboot.conf  https://johnwbyrd.github.io/os-netboot/os-netboot.conf
pkg update -f
```

Done.  You can log out of the shell and disable SSH again (Step 1B in
reverse) if you enabled it.

### Step 2.  Install in the GUI

Navigate to **System → Firmware → Plugins**.  Enable **Show community
plugins** (top-right checkbox).  Find `os-netboot` in the list and click
the **+** icon next to it.

That's the entire install.  No more shell.

### Step 3.  Configure

In the GUI, navigate to **Services → Netboot → General**:

1. Check **Enable**.
2. Pick **Listen interfaces** — typically your LAN.
3. Leave the rest at defaults (`/var/netboot`, HTTP port 8069) unless you
   have reason to change them.
4. Click **Save**.

TFTP and HTTP start automatically.  Green status dots at the top of the
Netboot page confirm.

### Step 4.  Put files in (all GUI)

**Services → Netboot → Files**.  Three paths:

- **Bootstrap netboot.xyz** (one click).  Fetches both the legacy-BIOS iPXE
  binary (`netboot.xyz.kpxe`) **and** the UEFI x86_64 iPXE binary
  (`netboot.xyz.efi`) from `boot.netboot.xyz` into the content root.  This
  is what you want for the common case — your fleet has a mix of BIOS and
  UEFI machines, and you want every one of them to be able to PXE-boot the
  netboot.xyz menu without further configuration.  After clicking this
  once, both boot types work; you don't pick one.
- **Drag-and-drop upload.**
- **Fetch from URL.**  Paste a link, the firewall pulls it server-side.
  Useful for things outside the netboot.xyz tree (custom iPXE menus,
  rescue images, Clonezilla, memtest, etc.)

### Step 5 (optional).  Auto-wire Dnsmasq DHCP boot entries

If your LAN runs OPNsense's built-in Dnsmasq DNS/DHCP, click **Wire up DHCP
boot entries** on the Netboot settings page.  It creates BIOS and UEFI
arch-aware boot tags and file entries automatically.

### Step 6 (optional).  SFTP ingress for power users

**Services → Netboot → General → SFTP** section:

1. Check **Enable SFTP ingress**.
2. Paste your SSH **public key(s)** into the Authorized Keys field, one
   per line.
3. (Optional) Change the SFTP port — default 2069, *not* 22.
4. Save.

Then from any machine with the matching private key:

```sh
sftp -P 2069 _netboot@<OPNsense IP>
```

You'll land chrooted in the content root.

---

## Upgrade and uninstall

Both are GUI operations, just like the install.

- **Upgrade.**  **System → Firmware → Plugins** — when a newer version of
  `os-netboot` is in the repo, the **+** icon is replaced by an upgrade
  arrow.  Click it.  Or use the global **System → Firmware → Status →
  Check for updates** which includes plugins.
- **Uninstall.**  **System → Firmware → Plugins** — click the trash icon
  next to `os-netboot`.  Your content (`/var/netboot`) and settings
  (`config.xml` netboot section) are preserved.  Re-install picks up where
  you left off.

To **remove the repository itself** (e.g. uninstalling everything from
this maintainer), shell back in and delete the two files Step 1 dropped:

```sh
rm /usr/local/etc/pkg/repos/os-netboot.conf
rm /usr/local/etc/ssl/os-netboot.pub
pkg update -f
```

---

## Troubleshooting

- **Plugin doesn't appear in the list.**  Make sure "Show community plugins"
  is checked.  If still missing: shell in, run `pkg update -f` and check
  there are no errors mentioning `os-netboot.pub` (wrong key path) or
  `signature_type` (mismatched signature config).
- **Service won't start.**  System → Log Files → General — search for
  `netboot_`.  Most common cause is a stale rendered config; click Save
  again on the Netboot General page to re-render templates.
- **Clients don't PXE boot.**  From a client on the same network as the
  listen interface: `tftp <netboot-ip>` then `get netboot.xyz.kpxe`.  If
  that times out, check the listen interface and that the firewall rules
  added by Netboot are present and green.  If TFTP succeeds, the issue is
  DHCP-side: verify DHCP boot entries point at the Netboot listen IP.
- **Upload fails with permission denied.**  At shell:
  `chown -R _netboot:_netboot /var/netboot`.

---

## Build from source

For developers who want to build the `.pkg` locally instead of using the
hosted repo.

You need a FreeBSD or OPNsense build host with `pkg install -y bsdmake git`.

```sh
git clone https://github.com/opnsense/plugins opnsense-plugins
git clone https://github.com/johnwbyrd/os-netboot
mkdir -p opnsense-plugins/ftp/netboot
cp -R os-netboot/. opnsense-plugins/ftp/netboot/
cd opnsense-plugins/ftp/netboot
make package
ls -la work/pkg/*.pkg
```

The hosted release flow lives in `.github/workflows/build-release.yml`:
it builds inside a FreeBSD VM, signs the package, generates a `pkg repo`
catalog, and publishes the whole `pkg/${ABI}/` tree (plus the public key
and a sample `.conf`) to the `gh-pages` branch — that's what
`johnwbyrd.github.io/os-netboot/` serves.

Repository signing setup (one-time, for the maintainer): see
[`dist/README.md`](dist/README.md).

## Upstream

Once stable, this plugin will be submitted to `opnsense/plugins` at
`ftp/netboot/` for inclusion in the official OPNsense community plugins
repository.  At that point the third-party-repo dance above goes away —
`os-netboot` will appear in **System → Firmware → Plugins** out of the box,
without users adding any extra repo.

## License

BSD-2-Clause.  See [LICENSE](LICENSE).
