-- {{TITLE}} — Top-Down Template
-- A top-down adventure with 8-directional movement and tile-based world

local coqui = require('lib.coqui_api')

-- ── Game State ──────────────────────────────────────────────────────

local player = {
    x = 400, y = 300,
    width = 20, height = 20,
    speed = 150,
    color = {0.3, 0.7, 0.4},
    health = 100,
    maxHealth = 100,
}

local enemies = {}
local projectiles = {}
local score = 0
local tileSize = 32

-- Simple tile map (0 = floor, 1 = wall)
local map = {
    {1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,1,1,0,0,0,0,0,0,1,1,1,0,0,0,0,0,1,1,0,0,0,1},
    {1,0,0,1,1,0,0,0,0,0,0,1,0,1,0,0,0,0,0,1,1,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,1,1,0,0,0,0,0,0,0,0,1,1,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,1,1,0,0,0,0,0,0,0,0,1,1,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,1,1,0,0,0,0,0,0,1,0,1,0,0,0,0,0,1,1,0,0,0,1},
    {1,0,0,1,1,0,0,0,0,0,0,1,1,1,0,0,0,0,0,1,1,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1},
    {1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1},
}

-- ── Helpers ─────────────────────────────────────────────────────────

local function isSolid(tx, ty)
    if map[ty] and map[ty][tx] then
        return map[ty][tx] == 1
    end
    return true
end

local function worldToTile(wx, wy)
    return math.floor(wx / tileSize) + 1, math.floor(wy / tileSize) + 1
end

local function spawnEnemy(x, y)
    table.insert(enemies, {
        x = x, y = y,
        width = 18, height = 18,
        speed = 60 + math.random(40),
        health = 30,
        color = {0.9, 0.2, 0.2},
        timer = 0,
        dir = math.random() * math.pi * 2,
    })
end

-- ── Love Callbacks ──────────────────────────────────────────────────

function love.load()
    coqui.configure({ endpoint = 'http://localhost:3300' })
    love.graphics.setBackgroundColor(0.1, 0.1, 0.12)

    -- Spawn initial enemies
    spawnEnemy(200, 200)
    spawnEnemy(600, 150)
    spawnEnemy(500, 400)
    spawnEnemy(150, 450)
end

function love.update(dt)
    coqui.poll()

    -- Player movement (8-directional, normalized)
    local dx, dy = 0, 0
    if love.keyboard.isDown('left', 'a')  then dx = dx - 1 end
    if love.keyboard.isDown('right', 'd') then dx = dx + 1 end
    if love.keyboard.isDown('up', 'w')    then dy = dy - 1 end
    if love.keyboard.isDown('down', 's')  then dy = dy + 1 end

    if dx ~= 0 or dy ~= 0 then
        local len = math.sqrt(dx * dx + dy * dy)
        dx, dy = dx / len, dy / len
    end

    local newX = player.x + dx * player.speed * dt
    local newY = player.y + dy * player.speed * dt

    -- Tile collision for X
    local tx1, ty1 = worldToTile(newX, player.y)
    local tx2, ty2 = worldToTile(newX + player.width, player.y + player.height)
    local blockedX = false
    for ty = ty1, ty2 do
        for tx = tx1, tx2 do
            if isSolid(tx, ty) then blockedX = true end
        end
    end
    if not blockedX then player.x = newX end

    -- Tile collision for Y
    tx1, ty1 = worldToTile(player.x, newY)
    tx2, ty2 = worldToTile(player.x + player.width, newY + player.height)
    local blockedY = false
    for ty = ty1, ty2 do
        for tx = tx1, tx2 do
            if isSolid(tx, ty) then blockedY = true end
        end
    end
    if not blockedY then player.y = newY end

    -- Update enemies
    for _, e in ipairs(enemies) do
        e.timer = e.timer + dt
        if e.timer > 2 then
            e.dir = math.random() * math.pi * 2
            e.timer = 0
        end
        local ex = e.x + math.cos(e.dir) * e.speed * dt
        local ey = e.y + math.sin(e.dir) * e.speed * dt
        local etx1, ety1 = worldToTile(ex, ey)
        local etx2, ety2 = worldToTile(ex + e.width, ey + e.height)
        local eBlocked = false
        for ty = ety1, ety2 do
            for tx = etx1, etx2 do
                if isSolid(tx, ty) then eBlocked = true end
            end
        end
        if not eBlocked then
            e.x = ex
            e.y = ey
        else
            e.dir = e.dir + math.pi
        end
    end

    -- Update projectiles
    for i = #projectiles, 1, -1 do
        local p = projectiles[i]
        p.x = p.x + p.dx * p.speed * dt
        p.y = p.y + p.dy * p.speed * dt
        p.life = p.life - dt

        -- Check enemy hit
        for j = #enemies, 1, -1 do
            local e = enemies[j]
            if p.x > e.x and p.x < e.x + e.width and p.y > e.y and p.y < e.y + e.height then
                e.health = e.health - 10
                if e.health <= 0 then
                    table.remove(enemies, j)
                    score = score + 50
                    -- Respawn elsewhere
                    spawnEnemy(math.random(2, 23) * tileSize, math.random(2, 17) * tileSize)
                end
                table.remove(projectiles, i)
                break
            end
        end

        -- Remove expired or out of bounds
        if p.life <= 0 then
            table.remove(projectiles, i)
        else
            local ptx, pty = worldToTile(p.x, p.y)
            if isSolid(ptx, pty) then
                table.remove(projectiles, i)
            end
        end
    end
