# Testing Design Document

Working doc for the os-netboot test architecture.  Updated as we
implement; mark sections **DONE**, **PARTIAL**, or **PROPOSED**.

---

## Why this document exists

The plugin's reason for existing is: *"I want my PCs to PXE-boot reliably
when their disks die."*  That outcome is what we ship.  Every other
verification is a proxy.  If nothing in CI actually boots a machine over
PXE, we are not testing the parachute -- we are inspecting the rigging
on the ground.

We will end up with multiple layers of tests because the cheap layers
catch the most bugs per second of CI time, while the expensive layers
catch the bugs that matter most.  The cheap layers exist to make the
expensive one's red-builds rare; the expensive one exists because some
bugs are only visible end-to-end.

## Test taxonomy

Seven layers, ordered from cheapest to most expensive.  Each one
exists because there's a class of bug only it can catch.

### Layer 1 -- Pure unit tests  (**PARTIAL**)

Static utility classes with no Phalcon, no OPNsense framework, no
FreeBSD-specific dependency.  Runs on the Linux GH Actions runner in
under a second per push.

| Class | Status |
| --- | --- |
| `OPNsense\Netboot\PathResolver` | DONE -- 19 cases, see `src/opnsense/mvc/tests/PathResolverTest.php` |
| Authorized-keys parser/validator | proposed -- parse OpenSSH `authorized_keys` format, reject garbage |
| Port-range validator | proposed -- warn on privileged ports / port conflicts |
| Content-root path validator | proposed -- reject `/`, `/etc`, `/var`, `/dev`, etc. |

These tests run via plain PHPUnit on the Linux runner (`ci.yml`).

### Layer 2 -- Model XML schema tests  (**PROPOSED**)

Load `Netboot.xml` (and any future model XMLs) with a plain XML parser.
Verify:

- Every `<Field>` has the required attributes.
- Every `<ValidationMessage>` ends with a period and starts with a
  capital.  (`make lint` already does this, but it's worth a
  redundant check because lint may not run on every change.)
- Every `<Default>` value, when fed back through its own `<Mask>`,
  validates.  Catches "the default is rejected if you type it" bugs.
- Every `<InterfaceField>` references a real OPNsense interface
  attribute (cross-check against a known set).
- ACL.xml's `<page-*>` patterns match real controller routes.

PHP + DOMDocument; runs on Linux.

### Layer 3 -- Template render tests  (**PROPOSED**)

Render each Jinja2 template in `src/opnsense/service/templates/OPNsense/Netboot/`
with a representative fixture config, then **invoke each daemon's
own config-check mode** on the output:

- `lighttpd -t -f rendered.conf` -- exits 0 iff the config is valid.
- `sshd -t -f rendered.conf` -- ditto.
- `in.tftpd <flags> --help` (no native dry-run flag, but we can do a
  hand-parse for the flag set or run it and immediately SIGTERM).

This catches *every* Jinja2 typo and every "the new daemon version
renamed this directive" upstream regression.

Runs in the FreeBSD VM (lighttpd / sshd are FreeBSD-installed).
Triggered from `build-release.yml` before the package build, so a
template syntax error fails the build before we publish.

### Layer 4 -- Controller integration tests  (**PROPOSED**)

Phalcon-bound.  Set up a tiny in-memory `config.xml`, instantiate
Phalcon DI, call each API endpoint with mocked requests, assert
response shape and side-effects.  Hard part is the Phalcon test
scaffold -- but `opnsense/core/src/opnsense/mvc/tests/` has a working
pattern we can crib from.

Specific things to assert:

- `Api/GeneralController::getAction` returns the right field set.
- `Api/GeneralController::setAction` rejects bad inputs with our
  detailed error messages (regression test for the error-message audit).
- `Api/ServiceController::reconfigureAction` runs the four configd
  actions in the right order (mock the backend, capture calls).
- `Api/ServiceController::statusAction` correctly aggregates from
  three daemons.
- `Api/FilesController::uploadAction` rejects unsafe filenames using
  `PathResolver::isSafeName` (delegation test).

Runs in the FreeBSD VM (Phalcon is not on the Linux runner).

### Layer 5 -- Configd action / shell-helper tests  (**PROPOSED**)

