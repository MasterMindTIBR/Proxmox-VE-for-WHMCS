// pvewhmcs: WHMCS integration of the console page (tab title, favicon, reconnect). The hooks live in ui.js; re-apply both on noVNC bumps.
import * as WebUtil from './webutil.js';

const TEXT = {
    pt: {
        lost: 'Conexão perdida. A VM pode ter sido migrada ou reiniciada.',
        expired: 'A sessão do console expirou.',
        failed: 'Não foi possível conectar ao console.',
        reconnect: 'Reconectar',
        auto: 'Reconectando em %s s...',
    },
    en: {
        lost: 'Connection lost. The VM may have been migrated or restarted.',
        expired: 'The console session has expired.',
        failed: 'Could not connect to the console.',
        reconnect: 'Reconnect',
        auto: 'Reconnecting in %s s...',
    },
};
const lang = /^pt/i.test(WebUtil.getConfigVar('pvewhmcs_lang', '') || navigator.language) ? 'pt' : 'en';
const AUTO_KEY = 'pvewhmcs_auto_reconnect';

// Same-origin only: the fragment can be edited, and the button must not navigate elsewhere.
function sameOrigin(value) {
    try {
        const url = new URL(value, window.location.href);
        return url.origin === window.location.origin ? url.href : null;
    } catch (e) {
        return null;
    }
}

export function title() {
    return WebUtil.getConfigVar('pvewhmcs_title', null);
}

export function init() {
    if (title()) {
        document.title = title();
    }
    const icon = sameOrigin(WebUtil.getConfigVar('pvewhmcs_icon', '') || '');
    if (icon) {
        document.querySelector('link[rel="icon"]').href = icon;
    }
}

export function connected() {
    sessionStorage.removeItem(AUTO_KEY);
}

// reason: "lost" (dropped after connecting), "expired" (relay 4408) or "failed" (never connected).
export function lost(reason) {
    const url = sameOrigin(WebUtil.getConfigVar('pvewhmcs_reconnect', '') || '');
    if (!url) {
        return;
    }
    const text = TEXT[lang];
    let box = document.getElementById('pvewhmcs_lost');
    if (!box) {
        box = document.createElement('div');
        box.id = 'pvewhmcs_lost';
        box.style.cssText = 'position:fixed;left:50%;top:40%;transform:translate(-50%,-50%);z-index:1000;max-width:90%;padding:20px 24px;border-radius:8px;background:#fff;color:#222;box-shadow:0 4px 24px rgba(0,0,0,.4);font:15px sans-serif;text-align:center';
        document.body.appendChild(box);
    }
    box.textContent = '';
    const msg = document.createElement('p');
    msg.style.margin = '0 0 14px';
    msg.textContent = text[reason];
    const note = document.createElement('p');
    note.style.cssText = 'margin:0 0 14px;color:#666;font-size:13px';
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = text.reconnect;
    button.style.cssText = 'padding:8px 20px;border:0;border-radius:4px;background:#1a5fd6;color:#fff;font-size:15px;cursor:pointer';
    button.addEventListener('click', () => window.location.replace(url));
    box.append(msg, note, button);

    // One automatic attempt per drop; a second failure in a row waits for the button.
    if (reason === 'lost' && !sessionStorage.getItem(AUTO_KEY)) {
        sessionStorage.setItem(AUTO_KEY, '1');
        let seconds = 3;
        note.textContent = text.auto.replace('%s', seconds);
        const timer = setInterval(() => {
            seconds -= 1;
            note.textContent = text.auto.replace('%s', seconds);
            if (seconds <= 0) {
                clearInterval(timer);
                window.location.replace(url);
            }
        }, 1000);
    }
}
