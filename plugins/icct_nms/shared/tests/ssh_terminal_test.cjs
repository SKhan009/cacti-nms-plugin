const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { setTimeout: delay } = require('node:timers/promises');
const source = fs.readFileSync(
    require('node:path').join(__dirname, '../../protocols/ssh/js/terminal.js'),
    'utf8',
);
function element() {
    return {
        disabled: false,
        dataset: {},
        clientWidth: 800,
        clientHeight: 600,
        handlers: {},
        addEventListener(name, action) {
            this.handlers[name] = action;
        },
        replaceChildren() {},
        focus() {},
    };
}
function environment(handler) {
    const ids = Object.fromEntries(
        [
            'ssh-terminal',
            'ssh-terminal-display',
            'ssh-terminal-status',
            'ssh-connect',
            'ssh-disconnect',
            'ssh-test',
        ].map((name) => [name, element()]),
    );
    Object.assign(ids['ssh-terminal'].dataset, {
        hostId: '2',
        apiUrl: 'protocols/ssh/controllers/ssh_console.php',
    });
    const requests = [],
        clients = [],
        keyboards = [];
    class Tunnel {
        setState(state) {
            this.state = state;
        }
        setUUID(id) {
            this.uuid = id;
        }
    }
    Tunnel.State = { OPEN: 1, CLOSED: 2 };
    class Client {
        constructor(tunnel) {
            this.tunnel = tunnel;
            clients.push(this);
            tunnel.oninstruction = (opcode) => {
                if (opcode === 'sync') this.onstatechange(Client.State.CONNECTED);
            };
        }
        getDisplay() {
            return { getElement: () => ({}) };
        }
        connect() {
            this.onstatechange(Client.State.WAITING);
            this.tunnel.connect();
        }
        disconnect() {
            this.tunnel.disconnect();
        }
        sendKeyEvent(pressed, key) {
            this.tunnel.sendMessage('key', pressed, key);
        }
        sendMouseState() {}
    }
    Client.State = { WAITING: 2, CONNECTED: 3, DISCONNECTED: 5 };
    class Keyboard {
        constructor() {
            keyboards.push(this);
        }
        reset() {}
    }
    class Parser {
        receive(data) {
            const match = data.match(/\d+\.([a-z]+),/);
            if (match) this.oninstruction(match[1], ['1']);
        }
    }
    const globalHandlers = {};
    vm.runInNewContext(source, {
        document: {
            baseURI: 'https://nms.example/cacti/plugins/icct_nms/',
            getElementById: (id) => ids[id],
            querySelector: () => ({ value: 'csrf-test' }),
        },
        window: {
            addEventListener: (name, action) => {
                globalHandlers[name] = action;
            },
        },
        navigator: { sendBeacon() {} },
        Guacamole: { Tunnel, Client, Keyboard, Parser, Mouse: class {} },
        URL,
        URLSearchParams,
        TextEncoder,
        TextDecoder,
        Uint8Array,
        btoa: (value) => Buffer.from(value, 'binary').toString('base64'),
        atob: (value) => Buffer.from(value, 'base64').toString('binary'),
        setTimeout,
        fetch: async (url, options) => {
            const body = Object.fromEntries(options.body);
            requests.push(body);
            return handler(body, requests);
        },
    });
    return { ids, requests, clients, keyboards, globalHandlers };
}
function response(result, ok = true) {
    return {
        ok,
        headers: { get: () => 'application/json' },
        json: async () => (ok ? { ok: true, result } : { ok: false, error: result }),
    };
}
const pending = () => new Promise(() => {});
(async () => {
    const test = environment((body) =>
        Promise.resolve(response({ message: 'Host key verified and saved credential accepted.' })),
    );
    await test.ids['ssh-test'].handlers.click();
    assert.match(test.ids['ssh-terminal-status'].textContent, /saved credential accepted/);
    assert.equal(test.requests[0].action, 'test');
    assert.equal(test.requests[0].__csrf_magic, 'csrf-test');
    assert.ok(!('secret' in test.requests[0]) && !('monitoring' in test.requests[0]));
    const failure = environment(() => Promise.resolve(response('Host key changed.', false)));
    failure.ids['ssh-connect'].handlers.click();
    await delay(5);
    assert.equal(failure.ids['ssh-terminal-status'].textContent, 'Host key changed.');
    assert.equal(failure.ids['ssh-connect'].disabled, false);
    assert.equal(
        failure.requests.length,
        1,
        'Must not retry with another transport or authentication method',
    );
    let open;
    const cancel = environment((body) =>
        body.action === 'open'
            ? new Promise((resolve) => {
                  open = resolve;
              })
            : Promise.resolve(response({ closed: true })),
    );
    cancel.ids['ssh-connect'].handlers.click();
    cancel.ids['ssh-disconnect'].handlers.click();
    open(response({ id: 'a'.repeat(64) }));
    await delay(5);
    assert.deepEqual(
        cancel.requests.map((r) => r.action),
        ['open', 'close'],
        'Late successful opens must still revoke authorization after cancellation',
    );
    let reads = 0;
    const session = environment((body) => {
        if (body.action === 'open') return Promise.resolve(response({ id: 'b'.repeat(64) }));
        if (body.action === 'read')
            return ++reads === 1
                ? Promise.resolve(
                      response({
                          messages: [
                              { type: 'guac', data: Buffer.from('4.sync,1.1;').toString('base64') },
                          ],
                      }),
                  )
                : pending();
        return Promise.resolve(response({ closed: true }));
    });
    session.ids['ssh-connect'].handlers.click();
    await delay(5);
    assert.equal(session.ids['ssh-terminal-status'].textContent, 'Connected');
    session.keyboards[0].onkeydown(65293);
    session.keyboards[0].onkeyup(65293);
    await delay(20);
    const write = session.requests.find((r) => r.action === 'write');
    assert.equal(
        Buffer.from(write.data, 'base64').toString(),
        '3.key,1.1,5.65293;3.key,1.0,5.65293;',
    );
    session.ids['ssh-disconnect'].handlers.click();
    await delay(5);
    assert.equal(session.ids['ssh-terminal-status'].textContent, 'Disconnected');
    assert.equal(session.requests.filter((r) => r.action === 'close').length, 1);
    console.log(
        'PASS: saved-credential test, CSRF, explicit errors without fallback, canceled-open cleanup, server-driven connection state, ordered keyboard input and disconnect.',
    );
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
