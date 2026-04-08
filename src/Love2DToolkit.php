<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitLove2D;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitLove2D\Runtime\Love2DRunner;
use CarmeloSantana\CoquiToolkitLove2D\Storage\Love2DLogStore;

/**
 * Love2D game development toolkit for Coqui.
 *
 * Provides tools to create, run, stop, build, and export Love2D game projects.
 * Includes code generation for common game components and scenes, process
 * lifecycle management, log viewing, and web export via love.js.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * Requires `love` binary on PATH for native execution and `love.js` (npm)
 * for web export.
 */
final class Love2DToolkit implements ToolkitInterface
{
    private readonly Love2DRunner $runner;
    private readonly Love2DLogStore $logStore;

    public function __construct(
        string $workspacePath,
        ?Love2DRunner $runner = null,
        ?Love2DLogStore $logStore = null,
    ) {
        $this->runner = $runner ?? new Love2DRunner($workspacePath);
        $this->logStore = $logStore ?? new Love2DLogStore(
            $workspacePath . '/love2d-logs.db',
        );
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        return new self(workspacePath: $workspacePath);
    }

    public function tools(): array
    {
        return [
            new Love2DTool($this->runner),
            new Love2DTemplateTool(),
            new Love2DLogTool($this->runner, $this->logStore),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <LOVE2D-TOOLKIT-GUIDELINES>
            ## Love2D Game Development

            You can create, run, and export Love2D games using three tools:

            ### Workflow — Creating a Game
            1. **Create project**: `love2d` action `create` with `name` and optional `template`
               (blank, platformer, top-down, puzzle, particle-demo)
              Optional: pass `project` to scaffold directly into an existing Coqui project directory.
            2. **Generate components**: `love2d_template` to scaffold player, enemy, camera, etc.
            3. **Write game code**: Edit `main.lua` and other Lua files in the project directory
            4. **Run natively**: `love2d` action `run` with `project` set to the project name
            5. **Check output**: `love2d_log` action `tail` to see errors or debug output
            6. **Iterate**: Fix issues, re-run, check logs — repeat until working
            7. **Build .love archive**: `love2d` action `build` for distribution
            8. **Export to web**: `love2d` action `export_web` to create a browser-playable version

            ### `love2d` Tool — Project & Process Lifecycle
            - **create**: Scaffold a new Love2D project with conf.lua, main.lua, assets directory,
              and project-local debug logging. Specify `name` (required) and optional `template`,
              `title`, `width`, `height`, and `project` to scaffold into an existing Coqui project directory.
            - **run**: Launch Love2D with a project. Specify `project` (directory name).
            - **stop**: Stop a running Love2D instance by `name`.
            - **status**: Get details — PID, uptime, project path, log locations, binary path, and detected version.
            - **list**: Show all managed Love2D instances.
            - **build**: Create a .love archive from a project. Specify `project`.
            - **export_web**: Export project for browser play via love.js. Specify `project`.
              Optional: `compatibility` mode (default: true, uses compat mode for wider browser support).
            - **stop_all**: Stop every running Love2D instance.

            ### `love2d_template` Tool — Code Generation
            - **list_components**: Show available component types and scene types.
            - **generate_component**: Generate Lua module code for game components.
              Types: player, enemy, tilemap, camera, ui-hud, particle-system, state-machine,
              collision, animation, save-load, audio-manager, level-loader.
            - **generate_scene**: Generate complete scene/state modules.
              Types: menu, gameplay, pause, game-over, settings, level-select.

            ### `love2d_log` Tool — Output Monitoring
            - **tail**: Last N log entries (default 50). Filter by `level`, `name`, or `project`.
            - **search**: Search by `query` string across log messages, optionally filtered by instance or project.
            - **clear**: Delete all log entries.

            ### Coqui API Bridge
            Projects created with `love2d create` include `lib/coqui_api.lua` — a Lua module that
            lets the game communicate back to Coqui. It auto-detects native vs web runtime:
            - **Native**: Uses luasocket for HTTP requests on a background thread
            - **Web**: Uses JavaScript fetch() via love.js bridge

            Usage in game code:
            ```lua
            local coqui = require('lib.coqui_api')
            coqui.configure({ endpoint = 'http://localhost:3300' })
            coqui.sendPrompt('Player completed level 3 with score 1500')
            ```

            ### Key Considerations
            - Love2D must be installed on the system (`love` binary on PATH)
            - The toolkit targets Love2D 11.5 and surfaces the installed runtime version in tool output
            - For web export, Node.js and `love.js` npm package are required
            - New projects default to `workspace/projects/<name>/` unless you supply an explicit `project` path
            - Runtime PID/metadata live under `workspace/love2d/`, but each project stores logs in `.coqui/love2d/logs/`
            - Each run writes a timestamped log file and updates a stable `latest.log` pointer inside the project
            - Use the webserver toolkit to serve exported web builds
            - Game window resolution defaults to 800×600 but can be customized in conf.lua

            ### Game Development Tips
            - Start with a template that matches the game genre
            - Build incrementally: get movement working, then add enemies, then scoring
            - After every launch, check `latest.log` or use `love2d_log tail` before assuming the startup succeeded
            - The state_machine component is essential for multi-screen games
            - Test with `love2d run` before building archives
            - Use `love2d_template generate_component type:collision` for physics
            - Web export uses compatibility mode by default for wider browser support
            </LOVE2D-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
