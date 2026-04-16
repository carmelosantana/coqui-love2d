# Love2D Toolkit for Coqui

A comprehensive Love2D game development toolkit for [Coqui](https://github.com/coquibot/coqui). Enables the bot to create, run, build, and export Love2D games from start to finish.

## Features

- **Project Scaffolding** — Create Love2D projects from 5 built-in templates (blank, platformer, top-down, puzzle, particle-demo)
- **Native Execution** — Launch and manage Love2D processes with full lifecycle control
- **Project-Local Debug Logs** — Every run writes a timestamped log file inside the game project and updates a stable latest.log pointer
- **Code Generation** — Generate 12 game component types and 6 scene types as ready-to-use Lua modules
- **Web Export** — Export games as browser-playable builds via love.js
- **Log Monitoring** — Structured log viewing and search for debugging
- **Bot Communication** — Bidirectional Lua/JS bridge for game↔bot API calls
- **Built-In API Reference** — Bundled LÖVE 11.5 API documentation with full-text search (1000+ entries, no network access needed)
- **Companion Skill** — Love2D game dev best practices and workflow guidance

## Requirements

- PHP 8.4+
- [LÖVE](https://love2d.org/) installed and on PATH (`love` binary)
- Toolkit baseline: LÖVE 11.5
- Node.js + `love.js` npm package (for web export only)
- [carmelosantana/php-agents](https://github.com/carmelosantana/php-agents) ^0.5

## Installation

```bash
composer require coquibot/coqui-toolkit-love2d
```

The toolkit is auto-discovered by Coqui on next startup — no configuration needed.

## Tools

### `love2d` — Project & Process Lifecycle

| Action | Description |
|--------|-------------|
| `create` | Scaffold a new project with conf.lua, main.lua, assets, Coqui API bridge, and project-local debug logging |
| `run` | Launch Love2D with a project |
| `stop` | Stop a running instance |
| `status` | Get instance details (PID, uptime, project path, log paths, binary, detected version) |
| `list` | Show all managed instances |
| `build` | Create a .love archive for distribution |
| `export_web` | Export for browser play via love.js |
| `stop_all` | Stop all running instances |

### `love2d_template` — Code Generation

| Action | Description |
|--------|-------------|
| `list_components` | Show all available component and scene types |
| `generate_component` | Generate a Lua module for a game component |
| `generate_scene` | Generate a complete scene/state module |

**Component types:** player, enemy, tilemap, camera, ui-hud, particle-system, state-machine, collision, animation, save-load, audio-manager, level-loader

**Scene types:** menu, gameplay, pause, game-over, settings, level-select

### `love2d_log` — Output Monitoring

| Action | Description |
|--------|-------------|
| `tail` | Show recent log entries (filterable by level, instance name, or project path) |
| `search` | Search logs by keyword, optionally filtered by instance name or project path |
| `clear` | Clear all log entries |

### `love2d_doc` — API Reference

| Action | Description |
|--------|-------------|
| `search` | Full-text search across all Love2D functions, types, enums, and callbacks |
| `lookup` | Look up a specific API entry by name with full signatures and argument details |
| `list_modules` | List all Love2D modules with entry counts |

The documentation is bundled from the [love2d-community/love-api](https://github.com/love2d-community/love-api) project and covers LÖVE 11.5 with 1000+ entries. No network access is required at runtime — the data ships as a pre-built JSON file and is cached locally in SQLite with FTS5 for fast keyword search.

## Project Layout

New projects default to the Coqui workspace project root instead of a Love2D-specific subtree:

```text
workspace/
  projects/
    my-game/
      main.lua
      conf.lua
      assets/
      lib/
      .coqui/
        love2d/
          logs/
            20260407-153012-love-my-game.log
            latest.log
```

You can also scaffold into an existing Coqui project directory by passing `project` to `love2d create`.

## Logging and Debugging

Every `love2d run` launch now does the following:

- Writes a timestamped per-run log file inside `.coqui/love2d/logs/` in the project directory
- Updates a stable `latest.log` pointer in the same folder
- Persists run metadata so logs remain discoverable after crashes or normal shutdowns
- Surfaces the log paths directly in `love2d run`, `love2d status`, and `love2d_log` output

Recommended workflow:

1. Create or scaffold into a project directory
2. Run the game with `love2d`
3. If startup looks wrong, inspect `latest.log` in the project or use `love2d_log tail`
4. Search specific failures with `love2d_log search`

If Love2D exits immediately, the toolkit now returns the project path, log path, debug directory, binary path, detected version, and a startup log excerpt to make failures easier to diagnose.

## Version Handling

The toolkit currently targets LÖVE 11.5 as its verified baseline. It no longer scatters that version across multiple code paths:

- Scaffolded `conf.lua` files are generated from a centralized baseline
- `love2d run` and `love2d status` surface the detected installed runtime version
- Error output includes both the detected runtime version and the toolkit baseline

This keeps the toolkit aligned with the current stable Love2D release while making version assumptions explicit.

## Templates

| Template | Genre | Description |
|----------|-------|-------------|
| `blank` | Any | Empty project with Coqui bridge setup |
| `platformer` | Action | Side-scroller with gravity, platforms, coins |
| `top-down` | Adventure | 8-directional movement, tile map, enemies, shooting |
| `puzzle` | Puzzle | Match-3 grid with swap mechanics and scoring |
| `particle-demo` | Visual | 6 preset particle effects with mouse following |

## Coqui API Bridge

Every created project includes `lib/coqui_api.lua` — a Lua module that lets the game communicate back to Coqui. It auto-detects native vs web runtime:

```lua
local coqui = require('lib.coqui_api')

function love.load()
    coqui.configure({ endpoint = 'http://localhost:3300' })
end

function love.update(dt)
    -- Poll for async responses
    local response = coqui.poll()
end

-- Send events to the bot
coqui.onLevelComplete(3, 1500, 45.2)
coqui.sendEvent('player_death', { reason = 'fell off platform' })
coqui.sendPrompt('Player is stuck, suggest easier puzzle')
```

**Native mode**: Uses luasocket + love.thread for non-blocking HTTP
**Web mode**: Uses JavaScript fetch() via the coqui_bridge.js injected into the love.js page

## Companion Skill

The toolkit includes a `love2d-game-dev` skill in `skills/love2d-game-dev/SKILL.md` that provides the bot with:

- Development workflow guidance
- Love2D architecture patterns
- Template and component selection advice
- Debugging strategies
- Code quality guidelines
- Performance tips

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Static analysis
vendor/bin/phpstan analyse
```

## Architecture

```
src/
  Love2DToolkit.php              # ToolkitInterface — wires tools + guidelines
  Love2DTool.php                 # Primary tool: create/run/stop/build/export
  Love2DTemplateTool.php         # Code generation: components + scenes
  Love2DLogTool.php              # Log viewer: tail/search/clear
  Love2DDocTool.php              # API reference: search/lookup/list_modules
  Runtime/
    Love2DRunner.php             # Process manager + project operations
  Storage/
    Love2DLogStore.php           # SQLite log store
    Love2DDocStore.php           # SQLite FTS5 doc cache
  Resources/
    love2d-api.json              # Bundled LÖVE 11.5 API data (generated)
    coqui_api.lua                # Lua bridge (copied into projects)
    coqui_bridge.js              # JS bridge (for love.js web export)
    index.html                   # Web export HTML template
    templates/                   # Project templates
      blank/
      platformer/
      top-down/
      puzzle/
      particle-demo/
scripts/
  build-docs.php                 # Generates love2d-api.json from love-api repo
skills/
  love2d-game-dev/
    SKILL.md                     # Companion skill for game dev guidance
tests/
  Unit/
    Love2DToolkitTest.php
    Love2DRunnerTest.php
    Love2DLogStoreTest.php
    Love2DDocStoreTest.php
    Love2DToolsTest.php
```

## License

MIT
