--- Coqui API Bridge for Love2D
--- Provides bidirectional communication between Love2D games and the Coqui bot.
--- Auto-detects native (luasocket) vs web (love.js fetch) runtime.
---
--- Usage:
---   local coqui = require('lib.coqui_api')
---   coqui.configure({ endpoint = 'http://localhost:3300' })
---   coqui.sendPrompt('Hello from the game!')
---
--- @module coqui_api

local coqui_api = {}

-- Configuration
local config = {
    endpoint = 'http://localhost:3300',
    timeout = 5,
    debug = false,
    enabled = true,
}

-- Runtime detection
local isWeb = false
local hasSocket = false
local hasThread = false

-- Try to detect environment
do
    -- Check if we're running under love.js (Emscripten)
    if love and love.system then
        local os_name = love.system.getOS()
        if os_name == 'Web' or os_name == 'Emscripten' then
            isWeb = true
        end
    end

    -- Check for luasocket (native only)
    if not isWeb then
        local ok, _ = pcall(require, 'socket.http')
        hasSocket = ok
        local ok2, _ = pcall(function() return love.thread end)
        hasThread = ok2
    end
end

-- ── Configuration ───────────────────────────────────────────────────

--- Configure the Coqui API bridge.
--- @param opts table Configuration table
---   - endpoint (string): API base URL (default: http://localhost:3300)
---   - timeout (number): Request timeout in seconds (default: 5)
---   - debug (boolean): Enable debug logging (default: false)
---   - enabled (boolean): Enable/disable the bridge (default: true)
function coqui_api.configure(opts)
    if type(opts) ~= 'table' then return end

    if opts.endpoint then config.endpoint = tostring(opts.endpoint) end
    if opts.timeout then config.timeout = tonumber(opts.timeout) or 5 end
    if opts.debug ~= nil then config.debug = opts.debug end
    if opts.enabled ~= nil then config.enabled = opts.enabled end

    if config.debug then
        print('[CoquiAPI] Configured — endpoint: ' .. config.endpoint)
        print('[CoquiAPI] Runtime: ' .. (isWeb and 'web' or 'native'))
        if not isWeb then
            print('[CoquiAPI] Socket: ' .. (hasSocket and 'yes' or 'no'))
            print('[CoquiAPI] Thread: ' .. (hasThread and 'yes' or 'no'))
        end
    end
end

--- Get current configuration.
--- @return table Current config values
function coqui_api.getConfig()
    return {
        endpoint = config.endpoint,
        timeout = config.timeout,
        debug = config.debug,
        enabled = config.enabled,
        runtime = isWeb and 'web' or 'native',
        hasSocket = hasSocket,
        hasThread = hasThread,
    }
end

-- ── Internal: Simple JSON Encoding ──────────────────────────────────

local function jsonEncode(val)
    if type(val) == 'table' then
        -- Check if array
        local isArray = #val > 0
        local parts = {}
        if isArray then
            for _, v in ipairs(val) do
                table.insert(parts, jsonEncode(v))
            end
            return '[' .. table.concat(parts, ',') .. ']'
        else
            for k, v in pairs(val) do
                table.insert(parts, '"' .. tostring(k) .. '":' .. jsonEncode(v))
            end
            return '{' .. table.concat(parts, ',') .. '}'
        end
    elseif type(val) == 'string' then
        return '"' .. val:gsub('\\', '\\\\'):gsub('"', '\\"'):gsub('\n', '\\n'):gsub('\r', '\\r') .. '"'
    elseif type(val) == 'boolean' then
        return val and 'true' or 'false'
    elseif type(val) == 'nil' then
        return 'null'
    else
        return tostring(val)
    end
end

-- ── Internal: HTTP Transports ───────────────────────────────────────

--- Native HTTP POST using luasocket (runs on a background thread).
--- @param path string API path (e.g., '/api/tasks')
--- @param body string JSON body
--- @param callback function Called with (success, response) when done
local function httpPostNative(path, body, callback)
    if not hasSocket then
        if callback then callback(false, 'luasocket not available') end
        return
    end

    if hasThread then
        -- Run HTTP request on a background thread to avoid blocking the game loop
        local threadCode = [[
            local url, body, timeout = ...
            local http = require('socket.http')
            local ltn12 = require('ltn12')

            http.TIMEOUT = timeout
            local response = {}
            local ok, status = http.request({
                url = url,
                method = 'POST',
                headers = {
                    ['Content-Type'] = 'application/json',
                    ['Content-Length'] = tostring(#body),
                },
                source = ltn12.source.string(body),
                sink = ltn12.sink.table(response),
            })

            local channel = love.thread.getChannel('coqui_api_response')
            if ok then
                channel:push({ success = true, status = status, body = table.concat(response) })
            else
                channel:push({ success = false, error = tostring(status) })
            end
        ]]

        local thread = love.thread.newThread(threadCode)
        thread:start(config.endpoint .. path, body, config.timeout)

        if config.debug then
            print('[CoquiAPI] Request sent to ' .. path .. ' (threaded)')
        end
    else
        -- Fallback: blocking request (not recommended but functional)
        local http = require('socket.http')
        local ltn12 = require('ltn12')

        http.TIMEOUT = config.timeout
        local response = {}
        local ok, status = http.request({
            url = config.endpoint .. path,
            method = 'POST',
            headers = {
                ['Content-Type'] = 'application/json',
                ['Content-Length'] = tostring(#body),
            },
            source = ltn12.source.string(body),
            sink = ltn12.sink.table(response),
        })

        if callback then
            if ok then
                callback(true, table.concat(response))
            else
                callback(false, tostring(status))
            end
        end
    end
end

--- Native HTTP GET using luasocket.
--- @param path string API path
--- @param callback function Called with (success, response)
local function httpGetNative(path, callback)
    if not hasSocket then
        if callback then callback(false, 'luasocket not available') end
        return
    end

    local http = require('socket.http')
    http.TIMEOUT = config.timeout

    local body, status = http.request(config.endpoint .. path)

    if callback then
        if body and status == 200 then
            callback(true, body)
        else
            callback(false, 'HTTP ' .. tostring(status))
        end
    end
end

--- Web HTTP POST using JavaScript fetch() via love.js bridge.
--- Uses window.CoquiAPI injected by coqui_bridge.js.
--- @param path string API path
--- @param body string JSON body
--- @param callback function|nil Called with (success, response)
local function httpPostWeb(path, body, callback)
    -- love.js exposes JS interop — the bridge script sets up window.CoquiAPI
    -- which polls for requests from Lua via a shared queue
    local js = [[
        if (window.CoquiAPI && window.CoquiAPI.post) {
            window.CoquiAPI.post('%s', %s);
        }
    ]]
    -- In love.js Emscripten builds, we use Module.eval or JS.eval
    local ok, err = pcall(function()
        if love.system._js_eval then
            love.system._js_eval(string.format(js, path, body))
        end
    end)

    if config.debug then
        if ok then
            print('[CoquiAPI] Web request sent to ' .. path)
        else
            print('[CoquiAPI] Web request failed: ' .. tostring(err))
        end
    end
end

-- ── Public API ──────────────────────────────────────────────────────

--- Send a prompt/message to the Coqui bot.
--- @param message string The message to send
--- @param callback function|nil Optional callback(success, response)
function coqui_api.sendPrompt(message, callback)
    if not config.enabled then return end

    local body = jsonEncode({
        prompt = message,
    })

    if isWeb then
        httpPostWeb('/api/tasks', body, callback)
    else
        httpPostNative('/api/tasks', body, callback)
    end
end

--- Create a task for the Coqui bot to process.
--- @param description string Task description
--- @param data table|nil Additional task data
--- @param callback function|nil Optional callback(success, response)
function coqui_api.createTask(description, data, callback)
    if not config.enabled then return end

    local payload = {
        task = description,
        data = data or {},
    }

    local body = jsonEncode(payload)

    if isWeb then
        httpPostWeb('/api/tasks', body, callback)
    else
        httpPostNative('/api/tasks', body, callback)
    end
end

--- Check the status of a task.
--- @param taskId string Task ID to check
--- @param callback function Called with (success, response)
function coqui_api.checkTask(taskId, callback)
    if not config.enabled then return end

    if isWeb then
        -- Web: use bridge polling
        httpPostWeb('/api/tasks/' .. taskId, '{}', callback)
    else
        httpGetNative('/api/tasks/' .. taskId, callback)
    end
end

--- Send a game event to Coqui (e.g., level complete, player died).
--- @param eventType string Event name
--- @param data table|nil Event data
function coqui_api.sendEvent(eventType, data)
    if not config.enabled then return end

    local payload = {
        event = eventType,
        data = data or {},
        timestamp = os.time(),
    }

    local body = jsonEncode(payload)

    if isWeb then
        httpPostWeb('/api/tasks', body)
    else
        httpPostNative('/api/tasks', body)
    end
end

--- Convenience: notify Coqui that a level was completed.
--- @param level number|string Level identifier
--- @param score number Player's score
--- @param time number Time taken in seconds
function coqui_api.onLevelComplete(level, score, time)
    coqui_api.sendEvent('level_complete', {
        level = level,
        score = score,
        time = time,
    })
end

--- Convenience: notify Coqui that the player died.
--- @param reason string Death reason
--- @param data table|nil Additional context
function coqui_api.onPlayerDeath(reason, data)
    coqui_api.sendEvent('player_death', {
        reason = reason,
        data = data or {},
    })
end

--- Poll for responses from threaded requests (call in love.update).
--- @return table|nil Response data if available
function coqui_api.poll()
    if isWeb or not hasThread then
        return nil
    end

    local channel = love.thread.getChannel('coqui_api_response')
    return channel:pop()
end

--- Send an async request and process the response later via poll().
--- @param path string API path
--- @param data table Request data
function coqui_api.sendAsync(path, data)
    if not config.enabled then return end

    local body = jsonEncode(data)

    if isWeb then
        httpPostWeb(path, body)
    else
        httpPostNative(path, body)
    end
end

return coqui_api
