-- {{TITLE}} — Platformer Template
-- A side-scrolling platformer with player movement, gravity, and platforms

local coqui = require('lib.coqui_api')

-- ── Game State ──────────────────────────────────────────────────────

local player = {
    x = 100, y = 0,
    width = 24, height = 32,
    velX = 0, velY = 0,
    speed = 200,
    jumpForce = -400,
    gravity = 900,
    onGround = false,
    facing = 1,
    color = {0.3, 0.6, 1},
}

local platforms = {}
local coins = {}
local score = 0
local camera = { x = 0, y = 0 }

-- ── Helpers ─────────────────────────────────────────────────────────

local function aabb(ax, ay, aw, ah, bx, by, bw, bh)
    return ax < bx + bw and ax + aw > bx and ay < by + bh and ay + ah > by
end

local function generateLevel()
    platforms = {
        { x = 0,   y = 500, w = 300, h = 20 },
        { x = 250, y = 420, w = 120, h = 20 },
        { x = 420, y = 350, w = 150, h = 20 },
        { x = 620, y = 420, w = 200, h = 20 },
        { x = 870, y = 350, w = 120, h = 20 },
        { x = 1050, y = 280, w = 150, h = 20 },
        { x = 1250, y = 350, w = 200, h = 20 },
        { x = 1500, y = 500, w = 400, h = 20 },
    }

    coins = {
        { x = 280, y = 390, r = 8, collected = false },
        { x = 490, y = 320, r = 8, collected = false },
        { x = 720, y = 390, r = 8, collected = false },
        { x = 930, y = 320, r = 8, collected = false },
        { x = 1120, y = 250, r = 8, collected = false },
        { x = 1350, y = 320, r = 8, collected = false },
        { x = 1600, y = 470, r = 8, collected = false },
        { x = 1700, y = 470, r = 8, collected = false },
    }
end

-- ── Love Callbacks ──────────────────────────────────────────────────

function love.load()
    coqui.configure({ endpoint = 'http://localhost:3300', debug = false })
    love.graphics.setBackgroundColor(0.12, 0.12, 0.18)
    generateLevel()
    player.y = 460
end

function love.update(dt)
    local response = coqui.poll()

    -- Horizontal input
    player.velX = 0
    if love.keyboard.isDown('left', 'a') then
        player.velX = -player.speed
        player.facing = -1
    end
    if love.keyboard.isDown('right', 'd') then
        player.velX = player.speed
        player.facing = 1
    end

    -- Gravity
    player.velY = player.velY + player.gravity * dt

    -- Move X
    player.x = player.x + player.velX * dt

    -- Move Y
    player.y = player.y + player.velY * dt
    player.onGround = false

    -- Platform collision
    for _, p in ipairs(platforms) do
        if aabb(player.x, player.y, player.width, player.height, p.x, p.y, p.w, p.h) then
            -- Resolve vertically
            if player.velY > 0 then
                player.y = p.y - player.height
                player.velY = 0
                player.onGround = true
            elseif player.velY < 0 then
                player.y = p.y + p.h
                player.velY = 0
            end
        end
    end

    -- Coin collection
    local px, py = player.x + player.width / 2, player.y + player.height / 2
    for _, c in ipairs(coins) do
        if not c.collected then
            local dx, dy = px - c.x, py - c.y
            if dx * dx + dy * dy < (c.r + 12) * (c.r + 12) then
                c.collected = true
                score = score + 100
            end
        end
    end

    -- Fall reset
    if player.y > 700 then
        player.x = 100
        player.y = 460
        player.velX = 0
        player.velY = 0
    end

    -- Camera follow
    local targetCamX = player.x - love.graphics.getWidth() / 2 + player.width / 2
    camera.x = camera.x + (targetCamX - camera.x) * 5 * dt
    camera.x = math.max(0, camera.x)
end

function love.draw()
    love.graphics.push()
    love.graphics.translate(-camera.x, -camera.y)

    -- Draw platforms
    love.graphics.setColor(0.35, 0.35, 0.45)
    for _, p in ipairs(platforms) do
        love.graphics.rectangle('fill', p.x, p.y, p.w, p.h, 3, 3)
    end

    -- Draw coins
    for _, c in ipairs(coins) do
        if not c.collected then
            love.graphics.setColor(1, 0.85, 0)
            love.graphics.circle('fill', c.x, c.y, c.r)
        end
    end

    -- Draw player
    love.graphics.setColor(player.color)
    love.graphics.rectangle('fill', player.x, player.y, player.width, player.height, 2, 2)

    -- Player direction indicator
    love.graphics.setColor(0.9, 0.9, 1)
    local eyeX = player.facing == 1 and player.x + player.width - 6 or player.x + 3
    love.graphics.rectangle('fill', eyeX, player.y + 6, 3, 4)

    love.graphics.pop()

    -- HUD
    love.graphics.setColor(1, 1, 1)
    love.graphics.print('Score: ' .. score, 10, 10)
    love.graphics.print('Arrow keys / WASD to move, Space to jump', 10, love.graphics.getHeight() - 25)
end

function love.keypressed(key)
    if key == 'space' or key == 'up' or key == 'w' then
        if player.onGround then
            player.velY = player.jumpForce
            player.onGround = false
        end
    end

    if key == 'r' then
        player.x = 100
        player.y = 460
        player.velX = 0
        player.velY = 0
        score = 0
        generateLevel()
    end

    if key == 'escape' then
        love.event.quit()
    end
end
