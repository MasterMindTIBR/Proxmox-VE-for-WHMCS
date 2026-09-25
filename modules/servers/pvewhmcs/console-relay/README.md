# Console Relay (noVNC)

Bridges a browser's noVNC WebSocket to Proxmox's `vncwebsocket` API endpoint,
so Proxmox never needs a public IP, a PTR record, or to share a registrable
domain with WHMCS for cookie purposes. Only this relay needs network
reachability to Proxmox on port 8006 — the same reachability the module
already needs for provisioning.

Read `_docs/PROJECT-CONTEXT.md` and the README "noVNC" section in the repo
root before deploying this for the first time.

## How it fits together

Two supported layouts:

```text
Same domain:  Browser --wss--> WHMCS domain (443) --proxy_pass /pve-console-ws/--> relay (127.0.0.1:8765) --wss--> Proxmox:8006 (private)
Subdomain:    Browser --wss--> vnc.example.com (443, Plesk-managed) ------------------------> relay (Plesk app) --wss--> Proxmox:8006 (private)
```

`pvewhmcs_noVNC()` (in `modules/servers/pvewhmcs/pvewhmcs.php`) mints a
short-lived, HMAC-signed, single-use token describing the Proxmox target
(host, path, the restricted `vnc@pve` PVEAuthCookie). The browser only ever
sees that opaque token. This relay verifies it, opens the real connection to
Proxmox, and pipes bytes both ways.

If you deploy the relay on its own subdomain (recommended — no reverse-proxy
config needed, see Option A below), set that subdomain in WHMCS under
**Addons > Proxmox VE for WHMCS > Config > Console Relay Host** (and
**Console Relay Port** only if it isn't the default `443`). Leave both blank
to keep sharing the WHMCS domain instead.

## 1. Deploy the relay

### Option A: Plesk Node.js extension (recommended)

**Dedicated subdomain (e.g. `vnc.example.com`) — no reverse-proxy config needed:**

1. In Plesk, create the subdomain, then add a Node.js application against it
   with **Document Root** pointed at this `console-relay` directory.
2. Set **Application Startup File** to `server.js` and **Application Mode**
   to `production`.
3. Run `npm install` via Plesk's "NPM install" button.
4. Copy `config.example.json` to `config.json` in this same directory and
   fill in `secret` with the **exact same value** you set in WHMCS under
   **Addons > Proxmox VE for WHMCS > Config > Console Relay Secret**.
   Generate it with:

   ```bash
   openssl rand -hex 32
   ```

5. Start/restart the application from Plesk. Plesk terminates HTTPS for the
   subdomain and forwards everything (including the WebSocket upgrade) to
   this app — skip Section 2 entirely.
6. In WHMCS, set **Console Relay Host** to `vnc.example.com` (the subdomain
   you just created). Leave **Console Relay Port** blank unless Plesk serves
   that subdomain on a non-standard HTTPS port.
7. Confirm it's alive: `curl https://vnc.example.com/healthz` should return
   `ok`.

**Sharing the existing WHMCS domain instead** (subpath, needs Section 2):

1. Create the Node.js application against the WHMCS domain with a distinct
   **Document Root** pointed at this `console-relay` directory.
2. Follow steps 2-4 above.
3. Leave **Console Relay Host**/**Console Relay Port** blank in WHMCS (it
   falls back to the WHMCS domain automatically).
4. Continue to Section 2 to wire up the reverse-proxy path.

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

## 2. Make it reachable at `/pve-console-ws/` (only if sharing the WHMCS domain)

Skip this section entirely if you deployed to a dedicated subdomain
(Option A's first path above) — Plesk already routes that whole subdomain,
WebSocket upgrade included, straight to the app.

If instead you're sharing the WHMCS domain, the relay itself only listens
on `127.0.0.1`; it is never exposed directly. The WHMCS domain's own web
server must reverse-proxy that one path prefix to it, passing the WebSocket
upgrade through untouched.

In Plesk: **Domains > (your WHMCS domain) > Apache & nginx Settings >
Additional nginx directives**, add:

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
   `wss://<console-relay-host>/pve-console-ws/<token>` — where
   `<console-relay-host>` is either your dedicated subdomain (Console Relay
   Host in Module Config) or the WHMCS domain if you left that blank. Check
   DevTools' Network tab; it must **not** be a direct connection to the
   Proxmox host.
3. If it fails immediately with code `4401`, the token was invalid/expired —
   check the relay's log (`console.log` output, captured by Plesk or
   `journalctl -u pvewhmcs-console-relay`) and confirm both secrets match
   exactly.
4. If the WebSocket never reaches `open` and you're sharing the WHMCS
   domain, the reverse proxy likely isn't passing the `Upgrade` header
   through — re-check Section 2.

## Security notes

- Rotate `secret` by updating it in both places (WHMCS Module Config and
  `config.json`) — old, in-flight tokens simply stop validating.
- The relay never touches the WHMCS database or PVE credentials beyond what
  each token carries; a leaked relay log line still requires the signed
  token to reconnect, and tokens expire in under a minute.
- Keep `listenPort` bound to `127.0.0.1` (already the default) so it is only
  reachable through the reverse proxy, never directly from the Internet.