For each script in `src/opnsense/scripts/netboot/` and each action in
`actions_netboot.conf`, a `*Test.sh` that runs the script against a
fixture `config.xml` and asserts side-effects:

- `setup.sh` -- after running, `_netboot` user exists, `/var/netboot/`
  exists with correct mode and ownership, idempotent (run twice, no
  errors).
- `bootstrap.sh` -- run with each preset (`netboot_xyz`, `ipxe`, plus
  an unknown preset to verify rejection), mock `fetch` to a local HTTP
  server serving canned BIOS/UEFI binaries; verify the right pair lands
  in the configured content root with the right local filenames and
  perms. Verify the script self-heals when `_netboot` or the content
  root are missing (calls `setup.sh` first).
- `fetch_url.sh` -- pass `..`-containing paths, verify rejection; pass
  empty URL; pass `ftp://...`; pass legitimate URL; assert each case.

Plus a meta-test that every action declared in `actions_netboot.conf`
references a script that exists and is executable.

Runs in the FreeBSD VM.

### Layer 6 -- End-to-end PXE boot  (**PROPOSED**, this doc's focus)

See "Layer 6 in depth" below.  Two real VMs over a virtual switch,
controlled iPXE chainload, success measured by a token printed to the
client's serial console.  Separate workflow, fires nightly + after every
successful `build-release` on `main`.

### Layer 7 -- Documentation tests  (**PROPOSED**)

- All relative links in `README.md` resolve.
- All commands in fenced code blocks in `README.md` parse as valid
  shell (and where reasonable, execute in the FreeBSD VM).
- Every `gettext()` format string has matching `sprintf` argument
  counts -- catches "Cannot list %s" with no second arg.
- `dist/README.md` and `doc/*.md` get the same treatment.

Cheap, runs on Linux.

---

## Layer 6 in depth -- the actual PXE boot test

### Why this is the test that matters

Layers 1-5 each verify that a *piece* of the plugin is correctly
shaped.  Only Layer 6 verifies that a PC actually boots over the
network using what we configured.  It's slow.  It will be flaky at
first.  Without it, we are saying "we *think* this works" rather than
"we *know* this works."

### Topology

Two QEMU VMs on a single GH Actions runner, connected via QEMU's
userspace socket networking (no host bridges, no privileged setup):

```
              GH Actions runner (ubuntu-latest, KVM available)
              +-----------------------------------------------+
              |                                               |
              |     OPNsense VM        PXE client VM         |
              |     +---------+         +---------+          |
              |     | WAN nic |--NAT--> Internet  |          |
              |     |         |                              |
              |     | LAN nic +--+   +---------+             |
              |     +---------+  |   | net nic |--+          |
              |                  |   +---------+  |          |
              |                  |                |          |
              |          QEMU userspace socket switch        |
              |          (-netdev socket on both)            |
              +-----------------------------------------------+
```

Both VMs use KVM acceleration (`/dev/kvm` is exposed on standard runners).
Without KVM, OPNsense boot is 5+ minutes; with it, ~60 seconds.

### Two client variants

The plugin's whole pitch is "BIOS *and* UEFI out of the box," so the
test must cover both:

| Client | Firmware | Expected DHCP-served filename |
| --- | --- | --- |
| BIOS | SeaBIOS (QEMU default) | `netboot.xyz.kpxe` |
| UEFI x86_64 | OVMF (`-bios /usr/share/OVMF/OVMF_CODE.fd`) | `netboot.xyz.efi` |

Each runs as a separate QEMU invocation; can be sequential (simpler,
~2x runtime) or parallel (twice the host load).  Start sequential;
parallelize if runtime is painful.

### Bringing OPNsense up reproducibly

The OPNsense vendor `.qcow2` image is downloadable, ~400 MB, license
permits redistribution.  We bake a known config.xml into it once,
publish the baked artifact as a GitHub Release asset on this repo
(call it `e2e-base-image`), and pin the e2e workflow to a specific
release of that artifact.  Bumping the OPNsense version becomes a
deliberate act: cut a new e2e-base-image release, bump the pin.

The baked config has:

