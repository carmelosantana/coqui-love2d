-- {{TITLE}} — Particle Demo Template
-- Interactive particle effects showcase

local coqui = require('lib.coqui_api')

-- ── Particle Systems ────────────────────────────────────────────────

local systems = {}
local currentSystem = 1
local canvas  -- 1x1 pixel for particle texture

local presets = {
    {
        name = 'Fire',
        setup = function(ps)
            ps:setParticleLifetime(0.3, 0.8)
            ps:setEmissionRate(200)
            ps:setSizeVariation(0.6)
            ps:setSizes(4, 1)
            ps:setSpeed(40, 120)
            ps:setDirection(-math.pi / 2)
            ps:setSpread(math.pi / 4)
            ps:setLinearAcceleration(-20, -50, 20, -100)
            ps:setColors(
                1, 0.8, 0.2, 1,
                1, 0.3, 0.1, 0.8,
                0.5, 0.1, 0.1, 0
            )
        end,
    },
    {
        name = 'Snow',
        setup = function(ps)
            ps:setParticleLifetime(3, 6)
            ps:setEmissionRate(40)
            ps:setSizeVariation(0.3)
            ps:setSizes(3, 2)
            ps:setSpeed(10, 40)
            ps:setDirection(math.pi / 2)
            ps:setSpread(math.pi / 3)
            ps:setLinearAcceleration(-15, 5, 15, 20)
            ps:setColors(
                1, 1, 1, 0.9,
                0.9, 0.95, 1, 0
            )
        end,
    },
    {
        name = 'Explosion',
        setup = function(ps)
            ps:setParticleLifetime(0.4, 1.2)
            ps:setEmissionRate(0)  -- Use emit() for bursts
            ps:setSizeVariation(0.5)
            ps:setSizes(5, 2, 0)
            ps:setSpeed(100, 400)
            ps:setDirection(0)
            ps:setSpread(math.pi * 2)
            ps:setLinearAcceleration(0, 50, 0, 100)
            ps:setColors(
                1, 0.9, 0.3, 1,
                1, 0.4, 0.1, 0.8,
                0.3, 0.1, 0.1, 0
            )
        end,
    },
    {
        name = 'Rain',
        setup = function(ps)
            ps:setParticleLifetime(0.5, 1.5)
            ps:setEmissionRate(150)
            ps:setSizeVariation(0.2)
            ps:setSizes(2, 1)
            ps:setSpeed(200, 400)
            ps:setDirection(math.pi / 2 + 0.2)
            ps:setSpread(0.1)
            ps:setLinearAcceleration(0, 200, 0, 300)
            ps:setColors(
                0.5, 0.7, 1, 0.7,
                0.3, 0.5, 0.8, 0
            )
        end,
    },
    {
        name = 'Magic',
        setup = function(ps)
            ps:setParticleLifetime(0.8, 2)
            ps:setEmissionRate(80)
            ps:setSizeVariation(0.5)
            ps:setSizes(4, 2, 0)
            ps:setSpeed(20, 80)
            ps:setDirection(0)
            ps:setSpread(math.pi * 2)
            ps:setLinearAcceleration(-30, -30, 30, 30)
            ps:setRotation(0, math.pi * 2)
            ps:setSpin(0, math.pi)
            ps:setColors(
                0.6, 0.3, 1, 1,
                0.3, 0.7, 1, 0.8,
                0.2, 1, 0.5, 0
            )
        end,
    },
    {
        name = 'Smoke',
        setup = function(ps)
            ps:setParticleLifetime(1, 3)
            ps:setEmissionRate(30)
            ps:setSizeVariation(0.3)
            ps:setSizes(3, 8, 12)
            ps:setSpeed(10, 30)
            ps:setDirection(-math.pi / 2)
            ps:setSpread(math.pi / 6)
            ps:setLinearAcceleration(-10, -20, 10, -40)
            ps:setColors(
                0.4, 0.4, 0.4, 0.6,
                0.3, 0.3, 0.3, 0.2,
                0.2, 0.2, 0.2, 0
            )
        end,
    },
}

-- ── Love Callbacks ──────────────────────────────────────────────────

function love.load()
    coqui.configure({ endpoint = 'http://localhost:3300' })
    love.graphics.setBackgroundColor(0.05, 0.05, 0.08)

    -- Create a 1x1 white pixel for particles
    canvas = love.graphics.newCanvas(1, 1)
    love.graphics.setCanvas(canvas)
    love.graphics.setColor(1, 1, 1)
    love.graphics.rectangle('fill', 0, 0, 1, 1)
    love.graphics.setCanvas()

    -- Create particle systems for each preset
    for _, preset in ipairs(presets) do
        local ps = love.graphics.newParticleSystem(canvas, 512)
        preset.setup(ps)
        table.insert(systems, ps)
    end

    -- Position emitters at center
    local cx = love.graphics.getWidth() / 2
    local cy = love.graphics.getHeight() / 2
    for _, ps in ipairs(systems) do
        ps:setPosition(cx, cy)
    end
end

function love.update(dt)
    coqui.poll()

    -- Update current particle system
    local ps = systems[currentSystem]
    if ps then
        -- Follow mouse
        local mx, my = love.mouse.getPosition()
        ps:setPosition(mx, my)
        ps:update(dt)
    end
end

function love.draw()
    -- Draw active particle system
    local ps = systems[currentSystem]
    if ps then
        love.graphics.setColor(1, 1, 1)
        love.graphics.draw(ps)
    end

    -- Draw UI
    love.graphics.setColor(1, 1, 1, 0.8)
    love.graphics.printf(presets[currentSystem].name, 0, 15,
        love.graphics.getWidth(), 'center')

    -- Preset indicators
    local dotY = 45
    local totalWidth = #presets * 18
    local startX = (love.graphics.getWidth() - totalWidth) / 2
    for i = 1, #presets do
        if i == currentSystem then
            love.graphics.setColor(0.5, 0.7, 1, 0.9)
        else
            love.graphics.setColor(0.3, 0.3, 0.4, 0.5)
        end
        love.graphics.circle('fill', startX + (i - 1) * 18 + 5, dotY, 5)
    end

    -- Particle count
    local count = ps and ps:getCount() or 0
    love.graphics.setColor(0.5, 0.5, 0.5)
    love.graphics.print('Particles: ' .. count, 10, love.graphics.getHeight() - 45)
    love.graphics.print('FPS: ' .. love.timer.getFPS(), 10, love.graphics.getHeight() - 25)
    love.graphics.printf(
        'Left/Right: change effect  |  Click: burst  |  1-' .. #presets .. ': quick select',
        0, love.graphics.getHeight() - 25, love.graphics.getWidth() - 10, 'right')
end

function love.mousepressed(x, y, button)
    if button == 1 then
        local ps = systems[currentSystem]
        if ps then
            ps:emit(50)
        end
    end
end

function love.keypressed(key)
    if key == 'right' then
        currentSystem = (currentSystem % #presets) + 1
    elseif key == 'left' then
        currentSystem = ((currentSystem - 2) % #presets) + 1
    elseif key == 'space' then
        local ps = systems[currentSystem]
        if ps then
            ps:emit(100)
        end
    elseif tonumber(key) then
        local n = tonumber(key)
        if n >= 1 and n <= #presets then
            currentSystem = n
        end
    elseif key == 'escape' then
        love.event.quit()
    end
end
