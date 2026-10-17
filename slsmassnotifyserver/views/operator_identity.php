<?php
$e=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$data=['endpoint'=>$endpoint,'csrf'=>$csrf,'callback_url'=>$callback,'identity'=>$identity,'revisions'=>$revisions,'accounts'=>$accounts];
?>
<link rel="stylesheet" href="modules/slsmassnotifyserver/views/identity_labs.css?v=<?= $e(substr(hash_file('sha256',__DIR__.'/identity_labs.css'),0,16)) ?>">
<section id="enterprise-identity-labs" class="sls-enterprise-identity">
<h3><i class="fa fa-key" aria-hidden="true"></i> Operator single sign-on <?php include __DIR__.'/labs.php'; ?></h3>
<p class="alert alert-warning"><strong>Labs: DO NOT USE ON PRODUCTION SERVERS.</strong> Provider sign-in requires deployment testing. SSO is disabled by default.</p>
<p>SSO signs users into the SLS operator dashboard at <code>/mass-notify/</code>. It does not change FreePBX or UCP sign-in. Each identity must be bound to an existing operator account; its saved role, recipients, expiration and authenticator still apply.</p>
<p>Keep an enrolled local operator administrator for recovery. Blank secret fields preserve saved credentials in the encrypted central configuration.</p>
<div role="status" aria-live="polite" data-identity-status></div>
<details open class="sls-identity-login"><summary><strong>Operator single sign-on (SSO)</strong></summary>
<p>Configure Entra ID, Okta, Google Workspace, Keycloak or OneLogin with OIDC code + S256 PKCE or SAML signed assertions. Register the displayed HTTPS callback exactly. It is derived from the protected advertised Control API address. Secrets saved in the encrypted central configuration are shown blank here; blank preserves a saved secret.</p>
<p>OIDC uses exact issuer, audience and nonce checks with an explicit endpoint hostname allowlist. Entra requires a single tenant UUID. Google requires a Workspace hosted domain. SAML requires pinned IdP certificates and matching SP RSA certificate/private key. Use a persistent NameID where the provider supports it.</p>
<div class="form-inline"><label>Provider <select data-provider-vendor><option value="entra">Entra ID</option><option value="okta">Okta</option><option value="google">Google Workspace</option><option value="keycloak">Keycloak</option><option value="onelogin">OneLogin</option></select></label> <label>Protocol <select data-provider-protocol><option value="oidc">OIDC</option><option value="saml">SAML</option></select></label> <button type="button" class="btn btn-default" data-add-provider>Add disabled provider</button></div>
<p>Available local account bindings:</p><ul><?php foreach($accounts as $account):?><li><code><?=$e($account['id'])?></code> — <?=$e($account['username'])?> (<?=$e($account['role'])?>)</li><?php endforeach;?></ul>
<div data-identity-providers></div><h4>Account bindings</h4><p>Bind an exact provider subject to an existing local account. Roles and recipient permissions come from that local account.</p><div data-identity-grants></div><button type="button" class="btn btn-default btn-sm" data-add-grant>Add account binding</button><details><summary>Advanced identity JSON</summary><label for="identity-config-json">Enterprise identity configuration (JSON)</label><textarea id="identity-config-json" data-config="identity" rows="14" class="form-control" spellcheck="false"><?=$e(json_encode($identity,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></textarea></details>
<p><button type="button" class="btn btn-primary" data-save-config="identity">Save operator SSO settings</button></p>
</details>
<script type="application/json" data-identity-bootstrap><?=json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)?></script>
<script><?=file_get_contents(__DIR__.'/identity_labs.js')?></script>
</section>