- WAN interface configured for DHCP (gets address from QEMU NAT).
- LAN interface at `10.99.0.1/24`.
- Dnsmasq DHCP enabled on LAN with range `10.99.0.100-10.99.0.200`.
- Root SSH enabled with a known public key.
- An API key + secret enrolled for an `e2e` admin user.
- Our pkg repo's `os-netboot.conf` + `os-netboot.pub` pre-installed
  under `/usr/local/etc/pkg/repos/` and `/usr/local/etc/ssl/`.

The orchestrator never sees the prompts that a real first-boot would
show -- the image comes up with everything wired.

### Orchestration

A small Python module under `tests/e2e/` (not Bash -- the orchestration
logic itself needs to be testable, and we will want pcap parsing).
Speaks to OPNsense over its REST API (HTTPS, API-key auth) for plugin
install + configure, and over SSH for filesystem snapshots and log
collection.

The plugin install path is itself a test artifact -- if the API
install endpoint regresses, the E2E catches it.  We don't use the
GUI; the GUI is exercised manually pre-release.

### The chain we want to exercise

```
client firmware  (DHCP DISCOVER + PXE options request)
   -> OPNsense Dnsmasq            (DHCP OFFER with next-server + filename)
      -> client firmware          (TFTP RRQ for filename)
         -> OPNsense in.tftpd     (TFTP DATA: serves netboot.xyz.{kpxe,efi})
            -> iPXE running on client
               -> HTTP GET to controlled test menu URL
                  -> menu prints TEST_OK_<token> to serial console
                     -> orchestrator reads token from client serial
                        -> test passes
```

Six discrete protocol hops, each a possible failure point, each
visible in the captured evidence.

### Controlled iPXE chainload, not real netboot.xyz

For the test to be deterministic, the iPXE inside `netboot.xyz.{kpxe,efi}`
must NOT fetch the live `boot.netboot.xyz/ipxe/menu.ipxe`.  Real
netboot.xyz has the right to change content, go down, or rate-limit our
test runner.

Instead: drop a known-good `test-menu.ipxe` script into the content
root (via the plugin's own file-upload API -- itself a test), then
override DHCP option 67 ("boot file name") via the plugin's Dnsmasq
helper to point the iPXE-on-client at our test menu instead of
netboot.xyz's.

The test script:

```ipxe
#!ipxe
echo TEST_OK_<token>
sleep 5
exit
```

The orchestrator generates a random `<token>` per run, embeds it,
captures the client's serial console output, and asserts the token
appears.  Token presence proves:

- TFTP delivered a valid iPXE binary.
- That iPXE binary actually executed (not just got downloaded).
- That iPXE could speak HTTP to our HTTP server (port 8069).
- That iPXE could parse and execute a chainloaded script.

Real-netboot.xyz testing happens manually pre-release.  CI uses the
controlled chain; nothing in CI depends on an external service for
pass/fail.

### Evidence captured per run

All saved as a single GH Actions artifact named `e2e-<run-number>.zip`,
regardless of pass/fail:

- `pcap/lan.pcap` -- QEMU `-object filter-dump` writes the full LAN
  traffic for both client tests.
- `opnsense/serial.log` -- the OPNsense VM's serial console output.
- `opnsense/logs/` -- a tar of `/var/log/` from the OPNsense VM,
  collected via SSH at end of test.
- `client-bios/serial.log` -- the BIOS client's serial output (where
  TEST_OK_<token> should appear).
- `client-uefi/serial.log` -- ditto for UEFI.
- `api/*.json` -- every API request/response, captured for replay.
- `harness.log` -- the orchestrator's own structured log.

Post-mortem of a failed E2E reads almost entirely from this artifact.

### Workflow trigger and cadence

A new `.github/workflows/e2e.yml`:

```yaml
on:
  workflow_run:
    workflows: [build-release]
    types: [completed]
    branches: [main]
  schedule:
    - cron: '0 6 * * *'   # nightly at 06:00 UTC
  workflow_dispatch:
```

`workflow_run` chains after every successful `build-release` on main;
nightly cron catches "the published pkg is fine but our test fixtures
or the OPNsense base image regressed"; `workflow_dispatch` lets a
maintainer re-run on demand.

### Estimated runtime

