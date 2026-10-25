<details class="sls-postfix-help">
    <summary><i class="fa fa-question-circle text-primary" aria-hidden="true"></i> <?php echo _('How email is delivered'); ?></summary>
    <p><?php echo _('Postfix manages email on the PBX. SLS submits each message to its local sendmail service. A webhook sends an HTTPS request to another service; it is separate from email.'); ?></p>
    <p><?php echo _('For an SMTP relay, first inspect the existing configuration. Use your mail provider’s relay hostname and credentials; preserve settings already used by the PBX.'); ?></p>
    <pre>sudo postconf -n
sudo nano /etc/postfix/main.cf</pre>
    <p><?php echo _('Typical authenticated relay settings for port 587:'); ?></p>
    <pre>relayhost = [smtp.provider.example]:587
smtp_sasl_auth_enable = yes
smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd
smtp_sasl_security_options = noanonymous
smtp_tls_security_level = secure
smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt</pre>
    <p><?php echo _('Create the credential file if it is missing, then protect it before opening nano. Preserve existing relay entries. Add or update your relay line: [smtp.provider.example]:587 username:password. Replace the example values with your provider’s settings, then load the map with a private umask:'); ?></p>
    <pre>sudo sh -c 'umask 077; touch /etc/postfix/sasl_passwd'
sudo chown root:root /etc/postfix/sasl_passwd
sudo chmod 600 /etc/postfix/sasl_passwd
sudo nano /etc/postfix/sasl_passwd
sudo sh -c 'umask 077; postmap /etc/postfix/sasl_passwd'
sudo chmod 600 /etc/postfix/sasl_passwd.db
sudo postfix check
sudo postfix reload</pre>
    <p><?php echo _('Review /var/log/mail.log or journalctl -u postfix for rejected or deferred messages. “Accepted by PBX mail service” means queued locally; check the mail log for relay acceptance.'); ?></p>
    <a href="https://www.postfix.org/SASL_README.html#client_sasl" target="_blank" rel="noopener noreferrer"><?php echo _('Postfix relay authentication documentation'); ?> <i class="fa fa-external-link" aria-hidden="true"></i></a>
</details>
