# Console Relay (noVNC)

Bridges a browser's noVNC WebSocket to Proxmox's `vncwebsocket` API endpoint,
so Proxmox never needs a public IP, a PTR record, or to share a registrable
domain with WHMCS for cookie purposes. Only this relay needs network
reachability to Proxmox on port 8006 — the same reachability the module
already needs for provisioning.

Read `_docs/PROJECT-CONTEXT.md` and the README "noVNC" section in the repo
root before deploying this for the first time.

## How it fits together

```text
Browser --wss--> WHMCS domain (443) --proxy_pass--> this relay (127.0.0.1:8765) --wss--> Proxmox:8006 (private)
```

`pvewhmcs_noVNC()` (in `modules/servers/pvewhmcs/pvewhmcs.php`) mints a
short-lived, HMAC-signed, single-use token describing the Proxmox target
(host, path, the restricted `vnc@pve` PVEAuthCookie). The browser only ever
sees that opaque token. This relay verifies it, opens the real connection to
Proxmox, and pipes bytes both ways.

## 1. Deploy the relay

### Option A: Plesk Node.js extension (recommended)

1. In Plesk, create the Node.js application against a domain/subdomain — it
   can be the same domain WHMCS runs on, using a distinct **Document Root**
   pointed at this `console-relay` directory (Plesk supports a Node.js app
   under a subpath of an existing PHP-hosted domain).
2. Set **Application Startup File** to `server.js`.
3. Set the **Application Mode** to `production`.
4. Run `npm install` via Plesk's "NPM install" button (installs the `ws`
   dependency into `node_modules/`, which stays local to this directory and
   is never committed to Git).
5. Copy `config.example.json` to `config.json` in this same directory and
   fill in `secret` with the **exact same value** you set in WHMCS under
   **Addons > Proxmox VE for WHMCS > Config > Console Relay Secret**. Generate
   it with:

   ```bash
   openssl rand -hex 32
   ```

6. Start/restart the application from Plesk.
7. Confirm it's alive: `curl http://127.0.0.1:8765/healthz` should return `ok`
   (the exact port is whatever Plesk assigned/you set in `config.json`;
   check the app's log for the actual bound port on first boot).

### Option B: systemd (no Plesk Node.js extension)

```bash
cd /var/www/vhosts/<your-whmcs-domain>/modules/servers/pvewhmcs/console-relay
cp config.example.json config.json
# edit config.json: paste the same secret as the WHMCS Module Config
npm install --omit=dev
```

Create `/etc/systemd/system/pvewhmcs-console-relay.service`:

```ini
[Unit]
Description=Proxmox VE for WHMCS - Console Relay
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/vhosts/<your-whmcs-domain>/modules/servers/pvewhmcs/console-relay
ExecStart=/usr/bin/node server.js
Restart=on-failure
User=<the WHMCS vhost's system user, NOT root>

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now pvewhmcs-console-relay
```

## 2. Make it reachable at `/pve-console-ws/`

The relay itself only listens on `127.0.0.1`; it is never exposed directly.
The WHMCS domain's own web server must reverse-proxy that one path prefix to
it, passing the WebSocket upgrade through untouched.

If you used Plesk's Node.js extension against a dedicated
subdomain/subpath (Option A above), Plesk already wires this up for you —
skip this step.

Otherwise, in Plesk: **Domains > (your WHMCS domain) > Apache & nginx
Settings > Additional nginx directives**, add:

```nginx
location /pve-console-ws/ {
    proxy_pass http://127.0.0.1:8765;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

Adjust the port to match `listenPort` in `config.json`.

## 3. Verify

1. In WHMCS Client Area, request a console for an active VM/CT.
2. Click "Launch noVNC". The browser should connect to
   `wss://<your-whmcs-domain>/pve-console-ws/<token>` — check DevTools'
   Network tab, not a direct connection to the Proxmox host.
3. If it fails immediately with code `4401`, the token was invalid/expired —
   check the relay's log (`console.log` output, captured by Plesk or
   `journalctl -u pvewhmcs-console-relay`) and confirm both secrets match
   exactly.
4. If the WebSocket never reaches `open`, the reverse proxy likely isn't
   passing the `Upgrade` header through — re-check step 2.

## Security notes

- Rotate `secret` by updating it in both places (WHMCS Module Config and
  `config.json`) — old, in-flight tokens simply stop validating.
- The relay never touches the WHMCS database or PVE credentials beyond what
  each token carries; a leaked relay log line still requires the signed
  token to reconnect, and tokens expire in under a minute.
- Keep `listenPort` bound to `127.0.0.1` (already the default) so it is only
  reachable through the reverse proxy, never directly from the Internet.
