# Publisher rotation, expiry and recovery

The current Ed25519 self-signing key remains the bootstrap authority. Normal
installation does not rotate keys, initialize a stricter policy, or trust a
downloaded public key. Module signatures and publisher verification are separate:
this procedure changes publisher authority, not the existing local module signer.

New release manifests carry signed `key_id`, `issued_at` and `expires_at`
metadata. The release signer sets a one-year validity. Verification rejects
future/expired metadata and signatures outside a key's active window, before
using artifact hashes or executing the installer. Existing published manifests
without metadata remain compatible until a PBX explicitly initializes the
reviewed policy. Keep the clock synchronized.

## Initialize and review

As root, inspect the installed authority:

```bash
python3 -I /usr/local/bin/sls_mass_notify/sls_release_trust.py status
```

After confirming the bootstrap fingerprint and that the intended release uses
the new metadata, run the same command with `initialize`. This preserves the
current key and creates a root-private ledger plus a permanent initialized
marker under `/var/lib/sls-mass-notify-trust`. Once initialized, old manifests
without signed expiry metadata are rejected. Missing/corrupt established state
fails closed; an update cannot silently revert to bootstrap trust.

This ledger is security authority and revocation history, not PBX settings. It is
deliberately outside web-writable `.config`, is not exposed by an API, and is not
rewound by ordinary configuration or native PBX restoration. Include the entire
root trust directory in independently protected disaster recovery material.

## Normal rotation

Prepare a reviewed policy with exactly `schema: 1`, the current `sequence + 1`,
and one to four keys. Each key has:

| Field | Meaning |
|---|---|
| `id` | SHA-256 of its 44-byte Ed25519 DER SubjectPublicKeyInfo. |
| `public_key` | Base64 of those DER bytes; no URL or file path. |
| `not_before` | UTC Unix timestamp; zero permits immediate use. |
| `expires_at` | Exclusive UTC timestamp; zero has no key-level expiry. |
| `revoked_at` | Revocation timestamp; zero is not revoked. |

The helper's `key_entry(public_PEM_bytes)` returns this structure. First retain
the existing key and add the new key for an overlap period. Generate and store
the new private key independently of the repository. On the publisher's protected
build machine, sign the reviewed policy using both private keys:

```bash
python3 tools/sign_trust_policy.py --policy reviewed-policy.json \
  --key /secure/existing-ed25519.key --key /secure/new-ed25519.key \
  --output signed-transition.json
```

The output is a signed envelope, not executable code. Independently review its
fingerprints, sequence, validity dates and intended deployments. On each PBX,
import the reviewed local file as root:

```bash
python3 -I /usr/local/bin/sls_mass_notify/sls_release_trust.py import-transition \
  --transition /secure/signed-transition.json
```

Import requires a currently trusted active issuer signature and possession
proof for every new key. It rejects skipped/replayed sequences and cannot revive
a retired key or delay an existing revocation. After qualifying the overlap,
import the next signed policy to revoke/remove the old key. Keep at least one
currently active authority. Removed keys are permanently retired locally.

Installed updates and tagged/offline installers use the same protected verifier
when available. Fresh installations bootstrap using the pinned current key;
deploy the reviewed overlap policy before distributing releases signed only by a
replacement key. Downloaded code cannot establish authority for its own signature.

## Emergency recovery

Stop automatic release installation at the operating-system level and investigate
the affected signing/build infrastructure. Independently obtain and verify a new
public key fingerprint through an owner-controlled channel. Do not accept a
replacement fingerprint merely because the compromised publisher supplied it.

With trusted installed recovery code and a readable current ledger, root can use
`recover-offline --public-key /secure/replacement.pub --fingerprint <exact SHA-256>
--current-sequence <reviewed sequence> --reason "<reviewed recovery reason>"`.
This deliberately bypasses a compromised/lost issuer after explicit root review,
retires every prior authority, increments the sequence and records the recovery
in the durable ledger. It is never invoked by the updater, web UI, or `.config`.

If the ledger or installed helper itself is untrusted/unreadable, recover them
from independently verified offline evidence before using this procedure. Do not
delete the initialized marker or roll back revocations to make an update pass.
Actual disaster recovery on a separate PBX remains an acceptance requirement.

Real disposable-key fixtures test overlap, possession proofs, revocation,
sequence replay, unsafe/missing storage, expiry, offline replacement and the
installer's use of the same authority. No production key is rotated by tests.
