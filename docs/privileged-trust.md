# Protected module signing and release generations

The installer enrolls the verified release and explicitly reviewed stock-module
inventories **before activating the signer**. Missing or mismatched trust refuses signing and preserves the existing
signature. The working web tree and prior local signatures cannot enroll trust.

## Installer bootstrap for older releases

The installer prepares approvals in a retained root-private workspace before changing runtime, trust pointers or maintenance. Missing stock inventories are derived from exact-version FreePBX packages authenticated by the bundled public key and pinned full fingerprint. Previous SLS files and overlays must match a publisher-verified release. Framework mapping, installer-only omissions, the supported menu insertion and spinner alias are explicit; unknown live differences produce a review report without enrolling trust. Existing independently reviewed inventories preserve local branding and security fixes.

Use `SLS_MASS_NOTIFY_INVENTORY_ONLY=1` for preparation without activation. Offline package paths and previous-release inputs are described in [Installation](../INSTALL.md#authenticated-installation-and-recovery). Reviewed overrides use `SLS_MASS_NOTIFY_DASHBOARD_INVENTORY` / `SLS_MASS_NOTIFY_FRAMEWORK_INVENTORY` plus each matching `_SHA256` variable. Store inventories beneath root-owned, non-writable parents and independently verify their provenance and contents. Never generate an approval merely by hashing the current web tree.

Recovery captures the approved previous root runtime and canonical maintenance entries before mutation. Static restoration initially disables SLS root jobs. Only verified recovered module/Framework/Dashboard bytes and runtime permit reinstating the approved previous jobs. Failed postconditions retain recovery locations and leave maintenance disabled. Configuration and delivery history are not rewound.

## Publisher generation

Install `bin/sls_mass_notify/sls_module_trust.py` as root-owned, non-writable code.
Its default store is `/var/lib/sls-mass-notify-trust`, root-only mode 0700. Run:

```sh
/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_module_trust.py enroll-sls \
  --archive /root/reviewed-release/slsmassnotifyserver-0.1.5-beta.tgz \
  --manifest /root/reviewed-release/release-manifest.json \
  --signature /root/reviewed-release/release-manifest.sig
```

The helper independently verifies the embedded publisher Ed25519 key, release
identity, archive SHA-256 and module XML; rejects unsafe/duplicate archive members;
and retains exact code in a content-addressed root-only generation. Activation is
atomic. Older generations remain available for reviewed rollback. An existing
corrupted generation is rejected rather than overwritten.

## Reviewed upstream and local overlays

Dashboard and Framework require inventories derived from independently verified
upstream archives at their exact versions plus individually reviewed local
changes. Preserve legitimate branding and independently maintained security
patches. Do not download stock modules over those changes simply to get a valid
signature. The administrative inventory import deliberately requires an explicit
SHA-256 supplied after review; it does not authenticate an upstream claim merely
because someone wrote that claim in JSON.

An inventory has this shape (hashes below are placeholders):

```json
{
  "schema": 1,
  "module": "framework",
  "version": "17.0.33",
  "source": {
    "kind": "reviewed-upstream-and-overlays",
    "archive_sha256": "<verified upstream archive SHA-256>"
  },
  "files": {
    "module.xml": {
      "sha256": "<approved file SHA-256>",
      "target": "admin/modules/framework/module.xml"
    },
    "amp_conf/htdocs/admin/views/menu_items.php": {
      "sha256": "<exact approved menu including SLS insertion>",
      "target": "admin/views/menu_items.php"
    }
  },
  "uninstall": {
    "remove": [],
    "replace": {
      "amp_conf/htdocs/admin/views/menu_items.php": "<exact reviewed original menu SHA-256>"
    }
  }
}
```

Every expected installed file must be included. Framework's mapped web files use
its authentic `amp_conf/htdocs/` signature names and relative web-root targets;
this covers the menu instead of signing only its module directory. Custom
Framework mappings outside the web root require separate implementation/review;
they cannot be smuggled into arbitrary absolute targets. The helper checks exact
additions/removals in the module directory and exact bytes of all declared mapped
files. It does not claim a complete inventory of all other FreePBX modules or
undocumented extra files throughout the shared web root.

For Dashboard, `uninstall.remove` may name only its two SLS widget files; no other
file can be removed from signing coverage by uninstall mode. Framework's uninstall
replacement may name only the exact menu file. This mode consumes previously
approved expected hashes, not cleaned live bytes.

Store the reviewed inventory under root-protected parents, then import it:

```sh
/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_module_trust.py enroll-reviewed \
  --inventory /root/reviewed-release/framework.json \
  --sha256 <independently-reviewed-inventory-SHA-256>
```

Each upstream upgrade and changed approved overlay requires a new reviewed
inventory. The installer must use the widgets from its verified SLS generation,
not whatever widget files happen to be installed. Inventories and release trust
are installation security state, not portable user configuration.

## Signer and uninstall behavior

The signer obtains expected hashes from the protected registry, validates the
live tree before key/signature changes, signs only expected hashes, and repeats
parity checks after publication. Unexpected PHP, Python bytecode, unsafe links,
hardlinks and special files are rejected. Only exact named non-executable
Dashboard LESS cache formats are excluded. The sole reviewed asset-link exception
is Framework spinner.gif pointing to the exact Core GIF, with its upstream digest. Publication/rollback pin the module
directory descriptor; replacing its path cannot redirect a privileged file write.

FreePBX metadata and signature verification execute as `asterisk`. Account/home
metadata is independently checked against passwd before root ownership repair;
GPG-home permission repair rejects hardlinks/symlinks and preserves its own live
agent sockets. This matches the existing runtime's asterisk-account assumptions;
a different service-account deployment needs a separately reviewed protected
platform profile, not web-supplied account names.

Uninstall snapshots the inventory-aware signer and its protected helper before
removing runtime files. Its local-signing fallback uses the preapproved uninstall
inventory and cannot fall back to a legacy signer that trusts the current tree.

## Privileged installation phases

`sls_privileged_install.py` accepts fixed phases: `plan`, `preflight`, `prepare`, `admit`,
`dependencies`, `activate`, and `verify`. Mutating phases require `--apply`.
There is no caller-supplied filesystem root, command, PHP payload or service name.
Before mutation the helper verifies the protected publisher generation's exact
inventory and hashes. It copies only those bytes to fixed runtime/API/media
locations. Root executable paths require root-owned non-writable ancestors;
state permission repair rejects links, special files and inode substitution.

`prepare` sets up runtime files, writable service-account state, fixed Apache and
collector definitions and log rotation. It preserves central configuration bytes,
existing audio and delivery history. `dependencies` executes only the authenticated
Piper installer, which validates every existing environment entry before executing
its interpreter. Unsafe writable/foreign-owned/hard-linked environments are refused,
not adopted by changing ownership. Only standard venv Python and lib64 links are
accepted. `activate` reloads Apache, starts the collector and finally enrolls fixed
root cron jobs. `verify` independently checks root-owned integrations and services.

FreePBX module registration, configuration migration, database access, AMI, dialplan,
SIP templates, menu/widgets, backup enrollment, user cron and reload hooks run as
`asterisk`. Maintenance admits the authenticated runtime before running root helpers
and uses these same phases for repair. Signer metadata/verification and all
uninstaller PHP/fwconsole calls also run as `asterisk`. Uninstall verifies approved
inventories before teardown and preserves reviewed branding/dependency hardening;
it does not replace Framework or Dashboard from a vendor download.

Behavioral fixtures cover the phase boundaries, activation failures, rollback,
and complete uninstall. Every deployment and upstream module update still needs
an approved baseline; test success does not approve an unknown host's files.
Interrupted upgrade and full recovery qualification require a separate disposable
PBX. A publisher check on SLS does not authorize executing mutable FreePBX modules
as root. The current publisher verifier still has a single pinned key; overlapping
publisher trust and emergency key rotation remain separate release-security work.

## Delivery coordination and configuration preservation

The installer and maintenance service share the authenticated `sls_install_guard.py`
helper. Its dedicated process holds scheduling, announcement, weather, manual-test
and all sixteen global paging leases until the parent closes its lifetime pipe.
Maintenance takes the settings mutex last. Fresh installation creates missing
configuration through the runtime-account initializer before acquiring its
ordinary settings mutex; existing configuration is never replaced by that step.

Installer and repair reloads explicitly preserve staged settings. An ordinary
administrator Apply Config retains its normal behavior. Repair verifies active
and pending configuration fingerprints and retained phone history before clearing
recovery markers. Failed checks retain the root-private baseline path in the log;
operational ledgers are never restored from that baseline. Child service and PHP
processes close the lifetime pipe so detached children cannot retain the guard.