end

function love.draw()
    -- Draw map
    for y = 1, #map do
        for x = 1, #map[y] do
            if map[y][x] == 1 then
                love.graphics.setColor(0.3, 0.3, 0.35)
            else
                love.graphics.setColor(0.15, 0.15, 0.18)
            end
            love.graphics.rectangle('fill', (x - 1) * tileSize, (y - 1) * tileSize, tileSize, tileSize)
        end
    end

    -- Draw enemies
    for _, e in ipairs(enemies) do
        love.graphics.setColor(e.color)
        love.graphics.rectangle('fill', e.x, e.y, e.width, e.height, 2, 2)
    end

    -- Draw projectiles
    love.graphics.setColor(1, 1, 0.5)
    for _, p in ipairs(projectiles) do
        love.graphics.circle('fill', p.x, p.y, 3)
    end

    -- Draw player
    love.graphics.setColor(player.color)
    love.graphics.rectangle('fill', player.x, player.y, player.width, player.height, 3, 3)

    -- HUD
    love.graphics.setColor(1, 1, 1)
    love.graphics.print('Score: ' .. score, 10, 10)

    -- Health bar
    local barW = 100
    love.graphics.setColor(0.2, 0.2, 0.2)
    love.graphics.rectangle('fill', 10, 30, barW, 8)
    love.graphics.setColor(0.2, 0.8, 0.3)
    love.graphics.rectangle('fill', 10, 30, barW * (player.health / player.maxHealth), 8)

    love.graphics.setColor(0.5, 0.5, 0.5)
    love.graphics.print('WASD to move, Click to shoot, R to restart', 10, love.graphics.getHeight() - 25)
end

function love.mousepressed(x, y, button)
    if button == 1 then
        local px = player.x + player.width / 2
        local py = player.y + player.height / 2
        local dx = x - px
        local dy = y - py
        local len = math.sqrt(dx * dx + dy * dy)
        if len > 0 then
            table.insert(projectiles, {
                x = px, y = py,
                dx = dx / len, dy = dy / len,
                speed = 350,
                life = 2,
            })
        end
    end
end

function love.keypressed(key)
    if key == 'r' then
        player.x = 400
        player.y = 300
        player.health = 100
        enemies = {}
        projectiles = {}
        score = 0
        spawnEnemy(200, 200)
        spawnEnemy(600, 150)
        spawnEnemy(500, 400)
        spawnEnemy(150, 450)
    end
    if key == 'escape' then
        love.event.quit()
    end
end
