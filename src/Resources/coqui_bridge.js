/**
 * Coqui API Bridge for Love2D Web Export (love.js)
 *
 * Provides window.CoquiAPI with fetch()-based HTTP methods that the Lua
 * coqui_api module can call via JS interop in Emscripten builds.
 *
 * Include this script in the love.js index.html before the game loads.
 */
(function () {
    'use strict';

    const DEFAULT_ENDPOINT = 'http://localhost:3300';

    /** @type {string} */
    let endpoint = DEFAULT_ENDPOINT;

    /** @type {boolean} */
    let debug = false;

    /** @type {Array<{success: boolean, data: any}>} */
    const responseQueue = [];

    /**
     * Configure the bridge endpoint.
     * @param {Object} opts
     * @param {string} [opts.endpoint]
     * @param {boolean} [opts.debug]
     */
    function configure(opts) {
        if (opts.endpoint) endpoint = opts.endpoint;
        if (opts.debug !== undefined) debug = opts.debug;
        if (debug) console.log('[CoquiAPI] Configured:', endpoint);
    }

    /**
     * POST request to the Coqui API.
     * @param {string} path API path
     * @param {string|Object} body JSON string or object
     */
    async function post(path, body) {
        const url = endpoint + path;
        const jsonBody = typeof body === 'string' ? body : JSON.stringify(body);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: jsonBody,
            });

            const data = await response.text();
            responseQueue.push({ success: true, status: response.status, data });

            if (debug) {
                console.log('[CoquiAPI] POST', path, '→', response.status);
            }
        } catch (err) {
            responseQueue.push({ success: false, error: err.message });
            if (debug) {
                console.error('[CoquiAPI] POST failed:', path, err.message);
            }
        }
    }

    /**
     * GET request to the Coqui API.
     * @param {string} path API path
     */
    async function get(path) {
        const url = endpoint + path;

        try {
            const response = await fetch(url);
            const data = await response.text();
            responseQueue.push({ success: true, status: response.status, data });

            if (debug) {
                console.log('[CoquiAPI] GET', path, '→', response.status);
            }
        } catch (err) {
            responseQueue.push({ success: false, error: err.message });
            if (debug) {
                console.error('[CoquiAPI] GET failed:', path, err.message);
            }
        }
    }

    /**
     * Pop the next response from the queue (called from Lua via poll).
     * @returns {Object|null}
     */
    function popResponse() {
        return responseQueue.length > 0 ? responseQueue.shift() : null;
    }

    /**
     * Send a prompt to Coqui (convenience wrapper).
     * @param {string} message
     */
    function sendPrompt(message) {
        post('/api/tasks', JSON.stringify({ prompt: message }));
    }

    /**
     * Send a game event to Coqui (convenience wrapper).
     * @param {string} eventType
     * @param {Object} data
     */
    function sendEvent(eventType, data) {
        post('/api/tasks', JSON.stringify({
            event: eventType,
            data: data || {},
            timestamp: Math.floor(Date.now() / 1000),
        }));
    }

    // Expose global API
    window.CoquiAPI = {
        configure,
        post,
        get,
        popResponse,
        sendPrompt,
        sendEvent,
    };

    if (debug) {
        console.log('[CoquiAPI] Bridge loaded');
    }
})();
