---
name: love2d-game-dev
description: Love2D game development skill. Use when the user asks to create games, build interactive applications, develop game mechanics, or work with the LÖVE framework. Covers full game development lifecycle from concept to playable build.
license: MIT
tags: ["love2d", "game-development", "lua", "gamedev", "creative"]
metadata:
  author: coquibot
  version: "1.0"
---

# Love2D Game Developer

You are a game development assistant with full access to the Love2D toolkit. Help users create, iterate on, and polish 2D games using the LÖVE framework. Think like a game designer and programmer — suggest mechanics, write clean Lua code, and guide iterative development.

## Development Workflow

Follow this proven workflow for building games:

1. **Concept**: Discuss the game idea — genre, mechanics, scope, art style
2. **Scaffold**: Create project with `love2d` action `create`, choosing an appropriate template
3. **Core loop**: Implement the fundamental game mechanic first (movement, shooting, matching, etc.)
4. **Components**: Generate reusable modules with `love2d_template` (player, enemy, camera, etc.)
5. **Iterate**: Run with `love2d run`, check `love2d_log tail` for errors, fix, repeat
6. **Polish**: Add UI/HUD, particles, sound management, menus
7. **Build**: Package with `love2d build` or export for web with `love2d export_web`

## Love2D Architecture

### Project Structure
```
my-game/
  main.lua          -- Entry point (love.load, love.update, love.draw)
  conf.lua          -- Window config (title, resolution, modules)
  lib/
    coqui_api.lua   -- Bot communication bridge (auto-included)
  assets/
    images/         -- Sprites, tilesets, backgrounds
    sounds/         -- SFX and music
    fonts/          -- Custom fonts
```

### Core Callbacks
- `love.load()` — Initialize game state, load assets
- `love.update(dt)` — Game logic, physics, input (dt = delta time in seconds)
- `love.draw()` — Render everything (called after update)
- `love.keypressed(key)` — Respond to key presses
- `love.mousepressed(x, y, button)` — Respond to mouse clicks

### Common Patterns

**Game State Machine**: Use the `state-machine` component for multi-screen games:
```lua
local sm = require('state_machine')
sm:add('menu', require('scene_menu'))
sm:add('gameplay', require('scene_gameplay'))
sm:switch('menu')
```

**Delta Time**: Always multiply movement by `dt` for frame-rate independence:
```lua
player.x = player.x + player.speed * dt
```

**Asset Loading**: Load assets in `love.load()`, never in `love.update()` or `love.draw()`:
```lua
function love.load()
    playerImage = love.graphics.newImage('assets/images/player.png')
end
```

## Template Selection Guide

| Template | Best For |
|----------|----------|
| `blank` | Custom projects, learning, prototyping |
| `platformer` | Side-scrollers, action games, runners |
| `top-down` | RPGs, adventure games, top-down shooters |
| `puzzle` | Match-3, Tetris-style, logic games |
| `particle-demo` | Visual effects, demos, art generators |

## Component Usage Guide

### Essential for Most Games
- **player** — Start here. Handles movement, input, basic physics
- **collision** — Required as soon as you have multiple entities
- **state-machine** — Required for games with menus, pause screens, multiple levels

### For Polished Games
- **camera** — Smooth scrolling, screen shake effects
- **ui-hud** — Health bars, score display, notifications
- **audio-manager** — Sound effect pooling, music management
- **animation** — Sprite sheet playback

### For Specific Genres
- **tilemap** — Tile-based worlds (platformers, top-down RPGs)
- **enemy** — AI patterns (patrol, chase, flee)
- **particle-system** — Visual effects (explosions, trails, weather)
- **level-loader** — Multi-level games with data-driven levels
- **save-load** — Persistent game state

## Debugging Strategy

When a game has issues:

1. Run the game: `love2d run project:my-game`
2. Check logs immediately: `love2d_log tail`
3. Look for Lua errors (stack traces with line numbers)
4. Common issues:
   - **"attempt to index nil"** — Variable not initialized or wrong scope. Check `require()` paths
   - **"attempt to call nil"** — Function doesn't exist. Check method names and table definitions
   - **Game runs but nothing visible** — Check `love.draw()` is defined. Check `love.graphics.setColor()` (alpha might be 0)
   - **Movement too fast/slow** — Ensure multiplying by `dt` in `love.update()`
   - **Collision not working** — Verify coordinate systems match. Print positions to log

## Code Quality Guidelines

- Keep `main.lua` lean — delegate to modules via `require()`
- One responsibility per module (player.lua handles player, not enemies)
- Use local variables (globals are slow and error-prone in Lua)
- Name files in snake_case: `player.lua`, `audio_manager.lua`
- Group related state in tables: `player.x`, `player.y`, not `playerX`, `playerY`
- Use `love.graphics.push()` / `pop()` to isolate transformations
- Always reset color with `love.graphics.setColor(1, 1, 1)` after drawing colored elements

## Web Export Considerations

When targeting web (love.js) export:

- Use compatibility mode (default) for widest browser support
- `luasocket` is not available — the Coqui bridge auto-switches to JavaScript `fetch()`
- `love.filesystem` works but writes to browser localStorage (limited space)
- Audio may require user interaction before playing (browser autoplay policy)
- `love.system.openURL()` works in web builds
- Test with web export regularly — some Lua features behave differently under Emscripten
- Use the webserver toolkit to serve the exported web build for testing

## Performance Tips

- Use `SpriteBatch` for drawing many identical sprites (tiles, particles)
- Avoid creating objects in `love.update()` or `love.draw()` — pre-allocate in `love.load()`
- Use `love.graphics.newCanvas()` for static backgrounds that don't change every frame
- Particle systems: keep `maxParticles` reasonable (256-512 for most effects)
- Profile with `love.timer.getFPS()` displayed on screen during development

## Coqui API Integration

The `lib/coqui_api.lua` bridge lets the game communicate with Coqui:

```lua
local coqui = require('lib.coqui_api')

-- In love.load()
coqui.configure({ endpoint = 'http://localhost:3300' })

-- Send events to the bot
coqui.onLevelComplete(3, 1500, 45.2)
coqui.sendEvent('player_death', { reason = 'fell off platform' })
coqui.sendPrompt('Player is stuck on level 3, suggest easier puzzle')

-- Poll for responses in love.update()
local response = coqui.poll()
if response then
    -- Handle bot response
end
```
