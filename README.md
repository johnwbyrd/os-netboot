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
OPNsense has no GUI affordance for adding a custom repository, so the
first install on each firewall requires **one shell session, three
commands**.  After that, install / upgrade / uninstall all happen in the
GUI exactly like the official community plugins — the third-party-repo
plumbing is invisible from then on.

This is the same install pattern other established third-party plugin
repos use (e.g. `mimugmail`).

### Step 1.  Open a shell on the firewall (one time)

Two ways.  Pick whichever you have:

| Path | How |
|---|---|
| Console / serial / IPMI / iLO | At the OPNsense menu, press `8` then Enter. |
| Temporary SSH | In the GUI: **System → Settings → Administration → Secure Shell**, check **Enable Secure Shell**, **Permit root user login**, **Permit password login**, Save. SSH in as `root` from a LAN box. **Uncheck the same three boxes when Step 2 finishes.** |

### Step 2.  Register the repository and install the plugin

At the shell:

```sh
fetch -o /usr/local/etc/ssl/os-netboot.pub  https://johnwbyrd.github.io/os-netboot/os-netboot.pub
fetch -o /usr/local/etc/pkg/repos/os-netboot.conf  https://johnwbyrd.github.io/os-netboot/os-netboot.conf
configctl firmware install os-netboot
```

The first two `fetch`es drop a signed-repo descriptor and the matching
public key into the FreeBSD pkg configuration.  The third command is
OPNsense's plugin installer — it runs `pkg install`, then `register.php`
to record `os-netboot` as a configured plugin, then runs the plugin's
post-install hooks.  This is the same command path the GUI's **+**
button uses.

**Do not** use plain `pkg install os-netboot` from the shell.  Raw
`pkg install` puts the files on disk but skips OPNsense's
"configured plugins" tracking; the GUI then flags the plugin as
`(misconfigured)` and won't let you manage it normally.  Always use
`configctl firmware install <name>` from the shell.

Log out of the shell now.  If you enabled SSH in Step 1, go disable it
again.

### Step 3.  Configure

Everything from here on is in the GUI.  Navigate to **Services → Netboot →
General**:

1. Check **Enable**.
2. Pick **Listen interfaces** — typically your LAN.
3. Leave the rest at defaults (`/var/netboot`, HTTP port 8069) unless
   you have a specific reason to change them.
4. Click **Save**.

The Save handler creates the `_netboot` service user, makes the content
root, renders the daemon configs, starts TFTP + HTTP (+ SFTP if you
enabled it).  Status indicators at the top of the page go green for each
running daemon.

### Step 4.  Put boot content in

**Services → Netboot → Files**.  Three input paths:

- **Bootstrap netboot.xyz** (one click).  Fetches both `netboot.xyz.kpxe`
  (legacy BIOS) and `netboot.xyz.efi` (UEFI x86_64) from
  `boot.netboot.xyz` into the content root.  This is what almost everyone
  wants — your fleet has a mix of BIOS and UEFI machines, and clicking
  this once makes every one of them able to PXE-boot the netboot.xyz
  menu.  No firmware-type picking, no manual download-then-upload.
- **Drag-and-drop upload.**  Standard browser file picker.
- **Fetch from URL.**  Paste a URL, the firewall pulls the file
  server-side.  Useful for content outside the netboot.xyz tree —
  custom iPXE menus, rescue images, Clonezilla, memtest, etc.

### Step 5 (optional).  Auto-wire DHCP boot entries

If your LAN runs OPNsense's built-in Dnsmasq DNS/DHCP, click **Wire up
DHCP boot entries** on the Netboot settings page.  This creates BIOS and
UEFI x86_64 boot entries in Dnsmasq, with the arch-aware DHCP option 93
tags, pointing at the Netboot listen IP.  PXE clients then get the right
file served automatically.

If you run a different DHCP server, you'll wire it up yourself, in your
DHCP server's own config: tell it to advertise `next-server <Netboot
IP>` and a per-arch `bootfile-name` (`netboot.xyz.kpxe` for arch 0
legacy BIOS, `netboot.xyz.efi` for arch 7 / 9 UEFI x86_64).

### Step 6 (optional).  SFTP ingress for power users

If you'd rather push content in over SFTP (e.g. to `rsync` a large image
collection) than via the web upload form:

1. **Services → Netboot → General → SFTP**, check **Enable SFTP
   ingress**.
2. Paste your SSH **public** key(s) into Authorized Keys, one per line.
3. Leave the SFTP port at 2069 (it must NOT be 22; that's your
   management SSH).
4. Save.

Then from any client with the matching private key:

```sh
sftp -P 2069 _netboot@<OPNsense IP>
```

You'll land chrooted in the content root.  The SFTP user is a separate
system account with no shell, no password, and no access outside the
content root.

---

## Upgrade and uninstall

| Action | GUI path | Shell equivalent |
|---|---|---|
| Upgrade | **System → Firmware → Plugins** → upgrade arrow next to `os-netboot` | `configctl firmware update os-netboot` |
| Uninstall the plugin | **System → Firmware → Plugins** → trash icon next to `os-netboot` | `configctl firmware remove os-netboot` |
| Remove the repo entirely | n/a (must use shell) | `rm /usr/local/etc/pkg/repos/os-netboot.conf /usr/local/etc/ssl/os-netboot.pub && pkg update -f` |

Uninstall preserves your content (`/var/netboot`), your support
directories (`/var/db/netboot`, `/var/log/netboot`), and your plugin
settings (the `<netboot>` section of `config.xml`).  Reinstall picks up
where you left off.  To wipe completely, shell in and:

```sh
rm -rf /var/netboot /var/db/netboot /var/log/netboot
```

and edit out the `<netboot>` block from `config.xml` (**System →
Configuration → Backups → Download**, edit, **Restore**).

---

## Troubleshooting

- **Plugin shows `(misconfigured)` in the plugin list.**  You installed
  via raw `pkg install` instead of `configctl firmware install`.  Fix
  without reinstalling:
  ```sh
  /usr/local/opnsense/scripts/firmware/register.php install os-netboot
  ```
  Then refresh the Plugins page.
- **Plugin doesn't appear in the list.**  Check "Show community plugins"
  (top-right of the Plugins table).  If still missing: shell in and run
  `pkg update -f`, watch for errors mentioning `os-netboot.pub` (wrong
  key location) or signature validation.
- **`pkg update -f` says "No signature found in the repository".**  The
  pubkey at `/usr/local/etc/ssl/os-netboot.pub` is stale — re-fetch it
  with the Step 2 first command and retry.  This can happen after the
  upstream signing key rotates.
- **Services don't start after Save.**  **System → Log Files → General**,
  filter on `netboot_`.  Most common cause is a stale rendered config
  after a Netboot version bump — click Save again on the General page to
  re-render templates.
- **Clients don't PXE boot.**  From any machine on the LAN:
  ```sh
  tftp <Netboot IP>
  tftp> get netboot.xyz.kpxe
  tftp> quit
  ```
  If TFTP times out: the listen interface is wrong, or your firewall is
  blocking UDP/69 (the auto-rules should cover this, but custom rules
  ordered first can override).  If TFTP works but a real PXE client
  still doesn't boot: the DHCP server isn't advertising `next-server` +
  `bootfile-name`.  Verify the Dnsmasq DHCP boot entries on
  **Services → Dnsmasq DNS & DHCP → DHCP boot**.
- **Upload fails with permission denied.**  Content root ownership
  drifted.  At the shell:
  ```sh
  chown -R _netboot:_netboot /var/netboot
  ```

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
