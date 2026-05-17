# dist/

Files in this directory are what users drop onto their OPNsense host to add
the `os-netboot` pkg repository, and what CI publishes alongside the built
`.txz` packages.

## Files in this repo

| File              | What it is                                                                                                  |
| ----------------- | ----------------------------------------------------------------------------------------------------------- |
| `os-netboot.conf` | The pkg repository config users drop at `/usr/local/etc/pkg/repos/os-netboot.conf` on their OPNsense host. |
| `os-netboot.pub`  | The repository's public signing key. Drop at `/usr/local/etc/ssl/os-netboot.pub`. **Generated, not committed by hand; see "First-time signing setup" below.** |

## Files published by CI to GitHub Pages (not in this repo)

Under `pkg/${ABI}/` (e.g. `pkg/FreeBSD:14:amd64/`):

| File               | What it is                                                                          |
| ------------------ | ----------------------------------------------------------------------------------- |
| `os-netboot-*.pkg` | The signed plugin package itself.                                                   |
| `packagesite.pkg`  | The repository catalog `pkg(8)` reads to discover what packages this repo contains. |
| `meta.conf`        | Repo metadata describing the catalog format.                                        |

## First-time signing setup (one time, on the maintainer's box)

The CI workflow needs a private key to sign the repo catalog. Public key
gets committed; private key never leaves the maintainer's machine and a
GitHub Actions secret.

```
# Generate a 4096-bit RSA keypair (do this once, ever).
openssl genrsa -out /tmp/os-netboot.key 4096
openssl rsa -in /tmp/os-netboot.key -out dist/os-netboot.pub -pubout

# Commit the public key to the repo.
git add dist/os-netboot.pub
git commit -m "Add repository signing public key"

# Put the private key into GH Actions secrets, named PKG_SIGNING_KEY.
# Easiest path: gh CLI.
gh secret set PKG_SIGNING_KEY < /tmp/os-netboot.key

# Then shred the private key from your filesystem; the only copy that
# matters now is in GH Actions secrets.
shred -u /tmp/os-netboot.key
```

If you ever lose the private key, generate a new one and bump the major
version of any users who installed under the old key (they'll need to
re-fetch the new pubkey before `pkg update` works again).
