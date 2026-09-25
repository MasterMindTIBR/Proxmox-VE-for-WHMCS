# Console Relay (noVNC)

The Console Relay's source and deployment instructions live in their own
repository: **[junglivre/pvewhmcs-console-relay](https://github.com/junglivre/pvewhmcs-console-relay)**.

It bridges a browser's noVNC WebSocket to Proxmox's `vncwebsocket` API
endpoint, so Proxmox never needs a public IP, a PTR record, or to share a
registrable domain with WHMCS. `pvewhmcs_noVNC()` (in
`modules/servers/pvewhmcs/pvewhmcs.php`) mints the short-lived, HMAC-signed
token that relay verifies — see `pvewhmcs_build_console_token()` in
`modules/addons/pvewhmcs/proxmox.php`.

Clone or Git-deploy that repository (Plesk's Git integration works well for
this) and follow its README. Then configure this module under **Addons >
Proxmox VE for WHMCS > Config**:

- **VNC Secret** — the `vnc@pve` Proxmox user's password.
- **Console Relay Secret** — the same value as the relay's `config.json`.
- **Console Relay Host** / **Console Relay Port** — only if the relay runs
  on its own subdomain instead of sharing the WHMCS domain.

See this repo's main [README "noVNC" section](../../../../README.md#-2-novnc-console-tunnel-client-area)
for the full setup, including the restricted `vnc@pve` Proxmox user.
