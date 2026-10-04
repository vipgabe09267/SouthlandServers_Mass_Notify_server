# Dependency inventory and verified wheels

The release pins are in `bin/sls_mass_notify/piper-requirements.txt` inside the module. Repair checks every installed version. Packaging and runtime installation now require SHA-256 hashes from `piper-packaging.lock` and `piper-requirements.lock`, with binary wheels only. Pip uses isolated configuration and the explicit HTTPS PyPI index. A platform without a matching approved wheel fails with the exact dependency error; it is never built from an unverified source archive.

`piper-artifacts.json` records the reviewed filenames, sizes, upstream URLs and hashes for all non-yanked wheel variants available when the lock was generated. This supports multiple wheel-compatible platforms without silently trusting newly uploaded artifacts. Regenerate locks deliberately when changing pins; test the affected Python/platform and speech output before release.

The packaged `docs/piper-sbom.cdx.json` is a reproducible CycloneDX 1.6 inventory of the declared Piper environment, Twilio callback validator, locked PHP identity libraries and bundled Chart.js library. Its scope explicitly excludes OS packages, other FreePBX modules and voice models. It is an inventory, not vulnerability clearance or proof that an arbitrary host installed those exact artifacts.

From a repository checkout, inspect the actual installed environment without importing or executing its package code:

```bash
python3 tools/build_sbom.py \
  --site-packages /usr/local/bin/sls_mass_notify/piper/venv/lib/python3.11/site-packages \
  --output /tmp/sls-installed-piper.cdx.json
```

A missing or mismatched required package makes this command fail. Additional installed distributions are included. The output contains package metadata, not PBX credentials or configuration.

Maintainers refresh artifact hashes with `python3 tools/lock_piper_dependencies.py --output-directory DIRECTORY`. Review the resulting changes and verify downloaded wheels before copying the three lock/inventory files into the runtime source directory.

For disconnected validation, prepare a wheelhouse on the same Python/platform using `pip download --only-binary=:all: --require-hashes -r piper-requirements.lock --dest WHEELHOUSE`. Both packaging and runtime locks are required. A disposable environment can install using `pip install --no-index --find-links WHEELHOUSE --only-binary=:all: --require-hashes -r piper-requirements.lock`.

When installed versions differ or the managed environment is broken, the installer builds a replacement in a private mount namespace. The candidate is mounted at the canonical interpreter path during construction, so generated console-script paths remain valid after activation. Package installs cannot modify the host's active environment. Exact versions, dependency consistency and Python/Piper entry points must pass before a Linux atomic directory exchange. Notification workers are held during exchange and post-activation checks. Failed validation restores the original directory atomically; incomplete cleanup retains the new validated environment and reports the recovery location.

Replacement requires root, Python venv support, mount namespaces and a filesystem supporting `renameat2(RENAME_EXCHANGE)`. Unsupported hosts receive a specific preservation/error message; there is no in-place fallback. Root-private `.replacement-*` directories retain failed-build or rollback evidence. Three retained directories stop further allocation until an administrator reviews them. Never remove the permanent `.replacement.lock` while a replacement could be running. Cleanup/permission repair preserves its mode and the private recovery directories.

An administrator can use the signed, root-owned runtime helper for a controlled offline repair:

```bash
sudo /usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_piper_environment.py \
  --wheelhouse /root/sls-reviewed-wheels
```

The wheelhouse and its ancestors must be root protected. Hash verification and binary-only installation remain mandatory. The helper waits for active SLS work to drain before activation. `--workers-paused` is reserved for the authenticated installer that already holds those leases. This option controls Python wheels only; OS packages and missing voice models require their existing installation sources. No web setting can select arbitrary package indexes or wheelhouse paths.

Validation on Debian 12/Python 3.11/amd64: all ten downloaded wheels matched the recorded PyPI hashes; a fresh private environment installed with network indexes disabled, passed `pip check` and exact-version reconciliation, and generated valid 16 kHz speech using the existing Lessac model. Piper synthesis is stochastic; different sample lengths/hashes do not by themselves indicate different words. No production interpreter, configuration or notification was changed by that test.

The October 2 refresh qualifies Piper 1.8.0, ONNX Runtime 1.30.0, pip 26.2.1,
setuptools 84.0.0, wheel 0.48.0, Packaging 26.3 and Protobuf 7.36.2. Offline
hash-checked installation, `pip check`, exact-version validation and two speech
samples per installed Amy/Lessac low/Lessac medium/Ryan voice passed. Outputs
preserve mono 16-bit PCM at each model's original 16 kHz or 22.05 kHz rate, with
bounded duration and no silent or excessive-clipping result. These generated
files were private and were never played through the PBX. Carrier listening
and subjective speech quality remain separate acceptance checks. The environment
measured 217,780,224 allocated bytes and ten selected wheels total 77,802,965 bytes;
installer and UI resource admission use the updated reference. NumPy 2.5.3
requires Python 3.12 and has no CPython 3.11 wheel, so compatible NumPy 2.4.6 is
retained. This update does not change the PBX Python version or saved voice choice.

The replacement workflow also passed a network-isolated offline-wheel test: canonical Python prefix and the generated Piper console entry point survived the real directory exchange, speech generation produced a valid mono 16 kHz WAV, and an invalid package lock preserved the previously validated environment. Fault fixtures cover failed builds, activation rollback, unsupported exchange, concurrent repair and partial cleanup. These tests do not simulate a PBX power failure.
