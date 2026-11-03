'use strict';
/** One secure token implementation for LAN HTTP and HTTPS; never use weak randomness. */
function icctRandomToken() {
    if (typeof crypto === 'undefined' || typeof crypto.getRandomValues !== 'function') {
        throw new Error('This browser cannot create a secure token. Use a supported browser.');
    }
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}
