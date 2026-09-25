'use strict';

/*
    Proxmox VE for WHMCS - Console Relay (noVNC)
    https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
    File: modules/servers/pvewhmcs/console-relay/server.js

    Copyright (C) The Network Crew Pty Ltd (TNC) & Co.

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

/*
 * Bridges a browser's noVNC WebSocket to Proxmox's own
 * /api2/json/nodes/.../vncwebsocket endpoint, so that:
 *   - Proxmox never needs a public IP; only this relay needs network
 *     reachability to it (the same reachability pvewhmcs.php already
 *     needs for provisioning).
 *   - PVEAuthCookie never reaches the browser: this process presents it
 *     to Proxmox itself, on the outbound connection.
 *   - The browser only ever talks to the WHMCS domain, so there is no
 *     PTR, same-registrable-domain, or cross-domain cookie requirement.
 *
 * pvewhmcs.php mints a short-lived, HMAC-signed, single-use token
 * (see pvewhmcs_build_console_token() in proxmox.php) describing the
 * upstream Proxmox target. This relay verifies that token, connects
 * upstream, and pipes frames both ways until either side closes.
 */

const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const path = require('path');
const WebSocket = require('ws');

function loadConfig() {
    const configPath = process.env.PVEWHMCS_RELAY_CONFIG
        || path.join(__dirname, 'config.json');
    const raw = fs.readFileSync(configPath, 'utf8');
    const config = JSON.parse(raw);

    if (!config.secret || String(config.secret).length < 32) {
        throw new Error('config.json: "secret" must be set and at least 32 characters, matching the WHMCS Module Config "Console Relay Secret".');
    }
    config.listenPort = Number(config.listenPort) || 8765;
    config.pathPrefix = String(config.pathPrefix || '/pve-console-ws').replace(/\/+$/, '');
    config.maxSessionSeconds = Number(config.maxSessionSeconds) || 7200;

    return config;
}

function base64UrlDecode(input) {
    let normalized = input.replace(/-/g, '+').replace(/_/g, '/');
    while (normalized.length % 4 !== 0) {
        normalized += '=';
    }

    return Buffer.from(normalized, 'base64');
}

/**
 * Verifies token structure/signature/expiry only. Single-use replay
 * protection happens in the caller, which tracks the "sid" once the
 * signature is confirmed valid.
 */
function verifyToken(token, secret) {
    if (typeof token !== 'string' || token.indexOf('.') === -1) {
        throw new Error('malformed token');
    }

    const separatorIndex = token.lastIndexOf('.');
    const encoded = token.slice(0, separatorIndex);
    const signature = token.slice(separatorIndex + 1);

    const expected = crypto.createHmac('sha256', secret).update(encoded).digest('hex');
    const expectedBuffer = Buffer.from(expected, 'hex');
    const providedBuffer = Buffer.from(String(signature), 'hex');
    if (expectedBuffer.length !== providedBuffer.length || !crypto.timingSafeEqual(expectedBuffer, providedBuffer)) {
        throw new Error('signature mismatch');
    }

    const payload = JSON.parse(base64UrlDecode(encoded).toString('utf8'));
    if (typeof payload.exp !== 'number' || payload.exp < Math.floor(Date.now() / 1000)) {
        throw new Error('token expired');
    }
    if (!payload.host || !payload.path || !payload.cookie || !payload.sid) {
        throw new Error('token missing required fields');
    }

    return payload;
}

function createSeenSidCache() {
    const seen = new Map();

    setInterval(() => {
        const now = Math.floor(Date.now() / 1000);
        for (const [sid, expiry] of seen) {
            if (expiry < now) {
                seen.delete(sid);
            }
        }
    }, 60000).unref();

    return {
        consume(sid, expiry) {
            if (seen.has(sid)) {
                return false;
            }
            seen.set(sid, expiry);

            return true;
        },
    };
}

function createRelay(config) {
    const httpServer = http.createServer((req, res) => {
        if (req.url === '/healthz') {
            res.writeHead(200, { 'Content-Type': 'text/plain' });
            res.end('ok');

            return;
        }
        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('Not found');
    });

    const wss = new WebSocket.Server({ noServer: true });
    const seenSids = createSeenSidCache();

    httpServer.on('upgrade', (req, socket, head) => {
        if (!req.url || req.url.indexOf(config.pathPrefix + '/') !== 0) {
            socket.destroy();

            return;
        }
        wss.handleUpgrade(req, socket, head, (ws) => {
            wss.emit('connection', ws, req);
        });
    });

    wss.on('connection', (clientWs, req) => {
        const token = req.url.slice(config.pathPrefix.length + 1);
        let payload;
        try {
            payload = verifyToken(token, config.secret);
            if (!seenSids.consume(payload.sid, payload.exp)) {
                throw new Error('token already used');
            }
        } catch (err) {
            log('reject', { reason: err.message });
            clientWs.close(4401, 'unauthorized');

            return;
        }

        const upstreamUrl = `wss://${payload.host}:${payload.port || 8006}/${payload.path}`;
        const upstream = new WebSocket(upstreamUrl, {
            headers: { Cookie: 'PVEAuthCookie=' + payload.cookie },
            rejectUnauthorized: payload.verify !== false,
            handshakeTimeout: 10000,
        });

        const pending = [];
        let upstreamOpen = false;

        const closeBoth = (code, reason) => {
            try { clientWs.close(code, reason); } catch (e) { /* already closed */ }
            try { upstream.close(); } catch (e) { /* already closed */ }
        };

        const sessionTimer = setTimeout(() => {
            log('session-timeout', { sid: payload.sid });
            closeBoth(4408, 'session timeout');
        }, config.maxSessionSeconds * 1000);
        sessionTimer.unref();

        upstream.on('open', () => {
            upstreamOpen = true;
            for (const buffered of pending.splice(0)) {
                upstream.send(buffered);
            }
            log('connected', { sid: payload.sid, host: payload.host });
        });

        upstream.on('message', (data) => {
            if (clientWs.readyState === WebSocket.OPEN) {
                clientWs.send(data);
            }
        });
        upstream.on('close', () => {
            clearTimeout(sessionTimer);
            closeBoth(1000, 'upstream closed');
        });
        upstream.on('error', (err) => {
            log('upstream-error', { sid: payload.sid, message: err.message });
            clearTimeout(sessionTimer);
            closeBoth(1011, 'upstream error');
        });

        clientWs.on('message', (data) => {
            if (upstreamOpen) {
                upstream.send(data);
            } else {
                pending.push(data);
            }
        });
        clientWs.on('close', () => {
            clearTimeout(sessionTimer);
            try { upstream.close(); } catch (e) { /* already closed */ }
        });
        clientWs.on('error', () => {
            clearTimeout(sessionTimer);
            try { upstream.close(); } catch (e) { /* already closed */ }
        });
    });

    return httpServer;
}

function log(event, fields) {
    console.log(JSON.stringify(Object.assign({ ts: new Date().toISOString(), event }, fields)));
}

if (require.main === module) {
    const config = loadConfig();
    const server = createRelay(config);
    server.listen(config.listenPort, '127.0.0.1', () => {
        log('listening', { port: config.listenPort, pathPrefix: config.pathPrefix });
    });
}

module.exports = { loadConfig, verifyToken, base64UrlDecode, createRelay, createSeenSidCache };
