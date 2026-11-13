/* Native Guacamole display over the authenticated local broker. No alternate transport or credentials. */
(() => {
    'use strict';
    const root = document.getElementById('ssh-terminal');
    if (!root) return;
    const screen = document.getElementById('ssh-terminal-display');
    const status = document.getElementById('ssh-terminal-status');
    const connect = document.getElementById('ssh-connect');
    const disconnect = document.getElementById('ssh-disconnect');
    const test = document.getElementById('ssh-test');
    const audit = document.getElementById('ssh-change-audit');
    const auditForm = document.getElementById('ssh-change-form');
    const auditStatus = document.getElementById('ssh-change-status');
    const token = document.querySelector('#ssh-terminal-token input[name="__csrf_magic"]').value;
    const endpoint = new URL(root.dataset.apiUrl, document.baseURI);
    let active = null;

    function message(text, error = false) {
        status.textContent = text;
        status.dataset.error = String(error);
    }
    function body(action, values = {}) {
        return new URLSearchParams({
            action,
            host_id: root.dataset.hostId,
            __csrf_magic: token,
            ...values,
        });
    }
    async function request(action, values = {}) {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body(action, values),
        });
        if (!response.headers.get('content-type')?.includes('application/json')) {
            throw new Error(
                'Cacti session expired or the console endpoint is unavailable. Reload this page.',
            );
        }
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'SSH console request failed.');
        return data.result;
    }
    function closeAuthorization(context) {
        if (!context.id || context.closeSent) return;
        context.closeSent = true;
        return request('close', { session_id: context.id }).catch((error) => {
            message(error.message, true);
        });
    }
    function stop(context, error) {
        if (context.closed) return;
        context.closed = true;
        context.keyboard?.reset();
        if (context.client) context.client.disconnect();
        context.tunnel.setState(Guacamole.Tunnel.State.CLOSED);
        closeAuthorization(context);
        if (active === context) {
            active = null;
            if (audit) audit.hidden = true;
            connect.disabled = false;
            test.disabled = false;
            disconnect.disabled = true;
            message(error ? error.message : 'Disconnected', Boolean(error));
        }
    }
    function encode(raw) {
        return btoa(
            Array.from(new TextEncoder().encode(raw), (byte) => String.fromCharCode(byte)).join(''),
        );
    }
    function instruction(parts) {
        return (
            parts
                .map((value) => {
                    const text = String(value);
                    return `${Array.from(text).length}.${text}`;
                })
                .join(',') + ';'
        );
    }
    async function read(context) {
        try {
            while (!context.closed) {
                const result = await request('read', { session_id: context.id });
                if (context.closed) break;
                if (!Array.isArray(result.messages)) throw new Error('Invalid console response.');
                for (const item of result.messages) {
                    if (item.type === 'error') throw new Error(item.error);
                    if (item.type === 'guac') {
                        const bytes = Uint8Array.from(atob(item.data), (char) =>
                            char.charCodeAt(0),
                        );
                        context.parser.receive(
                            new TextDecoder('utf-8', { fatal: true }).decode(bytes),
                        );
                    }
                }
            }
        } catch (error) {
            if (!context.closed) stop(context, error);
        }
    }
    function start() {
        if (active) return;
        const context = {
            id: null,
            closed: false,
            closeSent: false,
            queue: '',
            queuedBytes: 0,
            writes: Promise.resolve(),
        };
        active = context;
        connect.disabled = true;
        test.disabled = true;
        disconnect.disabled = false;
        message('Connecting…');
        const tunnel = new Guacamole.Tunnel();
        context.tunnel = tunnel;
        context.parser = new Guacamole.Parser();
        context.parser.oninstruction = (opcode, args) => {
            if (opcode === 'ready') tunnel.setUUID(args[0]);
            else if (tunnel.oninstruction) tunnel.oninstruction(opcode, args);
        };
        tunnel.connect = async () => {
            try {
                const size = {
                    width: Math.max(100, Math.min(4096, screen.clientWidth)),
                    height: Math.max(100, Math.min(2160, screen.clientHeight)),
                };
                const result = await request('open', size);
                if (typeof result.id !== 'string' || !/^[a-f0-9]{64}$/.test(result.id))
                    throw new Error('Invalid console session response.');
                context.id = result.id;
                if (context.closed) {
                    closeAuthorization(context);
                    return;
                }
                tunnel.setState(Guacamole.Tunnel.State.OPEN);
                read(context);
            } catch (error) {
                if (!context.closed) stop(context, error);
            }
        };
        tunnel.disconnect = () => {
            if (!context.closed) stop(context);
        };
        tunnel.sendMessage = (...parts) => {
            if (context.closed || !context.id) return;
            const frame = instruction(parts);
            context.queue += frame;
            context.queuedBytes += new TextEncoder().encode(frame).length;
            if (context.queuedBytes > 131072) {
                stop(context, new Error('Terminal input queue exceeded its limit.'));
                return;
            }
            if (context.flushTimer) return;
            context.flushTimer = setTimeout(() => {
                context.flushTimer = null;
                const raw = context.queue;
                context.queue = '';
                const length = new TextEncoder().encode(raw).length;
                context.writes = context.writes
                    .then(async () => {
                        if (context.closed) return;
                        if (length > 32768)
                            throw new Error('Terminal input exceeds the request limit.');
                        await request('write', { session_id: context.id, data: encode(raw) });
                        context.queuedBytes -= length;
                    })
                    .catch((error) => {
                        if (!context.closed) stop(context, error);
                    });
            }, 10);
        };
        const client = new Guacamole.Client(tunnel);
        context.client = client;
        screen.replaceChildren(client.getDisplay().getElement());
        context.keyboard = new Guacamole.Keyboard(screen);
        context.keyboard.onkeydown = (key) => {
            client.sendKeyEvent(1, key);
            return false;
        };
        context.keyboard.onkeyup = (key) => client.sendKeyEvent(0, key);
        const mouse = new Guacamole.Mouse(client.getDisplay().getElement());
        mouse.onmousedown =
            mouse.onmouseup =
            mouse.onmousemove =
                (state) => client.sendMouseState(state, true);
        client.onerror = (error) => stop(context, new Error(error.message));
        client.onstatechange = (state) => {
            if (context.closed) return;
            if (state === Guacamole.Client.State.CONNECTED) {
                message('Connected');
                if (audit) audit.hidden = false;
                screen.focus();
            }
            if (state === Guacamole.Client.State.DISCONNECTED) stop(context);
        };
        client.connect();
    }
    connect.addEventListener('click', start);
    auditForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const context = active;
        if (!context?.id || context.closed) return;
        const input = auditForm.querySelector('[name="parameter"]');
        const button = auditForm.querySelector('[type="submit"]');
        const parameter = input.value.trim();
        if (context.auditDraft?.parameter !== parameter) {
            context.auditDraft = {
                parameter,
                request_id: Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) =>
                    byte.toString(16).padStart(2, '0'),
                ).join(''),
            };
        }
        button.disabled = true;
        auditStatus.textContent = 'Recording change…';
        try {
            await request('audit', { session_id: context.id, ...context.auditDraft });
            context.auditDraft = null;
            auditForm.reset();
            auditStatus.textContent = 'Change recorded in the device Logs.';
        } catch (error) {
            auditStatus.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
    disconnect.addEventListener('click', () => {
        if (active) stop(active);
    });
    test.addEventListener('click', async () => {
        test.disabled = true;
        connect.disabled = true;
        message('Testing saved credential…');
        try {
            const result = await request('test');
            message(result.message);
        } catch (error) {
            message(error.message, true);
        } finally {
            test.disabled = false;
            connect.disabled = false;
        }
    });
    // Revoke the real session authorization on navigation; the broker also enforces lease and idle deadlines.
    window.addEventListener('pagehide', () => {
        if (active?.id && !active.closed) {
            navigator.sendBeacon(endpoint, body('close', { session_id: active.id }));
        }
    });
})();
