<?php
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$data=['endpoint'=>$endpoint,'csrf'=>$csrf,'identity'=>$identity,'directory'=>$directory,'subscriber'=>$subscriber,'revisions'=>$revisions,'accounts'=>$accounts,'templates'=>$templates];
?>
<section id="enterprise-identity-labs" class="sls-enterprise-identity">
<p class="alert alert-warning"><strong>Labs: DO NOT USE ON PRODUCTION SERVERS.</strong> Enterprise login, directory synchronization and browser responses require a reviewed deployment and provider acceptance tests. Every feature starts disabled.</p>
<p>Local operator accounts grant all roles and recipient assignments. Keep an enrolled local administrator and FreePBX recovery access. Enterprise login cannot create an operator or infer an administrator role from claims.</p>
<div role="status" aria-live="polite" data-identity-status></div>
<details open><summary><strong>Enterprise operator login</strong></summary>
<p>Configure Entra ID, Okta, Google Workspace, Keycloak or OneLogin with OIDC code + S256 PKCE or SAML signed assertions. Register the exact HTTPS callback ending <code>/mass-notify/sso.php</code>. Secrets saved in the encrypted central configuration are shown blank here; blank preserves a saved secret.</p>
<p>OIDC uses exact issuer, audience and nonce checks with an explicit endpoint hostname allowlist. Entra requires a single tenant UUID. Google requires a Workspace hosted domain. SAML requires pinned IdP certificates and matching SP RSA certificate/private key. Use a persistent NameID where the provider supports it.</p>
<div class="form-inline"><label>Provider <select data-provider-vendor><option value="entra">Entra ID</option><option value="okta">Okta</option><option value="google">Google Workspace</option><option value="keycloak">Keycloak</option><option value="onelogin">OneLogin</option></select></label> <label>Protocol <select data-provider-protocol><option value="oidc">OIDC</option><option value="saml">SAML</option></select></label> <button type="button" class="btn btn-default" data-add-provider>Add disabled provider</button></div>
<p>Available local account bindings:</p><ul><?php foreach($accounts as $account):?><li><code><?=$e($account['id'])?></code> — <?=$e($account['username'])?> (<?=$e($account['role'])?>)</li><?php endforeach;?></ul>
<label for="identity-config-json">Enterprise identity configuration (JSON)</label><textarea id="identity-config-json" data-config="identity" rows="14" class="form-control" spellcheck="false"><?=$e(json_encode($identity,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></textarea>
<p><button type="button" class="btn btn-primary" data-save-config="identity">Save enterprise login settings</button></p>
</details>
<details><summary><strong>Directory and roster synchronization</strong></summary>
<p>CSV, LDAP over verified TLS, and SCIM 2.0 read-only pull connectors import stable external IDs. A dry run lists adds, updates and deprovisioning before a one-use apply. Applying changes future incident templates; existing incident and announcement snapshots retain their original roster. Failed or partial remote results cannot deprovision people.</p>
<p>CSV header: <code>external_id,name,email,location,location_id,desktop_username,active</code>. Only external_id/name are required. Never reuse an external ID for a different person. LDAP maps an immutable attribute such as entryUUID or objectGUID; Active Directory filters should explicitly exclude disabled accounts, or configure the active attribute/inactive values. SCIM reads <code>/Users</code> with paging and does not expose a provisioning server.</p>
<p>Available incident templates:</p><ul><?php foreach($templates as $t):?><li><code><?=$e($t['id'])?></code> — <?=$e($t['name'])?></li><?php endforeach;?></ul>
<label for="directory-config-json">Directory configuration (JSON)</label><textarea id="directory-config-json" data-config="directory" rows="12" class="form-control" spellcheck="false"><?=$e(json_encode($directory,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></textarea>
<p><select data-source-type><option value="csv">CSV</option><option value="ldap">LDAP TLS</option><option value="scim">SCIM pull</option></select> <button type="button" class="btn btn-default" data-add-source>Add disabled source</button> <button type="button" class="btn btn-primary" data-save-config="directory">Save directory settings</button></p>
<label>Source ID<input class="form-control" data-preview-source maxlength="28" placeholder="dir_…"></label><label>CSV file (CSV sources only)<input type="file" data-csv-file accept=".csv,text/csv"></label>
<p><button type="button" class="btn btn-default" data-preview-directory>Run dry run</button> <button type="button" class="btn btn-default" data-preview-deprovision>Preview all source deprovisioning</button> <button type="button" class="btn btn-warning" data-apply-directory disabled>Apply reviewed preview</button></p>
<pre data-directory-diff aria-live="polite"></pre>
</details>
<details><summary><strong>Verified email browser responses</strong></summary>
<p>Administrators manage subscriber people and explicitly invite one person to one open incident. An expiring one-time link verifies possession of the saved email address and opens only that participant’s response form. People must be registered before the incident opens; later identity changes cannot answer an older roster snapshot. Received, safe and needs help remain human responses, separate from delivery receipts.</p>
<label for="subscriber-config-json">Subscriber configuration (JSON)</label><textarea id="subscriber-config-json" data-config="subscriber" rows="12" class="form-control" spellcheck="false"><?=$e(json_encode($subscriber,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></textarea>
<p><button type="button" class="btn btn-default" data-add-subscriber>Add disabled person</button> <button type="button" class="btn btn-primary" data-save-config="subscriber">Save subscriber people</button></p>
<label>Subscriber ID<input class="form-control" data-invite-subscriber maxlength="28" placeholder="sub_…"></label><label>Open incident ID<input class="form-control" data-invite-incident maxlength="36" placeholder="inc_…"></label>
<p><button type="button" class="btn btn-warning" data-invite-subscriber-button>Send one verification email</button> <button type="button" class="btn btn-default" data-revoke-subscriber>Revoke links and browser sessions</button></p>
<p>There is no public signup, cross-participant roster browsing, SMS login, managed mustering, visitor tracking, geofencing or mobile push.</p>
</details>
<script type="application/json" data-identity-bootstrap><?=json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?></script>
<script><?=file_get_contents(__DIR__.'/identity_labs.js')?></script>
</section>
