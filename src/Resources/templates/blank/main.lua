-- {{TITLE}} — main.lua
-- A blank Love2D project created by Coqui

-- Require the Coqui API bridge for game↔bot communication
local coqui = require('lib.coqui_api')

function love.load()
    -- Configure Coqui API (optional)
    coqui.configure({
        endpoint = 'http://localhost:3300',
        debug = false,
    })

    -- Initialize your game here
    love.graphics.setBackgroundColor(0.15, 0.15, 0.2)
end

function love.update(dt)
    -- Poll for Coqui API responses (if using async communication)
    local response = coqui.poll()
    if response then
        print('[Coqui] Response: ' .. tostring(response.success))
    end

    -- Update your game logic here
end

function love.draw()
    -- Draw your game here
    love.graphics.setColor(1, 1, 1)
    love.graphics.printf(
        '{{TITLE}}\n\nEdit main.lua to start building your game!',
        0, love.graphics.getHeight() / 2 - 30,
        love.graphics.getWidth(), 'center'
    )
end

function love.keypressed(key)
    if key == 'escape' then
        love.event.quit()
    end
end