| Phase | Cold cache | Warm cache |
| --- | --- | --- |
| Restore base image | ~3 min | <30 sec |
| Boot OPNsense, wait for API ready | ~90 sec | ~90 sec |
| Install + configure plugin | ~30 sec | ~30 sec |
| Bootstrap netboot.xyz fetch | ~10 sec | ~10 sec |
| BIOS client boot + capture | ~90 sec | ~90 sec |
| UEFI client boot + capture | ~120 sec | ~120 sec |
| Teardown + artifact upload | ~30 sec | ~30 sec |
| **Total** | **~8 min** | **~6 min** |

Acceptable as a workflow_run + nightly.  Not acceptable as a per-push
gate.

### Implementation phases

1. **Phase 6.0 -- Base image baking.**  One-time script that takes
   the upstream OPNsense vendor qcow2, boots it via QEMU with serial
   console, automates the first-time-setup prompts via `expect` or
   pre-staged config injection, then snapshots the result as
   `e2e-base-image-vNN.qcow2`.  Publish as a GH Release asset.
2. **Phase 6.1 -- Smoke E2E.**  Boot OPNsense, install plugin via
   API, verify TFTP serves the kpxe via a CLI tftp client *running on
   the OPNsense host itself* (not yet a real PXE client).  This
   catches 80% of regressions for 30% of the implementation effort.
   Done = a working `e2e.yml` that does this and runs nightly.
3. **Phase 6.2 -- Real PXE clients.**  Add the BIOS and UEFI QEMU
   clients with the controlled iPXE chainload.  Pcap capture, serial
   token assertion.  Done = full end-to-end including iPXE execution.
4. **Phase 6.3 -- Optional Layer-6b "real" test.**  A separate, looser
   test that lets iPXE chainload real `boot.netboot.xyz` and just
   asserts "iPXE managed to fetch *something*" without checking
   content.  Runs nightly only.  Flaky on purpose; informational.

Each phase is a separable PR.

### Open decisions

These are *not* answered in this document; they're flagged for the
implementer to resolve at write time.

| Decision | Options |
| --- | --- |
| Test-fixture iPXE chainload OR real `netboot.xyz` | Recommended: controlled iPXE for CI; real for manual pre-release |
| Base image hosting | GH Release on this repo (recommended) vs S3 vs always-fetch-from-opnsense.org |
| Pcap parsing language | Python (recommended -- scapy or dpkt) vs `tshark -T fields` shell |
| What happens if `boot.netboot.xyz` is down during CI | Phase 6.3 flaky-on-purpose test; phase 6.2 doesn't care because it uses controlled chain |
| Per-push smoke vs nightly only | Nightly only, workflow_run after every successful build-release on main |
| Plugin uninstall test | Should be in scope -- a separate run that installs, configures, uninstalls, verifies `/var/netboot/` content survives and `<netboot>` config tree is preserved |

---

## What this document is NOT

- Not the code.  Implementation comes after this design is settled.
- Not a guarantee of total bug coverage.  A skilled adversary will
  still find ways past these layers; the document is about routine
  regressions and the cost-of-confidence tradeoff for an open-source
  third-party plugin.
- Not a substitute for security review of the security-critical paths
  (PathResolver, signing, SFTP chroot).  Those want a deliberate
  audit on top of automated tests.

## Living document conventions

- Mark each layer **DONE** / **PARTIAL** / **PROPOSED** in this doc as
  it lands.
- Each layer's tests live in a predictable location at the REPO ROOT
  under `tests/`, NOT under `src/opnsense/mvc/tests/`. The latter is
  owned by the opnsense-core package's pkg-plist, and any file we
  put there collides with core at pkg install time (we caught this
  the hard way during the first end-to-end install attempt). All
  test files live OUTSIDE `src/` so they don't end up in the
  auto-generated package manifest.
  - Layer 1: `tests/*Test.php`
  - Layer 2: `tests/ModelXml*Test.php`
  - Layer 3: `tests/TemplateRender*Test.php`
  - Layer 4: `tests/Api*ControllerTest.php`
  - Layer 5: `tests/configd/*Test.sh`
  - Layer 6: `tests/e2e/`
  - Layer 7: `tests/docs/`
- A change that crosses layers (e.g., adds a new model field that
  needs new validation + new controller endpoint + new template
  field) lands the tests in the same PR as the change.
- A bug caught by a higher layer that *could* have been caught at a
  lower layer triggers a "lower the test" follow-up: add the missing
  layer-N test in a separate PR, link both PRs.
