# Signed 0.1.8-beta installation correction

This branch contains the signed AMI installer correction, not a new module version. It fixes native Asterisk 20/custom-build manager detection in installation, repair and restore. The original immutable 0.1.8-beta release assets are unchanged.

Run as root for a fresh installation, upgrade or repeated 0.1.8-beta installation:

```bash
cd "$(mktemp -d /tmp/sls-install.XXXXXXXX)" &&
curl -fsSL -o sls-bootstrap.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/correction-0.1.8-beta/install_bootstrap.sh &&
printf '%s\n' '3039a6b1a877695c1515e672366abde03d2702abb1f9b12f88107ab3128c27fb  sls-bootstrap.sh' | sha256sum --check &&
bash ./sls-bootstrap.sh 0.1.8-beta --correction
```

The bootstrap verifies the signed installer, package and current publisher policy before installation. All four signed release files must come from this bundle. The two verification helpers are checksum-pinned in the bootstrap; no publisher policy is reset.

Package SHA-256: `8d12bc26168a5a7e51ac1366ff6bbfba7afcfebdb9e2cb8a5ebf3e70b0fdecf6`.

[Project documentation](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/tree/main)
