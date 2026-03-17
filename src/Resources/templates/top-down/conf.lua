-- Top-down template — conf.lua
function love.conf(t)
    t.identity = nil
    t.version = '11.5'
    t.console = false

    t.window.title = '{{TITLE}}'
    t.window.icon = nil
    t.window.width = {{WIDTH}}
    t.window.height = {{HEIGHT}}
    t.window.resizable = true
    t.window.minwidth = 400
    t.window.minheight = 300
    t.window.vsync = 1

    t.modules.audio = true
    t.modules.event = true
    t.modules.graphics = true
    t.modules.image = true
    t.modules.joystick = true
    t.modules.keyboard = true
    t.modules.math = true
    t.modules.mouse = true
    t.modules.physics = false
    t.modules.sound = true
    t.modules.system = true
    t.modules.timer = true
    t.modules.touch = true
    t.modules.window = true
end
