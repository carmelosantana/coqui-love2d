<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitLove2D;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Tool for generating Love2D game code components and scenes.
 *
 * Provides code generation capabilities for common game components (player,
 * enemy, camera, UI, etc.) and scene types (menu, gameplay, pause, etc.).
 * Generated code follows Love2D conventions and integrates cleanly with the
 * standard project structure created by Love2DTool.
 */
final class Love2DTemplateTool implements ToolInterface
{
    public function name(): string
    {
        return 'love2d_template';
    }

    public function description(): string
    {
        return <<<'DESC'
            Generate Love2D game code components and scenes.

            Available actions:
            - list_components: List all available component types with descriptions.
            - generate_component: Generate a Lua module for a specific game component
              (player, enemy, tilemap, camera, ui-hud, particle-system, state-machine,
              collision, animation, save-load, audio-manager, level-loader).
            - generate_scene: Generate a complete game scene/state
              (menu, gameplay, pause, game-over, settings, level-select).
            DESC;
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        return match ($action) {
            'list_components' => $this->listComponents(),
            'generate_component' => $this->generateComponent($input),
            'generate_scene' => $this->generateScene($input),
            default => ToolResult::error("Unknown love2d_template action: '{$action}'"),
        };
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The template action to perform.',
                            'enum' => ['list_components', 'generate_component', 'generate_scene'],
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Component type (for "generate_component") or scene type (for "generate_scene").',
                            'enum' => [
                                'player', 'enemy', 'tilemap', 'camera', 'ui-hud',
                                'particle-system', 'state-machine', 'collision',
                                'animation', 'save-load', 'audio-manager', 'level-loader',
                                'menu', 'gameplay', 'pause', 'game-over', 'settings', 'level-select',
                            ],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Custom name for the generated module (optional, defaults to the type name).',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }

    // ── Action Handlers ─────────────────────────────────────────────────

    private function listComponents(): ToolResult
    {
        $output = "## Available Love2D Components\n\n";
        $output .= "### Game Components\n\n";
        $output .= "| Type | Description |\n|------|-------------|\n";
        $output .= "| `player` | Player entity with movement, input handling, and basic physics |\n";
        $output .= "| `enemy` | Enemy entity with AI patterns (patrol, chase, flee) |\n";
        $output .= "| `tilemap` | Tile-based map loader and renderer |\n";
        $output .= "| `camera` | Smooth-following camera with bounds and shake |\n";
        $output .= "| `ui-hud` | HUD overlay with health bar, score, and notifications |\n";
        $output .= "| `particle-system` | Configurable particle effects (explosions, trails, rain) |\n";
        $output .= "| `state-machine` | Generic finite state machine for game/entity states |\n";
        $output .= "| `collision` | AABB and circle collision detection and response |\n";
        $output .= "| `animation` | Sprite sheet animation with frame control |\n";
        $output .= "| `save-load` | Game state serialization to JSON files |\n";
        $output .= "| `audio-manager` | Sound effect and music management with pooling |\n";
        $output .= "| `level-loader` | Level data parsing and entity spawning |\n";
        $output .= "\n### Scene Types\n\n";
        $output .= "| Type | Description |\n|------|-------------|\n";
        $output .= "| `menu` | Main menu with navigation and start/quit buttons |\n";
        $output .= "| `gameplay` | Core gameplay loop with update/draw integration |\n";
        $output .= "| `pause` | Pause overlay with resume/quit options |\n";
        $output .= "| `game-over` | Game over screen with score display and retry |\n";
        $output .= "| `settings` | Settings menu with volume, controls, display |\n";
        $output .= "| `level-select` | Level selection grid with unlock tracking |\n";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function generateComponent(array $input): ToolResult
    {
        $type = trim((string) ($input['type'] ?? ''));

        if ($type === '') {
            return ToolResult::error(
                'The "type" parameter is required. Use "list_components" to see available types.',
            );
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = str_replace('-', '_', $type);
        }

        $code = match ($type) {
            'player' => $this->generatePlayer($name),
            'enemy' => $this->generateEnemy($name),
            'tilemap' => $this->generateTilemap($name),
            'camera' => $this->generateCamera($name),
            'ui-hud' => $this->generateHud($name),
            'particle-system' => $this->generateParticleSystem($name),
            'state-machine' => $this->generateStateMachine($name),
            'collision' => $this->generateCollision($name),
            'animation' => $this->generateAnimation($name),
            'save-load' => $this->generateSaveLoad($name),
            'audio-manager' => $this->generateAudioManager($name),
            'level-loader' => $this->generateLevelLoader($name),
            default => null,
        };

        if ($code === null) {
            return ToolResult::error("Unknown component type: '{$type}'. Use \"list_components\" to see available types.");
        }

        $output = "## Generated: {$type}\n\n";
        $output .= "Save this as `{$name}.lua` in your project directory.\n\n";
        $output .= "```lua\n{$code}\n```\n\n";
        $output .= "### Usage in main.lua\n\n";
        $output .= "```lua\nlocal {$name} = require('{$name}')\n```\n";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function generateScene(array $input): ToolResult
    {
        $type = trim((string) ($input['type'] ?? ''));

        if ($type === '') {
            return ToolResult::error(
                'The "type" parameter is required. Available: menu, gameplay, pause, game-over, settings, level-select.',
            );
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = 'scene_' . str_replace('-', '_', $type);
        }

        $code = match ($type) {
            'menu' => $this->generateMenuScene($name),
            'gameplay' => $this->generateGameplayScene($name),
            'pause' => $this->generatePauseScene($name),
            'game-over' => $this->generateGameOverScene($name),
            'settings' => $this->generateSettingsScene($name),
            'level-select' => $this->generateLevelSelectScene($name),
            default => null,
        };

        if ($code === null) {
            return ToolResult::error("Unknown scene type: '{$type}'.");
        }

        $output = "## Generated Scene: {$type}\n\n";
        $output .= "Save this as `{$name}.lua` in your project directory.\n\n";
        $output .= "```lua\n{$code}\n```\n\n";
        $output .= "### Usage with State Machine\n\n";
        $output .= "```lua\nlocal state_machine = require('state_machine')\n";
        $output .= "local {$name} = require('{$name}')\n";
        $output .= "state_machine:add('{$type}', {$name})\n";
        $output .= "state_machine:switch('{$type}')\n```\n";

        return ToolResult::success($output);
    }

    // ── Component Generators ────────────────────────────────────────────

    private function generatePlayer(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Player entity with movement and input handling
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new(x, y)
                local self = setmetatable({}, {$name})
                self.x = x or 100
                self.y = y or 100
                self.width = 32
                self.height = 32
                self.speed = 200
                self.velX = 0
                self.velY = 0
                self.gravity = 800
                self.jumpForce = -400
                self.onGround = false
                self.facing = 1  -- 1 = right, -1 = left
                self.health = 100
                self.maxHealth = 100
                self.alive = true
                return self
            end

            function {$name}:update(dt)
                if not self.alive then return end

                -- Horizontal movement
                self.velX = 0
                if love.keyboard.isDown('left', 'a') then
                    self.velX = -self.speed
                    self.facing = -1
                end
                if love.keyboard.isDown('right', 'd') then
                    self.velX = self.speed
                    self.facing = 1
                end

                -- Apply gravity
                self.velY = self.velY + self.gravity * dt

                -- Apply velocity
                self.x = self.x + self.velX * dt
                self.y = self.y + self.velY * dt

                -- Simple ground collision (replace with tilemap collision)
                local groundY = love.graphics.getHeight() - self.height
                if self.y >= groundY then
                    self.y = groundY
                    self.velY = 0
                    self.onGround = true
                end
            end

            function {$name}:jump()
                if self.onGround then
                    self.velY = self.jumpForce
                    self.onGround = false
                end
            end

            function {$name}:takeDamage(amount)
                self.health = math.max(0, self.health - amount)
                if self.health <= 0 then
                    self.alive = false
                end
            end

            function {$name}:draw()
                if not self.alive then return end
                love.graphics.setColor(0.2, 0.6, 1)
                love.graphics.rectangle('fill', self.x, self.y, self.width, self.height)
                love.graphics.setColor(1, 1, 1)
            end

            function {$name}:keypressed(key)
                if key == 'space' or key == 'up' or key == 'w' then
                    self:jump()
                end
            end

            function {$name}:getCenter()
                return self.x + self.width / 2, self.y + self.height / 2
            end

            function {$name}:getBounds()
                return self.x, self.y, self.width, self.height
            end

            return {$name}
            LUA;
    }

    private function generateEnemy(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Enemy entity with AI patterns
            local {$name} = {}
            {$name}.__index = {$name}

            local STATES = { PATROL = 'patrol', CHASE = 'chase', IDLE = 'idle' }

            function {$name}.new(x, y, options)
                options = options or {}
                local self = setmetatable({}, {$name})
                self.x = x or 200
                self.y = y or 200
                self.width = 28
                self.height = 28
                self.speed = options.speed or 80
                self.health = options.health or 50
                self.maxHealth = self.health
                self.alive = true
                self.damage = options.damage or 10
                self.state = STATES.PATROL
                self.direction = 1
                self.patrolDistance = options.patrolDistance or 150
                self.detectionRange = options.detectionRange or 200
                self.startX = x or 200
                self.timer = 0
                return self
            end

            function {$name}:update(dt, playerX, playerY)
                if not self.alive then return end

                local dx = (playerX or 0) - self.x
                local dy = (playerY or 0) - self.y
                local dist = math.sqrt(dx * dx + dy * dy)

                -- State transitions
                if dist < self.detectionRange and playerX then
                    self.state = STATES.CHASE
                elseif self.state == STATES.CHASE then
                    self.state = STATES.PATROL
                end

                if self.state == STATES.PATROL then
                    self.x = self.x + self.speed * self.direction * dt
                    if math.abs(self.x - self.startX) > self.patrolDistance then
                        self.direction = -self.direction
                    end
                elseif self.state == STATES.CHASE and dist > 0 then
                    self.x = self.x + (dx / dist) * self.speed * 1.5 * dt
                    self.y = self.y + (dy / dist) * self.speed * 1.5 * dt
                end
            end

            function {$name}:takeDamage(amount)
                self.health = math.max(0, self.health - amount)
                if self.health <= 0 then
                    self.alive = false
                end
            end

            function {$name}:draw()
                if not self.alive then return end
                love.graphics.setColor(1, 0.2, 0.2)
                love.graphics.rectangle('fill', self.x, self.y, self.width, self.height)
                -- Health bar
                local barWidth = self.width
                local healthRatio = self.health / self.maxHealth
                love.graphics.setColor(0.3, 0.3, 0.3)
                love.graphics.rectangle('fill', self.x, self.y - 8, barWidth, 4)
                love.graphics.setColor(1, 0, 0)
                love.graphics.rectangle('fill', self.x, self.y - 8, barWidth * healthRatio, 4)
                love.graphics.setColor(1, 1, 1)
            end

            function {$name}:getBounds()
                return self.x, self.y, self.width, self.height
            end

            return {$name}
            LUA;
    }

    private function generateCamera(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Smooth-following camera with bounds and shake
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new(width, height)
                local self = setmetatable({}, {$name})
                self.x = 0
                self.y = 0
                self.targetX = 0
                self.targetY = 0
                self.width = width or love.graphics.getWidth()
                self.height = height or love.graphics.getHeight()
                self.smoothing = 5
                self.scale = 1
                self.rotation = 0
                self.shakeIntensity = 0
                self.shakeDuration = 0
                self.shakeTimer = 0
                self.bounds = nil  -- { minX, minY, maxX, maxY }
                return self
            end

            function {$name}:follow(x, y)
                self.targetX = x - self.width / (2 * self.scale)
                self.targetY = y - self.height / (2 * self.scale)
            end

            function {$name}:update(dt)
                -- Smooth follow
                self.x = self.x + (self.targetX - self.x) * self.smoothing * dt
                self.y = self.y + (self.targetY - self.y) * self.smoothing * dt

                -- Apply bounds
                if self.bounds then
                    self.x = math.max(self.bounds.minX, math.min(self.x, self.bounds.maxX - self.width / self.scale))
                    self.y = math.max(self.bounds.minY, math.min(self.y, self.bounds.maxY - self.height / self.scale))
                end

                -- Update shake
                if self.shakeTimer > 0 then
                    self.shakeTimer = self.shakeTimer - dt
                    if self.shakeTimer <= 0 then
                        self.shakeIntensity = 0
                    end
                end
            end

            function {$name}:shake(intensity, duration)
                self.shakeIntensity = intensity or 5
                self.shakeDuration = duration or 0.3
                self.shakeTimer = self.shakeDuration
            end

            function {$name}:setBounds(minX, minY, maxX, maxY)
                self.bounds = { minX = minX, minY = minY, maxX = maxX, maxY = maxY }
            end

            function {$name}:attach()
                love.graphics.push()
                love.graphics.scale(self.scale)
                local shakeX = self.shakeTimer > 0 and (math.random() - 0.5) * self.shakeIntensity * 2 or 0
                local shakeY = self.shakeTimer > 0 and (math.random() - 0.5) * self.shakeIntensity * 2 or 0
                love.graphics.translate(-self.x + shakeX, -self.y + shakeY)
            end

            function {$name}:detach()
                love.graphics.pop()
            end

            function {$name}:toWorld(screenX, screenY)
                return screenX / self.scale + self.x, screenY / self.scale + self.y
            end

            function {$name}:toScreen(worldX, worldY)
                return (worldX - self.x) * self.scale, (worldY - self.y) * self.scale
            end

            return {$name}
            LUA;
    }

    private function generateTilemap(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Tile-based map loader and renderer
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new(tileSize)
                local self = setmetatable({}, {$name})
                self.tileSize = tileSize or 32
                self.tiles = {}
                self.width = 0
                self.height = 0
                self.layers = {}
                return self
            end

            function {$name}:loadFromTable(data)
                self.width = data.width or 0
                self.height = data.height or 0
                self.tiles = data.tiles or {}
            end

            function {$name}:loadFromString(str)
                self.tiles = {}
                local y = 0
                for line in str:gmatch('[^\\n]+') do
                    y = y + 1
                    self.tiles[y] = {}
                    local x = 0
                    for char in line:gmatch('.') do
                        x = x + 1
                        self.tiles[y][x] = tonumber(char) or 0
                    end
                    self.width = math.max(self.width, x)
                end
                self.height = y
            end

            function {$name}:getTile(x, y)
                if self.tiles[y] then
                    return self.tiles[y][x] or 0
                end
                return 0
            end

            function {$name}:setTile(x, y, value)
                if not self.tiles[y] then
                    self.tiles[y] = {}
                end
                self.tiles[y][x] = value
            end

            function {$name}:worldToTile(worldX, worldY)
                return math.floor(worldX / self.tileSize) + 1, math.floor(worldY / self.tileSize) + 1
            end

            function {$name}:tileToWorld(tileX, tileY)
                return (tileX - 1) * self.tileSize, (tileY - 1) * self.tileSize
            end

            function {$name}:isSolid(tileX, tileY)
                return self:getTile(tileX, tileY) > 0
            end

            function {$name}:draw(colors)
                colors = colors or {
                    [0] = {0.1, 0.1, 0.15},
                    [1] = {0.4, 0.4, 0.4},
                    [2] = {0.3, 0.6, 0.3},
                    [3] = {0.6, 0.4, 0.2},
                }
                for y = 1, self.height do
                    for x = 1, self.width do
                        local tile = self:getTile(x, y)
                        local color = colors[tile] or {1, 1, 1}
                        love.graphics.setColor(color)
                        love.graphics.rectangle('fill',
                            (x - 1) * self.tileSize,
                            (y - 1) * self.tileSize,
                            self.tileSize, self.tileSize)
                    end
                end
                love.graphics.setColor(1, 1, 1)
            end

            function {$name}:getPixelWidth()
                return self.width * self.tileSize
            end

            function {$name}:getPixelHeight()
                return self.height * self.tileSize
            end

            return {$name}
            LUA;
    }

    private function generateHud(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — HUD overlay with health bar, score, and notifications
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new()
                local self = setmetatable({}, {$name})
                self.score = 0
                self.health = 100
                self.maxHealth = 100
                self.notifications = {}
                self.fontSize = 16
                self.font = love.graphics.newFont(self.fontSize)
                return self
            end

            function {$name}:setHealth(current, max)
                self.health = current
                self.maxHealth = max or self.maxHealth
            end

            function {$name}:setScore(value)
                self.score = value
            end

            function {$name}:addScore(amount)
                self.score = self.score + amount
            end

            function {$name}:notify(text, duration)
                table.insert(self.notifications, {
                    text = text,
                    timer = duration or 3,
                    alpha = 1,
                })
            end

            function {$name}:update(dt)
                for i = #self.notifications, 1, -1 do
                    local n = self.notifications[i]
                    n.timer = n.timer - dt
                    if n.timer <= 0.5 then
                        n.alpha = n.timer / 0.5
                    end
                    if n.timer <= 0 then
                        table.remove(self.notifications, i)
                    end
                end
            end

            function {$name}:draw()
                local prevFont = love.graphics.getFont()
                love.graphics.setFont(self.font)

                -- Health bar
                local barX, barY = 10, 10
                local barW, barH = 200, 20
                local healthRatio = self.health / self.maxHealth

                love.graphics.setColor(0.2, 0.2, 0.2, 0.8)
                love.graphics.rectangle('fill', barX, barY, barW, barH, 3, 3)
                local r = healthRatio > 0.5 and (1 - healthRatio) * 2 or 1
                local g = healthRatio > 0.5 and 1 or healthRatio * 2
                love.graphics.setColor(r, g, 0, 0.9)
                love.graphics.rectangle('fill', barX, barY, barW * healthRatio, barH, 3, 3)
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf(self.health .. '/' .. self.maxHealth, barX, barY + 2, barW, 'center')

                -- Score
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf('Score: ' .. self.score, love.graphics.getWidth() - 210, 12, 200, 'right')

                -- Notifications
                local ny = love.graphics.getHeight() - 40
                for i = #self.notifications, 1, -1 do
                    local n = self.notifications[i]
                    love.graphics.setColor(1, 1, 1, n.alpha)
                    love.graphics.printf(n.text, 0, ny, love.graphics.getWidth(), 'center')
                    ny = ny - 25
                end

                love.graphics.setColor(1, 1, 1)
                love.graphics.setFont(prevFont)
            end

            return {$name}
            LUA;
    }

    private function generateStateMachine(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Generic finite state machine
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new()
                local self = setmetatable({}, {$name})
                self.states = {}
                self.current = nil
                self.currentName = ''
                self.previous = nil
                self.previousName = ''
                return self
            end

            function {$name}:add(name, state)
                self.states[name] = state
            end

            function {$name}:switch(name, ...)
                if not self.states[name] then
                    error('State not found: ' .. tostring(name))
                end

                if self.current and self.current.exit then
                    self.current:exit()
                end

                self.previous = self.current
                self.previousName = self.currentName
                self.current = self.states[name]
                self.currentName = name

                if self.current.enter then
                    self.current:enter(...)
                end
            end

            function {$name}:update(dt)
                if self.current and self.current.update then
                    self.current:update(dt)
                end
            end

            function {$name}:draw()
                if self.current and self.current.draw then
                    self.current:draw()
                end
            end

            function {$name}:keypressed(key)
                if self.current and self.current.keypressed then
                    self.current:keypressed(key)
                end
            end

            function {$name}:keyreleased(key)
                if self.current and self.current.keyreleased then
                    self.current:keyreleased(key)
                end
            end

            function {$name}:mousepressed(x, y, button)
                if self.current and self.current.mousepressed then
                    self.current:mousepressed(x, y, button)
                end
            end

            function {$name}:mousereleased(x, y, button)
                if self.current and self.current.mousereleased then
                    self.current:mousereleased(x, y, button)
                end
            end

            function {$name}:goBack(...)
                if self.previousName ~= '' then
                    self:switch(self.previousName, ...)
                end
            end

            function {$name}:is(name)
                return self.currentName == name
            end

            return {$name}
            LUA;
    }

    private function generateParticleSystem(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Configurable particle effect manager
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new()
                local self = setmetatable({}, {$name})
                self.systems = {}
                self.canvas = love.graphics.newCanvas(1, 1)
                return self
            end

            function {$name}:createEffect(id, options)
                options = options or {}
                local img = self.canvas
                local ps = love.graphics.newParticleSystem(img, options.max or 256)
                ps:setParticleLifetime(options.minLife or 0.5, options.maxLife or 1.5)
                ps:setEmissionRate(options.rate or 50)
                ps:setSizeVariation(options.sizeVariation or 0.5)
                ps:setLinearAcceleration(
                    options.minAx or -50, options.minAy or -50,
                    options.maxAx or 50, options.maxAy or 50)
                ps:setColors(
                    options.r1 or 1, options.g1 or 1, options.b1 or 1, options.a1 or 1,
                    options.r2 or 1, options.g2 or 1, options.b2 or 1, options.a2 or 0)
                ps:setSizes(options.startSize or 3, options.endSize or 0)
                ps:setSpeed(options.minSpeed or 50, options.maxSpeed or 150)
                ps:setSpread(options.spread or math.pi * 2)
                self.systems[id] = { ps = ps, x = 0, y = 0, active = false }
                return ps
            end

            function {$name}:emit(id, x, y, count)
                local sys = self.systems[id]
                if sys then
                    sys.x = x
                    sys.y = y
                    sys.ps:setPosition(x, y)
                    sys.ps:emit(count or 20)
                end
            end

            function {$name}:start(id, x, y)
                local sys = self.systems[id]
                if sys then
                    sys.x = x
                    sys.y = y
                    sys.ps:setPosition(x, y)
                    sys.active = true
                end
            end

            function {$name}:stop(id)
                local sys = self.systems[id]
                if sys then
                    sys.active = false
                    sys.ps:setEmissionRate(0)
                end
            end

            function {$name}:update(dt)
                for _, sys in pairs(self.systems) do
                    sys.ps:update(dt)
                end
            end

            function {$name}:draw()
                for _, sys in pairs(self.systems) do
                    love.graphics.draw(sys.ps)
                end
            end

            return {$name}
            LUA;
    }

    private function generateCollision(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — AABB and circle collision detection
            local {$name} = {}

            function {$name}.aabb(x1, y1, w1, h1, x2, y2, w2, h2)
                return x1 < x2 + w2 and x1 + w1 > x2 and
                       y1 < y2 + h2 and y1 + h1 > y2
            end

            function {$name}.circle(x1, y1, r1, x2, y2, r2)
                local dx = x2 - x1
                local dy = y2 - y1
                local dist = math.sqrt(dx * dx + dy * dy)
                return dist < r1 + r2
            end

            function {$name}.pointInRect(px, py, rx, ry, rw, rh)
                return px >= rx and px <= rx + rw and py >= ry and py <= ry + rh
            end

            function {$name}.pointInCircle(px, py, cx, cy, r)
                local dx = px - cx
                local dy = py - cy
                return dx * dx + dy * dy <= r * r
            end

            function {$name}.resolve(x1, y1, w1, h1, x2, y2, w2, h2)
                if not {$name}.aabb(x1, y1, w1, h1, x2, y2, w2, h2) then
                    return 0, 0, false
                end

                local overlapX, overlapY
                local cx1 = x1 + w1 / 2
                local cy1 = y1 + h1 / 2
                local cx2 = x2 + w2 / 2
                local cy2 = y2 + h2 / 2

                local dx = cx1 - cx2
                local dy = cy1 - cy2
                local halfW = (w1 + w2) / 2
                local halfH = (h1 + h2) / 2

                overlapX = dx > 0 and halfW - dx or -(halfW + dx)
                overlapY = dy > 0 and halfH - dy or -(halfH + dy)

                if math.abs(overlapX) < math.abs(overlapY) then
                    return overlapX, 0, true
                else
                    return 0, overlapY, true
                end
            end

            return {$name}
            LUA;
    }

    private function generateAnimation(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Sprite sheet animation with frame control
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new(image, frameWidth, frameHeight, options)
                options = options or {}
                local self = setmetatable({}, {$name})
                self.image = image
                self.frameWidth = frameWidth
                self.frameHeight = frameHeight
                self.animations = {}
                self.current = nil
                self.currentName = ''
                self.frame = 1
                self.timer = 0
                self.playing = true
                self.flipX = false
                self.flipY = false

                -- Build quads from spritesheet
                self.quads = {}
                local imgW, imgH = image:getDimensions()
                local cols = math.floor(imgW / frameWidth)
                local rows = math.floor(imgH / frameHeight)
                for row = 0, rows - 1 do
                    for col = 0, cols - 1 do
                        table.insert(self.quads, love.graphics.newQuad(
                            col * frameWidth, row * frameHeight,
                            frameWidth, frameHeight, imgW, imgH))
                    end
                end
                return self
            end

            function {$name}:addAnimation(name, frames, fps, loop)
                self.animations[name] = {
                    frames = frames,
                    fps = fps or 10,
                    loop = loop ~= false,
                }
            end

            function {$name}:play(name)
                if self.currentName == name then return end
                local anim = self.animations[name]
                if not anim then return end
                self.current = anim
                self.currentName = name
                self.frame = 1
                self.timer = 0
                self.playing = true
            end

            function {$name}:update(dt)
                if not self.current or not self.playing then return end

                self.timer = self.timer + dt
                local frameDuration = 1 / self.current.fps

                while self.timer >= frameDuration do
                    self.timer = self.timer - frameDuration
                    self.frame = self.frame + 1
                    if self.frame > #self.current.frames then
                        if self.current.loop then
                            self.frame = 1
                        else
                            self.frame = #self.current.frames
                            self.playing = false
                        end
                    end
                end
            end

            function {$name}:draw(x, y, r, sx, sy, ox, oy)
                if not self.current then return end
                local frameIndex = self.current.frames[self.frame]
                local quad = self.quads[frameIndex]
                if not quad then return end

                sx = (sx or 1) * (self.flipX and -1 or 1)
                sy = (sy or 1) * (self.flipY and -1 or 1)
                ox = ox or (self.flipX and self.frameWidth or 0)
                oy = oy or (self.flipY and self.frameHeight or 0)

                love.graphics.draw(self.image, quad, x, y, r or 0, sx, sy, ox, oy)
            end

            function {$name}:isPlaying()
                return self.playing
            end

            return {$name}
            LUA;
    }

    private function generateSaveLoad(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Game state serialization to JSON
            local {$name} = {}

            local function serialize(val)
                if type(val) == 'table' then
                    local isArray = #val > 0
                    local parts = {}
                    if isArray then
                        for _, v in ipairs(val) do
                            table.insert(parts, serialize(v))
                        end
                        return '[' .. table.concat(parts, ',') .. ']'
                    else
                        for k, v in pairs(val) do
                            table.insert(parts, '"' .. tostring(k) .. '":' .. serialize(v))
                        end
                        return '{' .. table.concat(parts, ',') .. '}'
                    end
                elseif type(val) == 'string' then
                    return '"' .. val:gsub('"', '\\\\"'):gsub('\\n', '\\\\n') .. '"'
                elseif type(val) == 'boolean' then
                    return val and 'true' or 'false'
                elseif type(val) == 'nil' then
                    return 'null'
                else
                    return tostring(val)
                end
            end

            local function deserialize(str)
                -- Simple JSON parser using Lua patterns
                -- For production, use a proper JSON library like dkjson
                local ok, result = pcall(function()
                    return load('return ' .. str:gsub('%[', '{'):gsub('%]', '}'):gsub('"(%w+)"%s*:', '["%1"]='):gsub('null', 'nil'):gsub('true', 'true'):gsub('false', 'false'))()
                end)
                return ok and result or nil
            end

            function {$name}.save(filename, data)
                local json = serialize(data)
                local success, err = love.filesystem.write(filename, json)
                return success, err
            end

            function {$name}.load(filename)
                if not love.filesystem.getInfo(filename) then
                    return nil, 'File not found: ' .. filename
                end
                local content, err = love.filesystem.read(filename)
                if not content then
                    return nil, err
                end
                local data = deserialize(content)
                if not data then
                    return nil, 'Failed to parse save file'
                end
                return data
            end

            function {$name}.exists(filename)
                return love.filesystem.getInfo(filename) ~= nil
            end

            function {$name}.delete(filename)
                return love.filesystem.remove(filename)
            end

            return {$name}
            LUA;
    }

    private function generateAudioManager(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Sound effect and music management
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new()
                local self = setmetatable({}, {$name})
                self.sounds = {}
                self.music = nil
                self.musicVolume = 0.7
                self.sfxVolume = 1.0
                self.muted = false
                return self
            end

            function {$name}:loadSound(id, path, pool_size)
                pool_size = pool_size or 3
                self.sounds[id] = {
                    sources = {},
                    pool_size = pool_size,
                    index = 1,
                }
                for i = 1, pool_size do
                    local source = love.audio.newSource(path, 'static')
                    self.sounds[id].sources[i] = source
                end
            end

            function {$name}:play(id, volume)
                if self.muted then return end
                local sound = self.sounds[id]
                if not sound then return end

                local source = sound.sources[sound.index]
                source:stop()
                source:setVolume((volume or 1) * self.sfxVolume)
                source:play()
                sound.index = (sound.index % sound.pool_size) + 1
            end

            function {$name}:playMusic(path, volume)
                if self.music then
                    self.music:stop()
                end
                self.music = love.audio.newSource(path, 'stream')
                self.music:setLooping(true)
                self.music:setVolume((volume or 1) * self.musicVolume)
                if not self.muted then
                    self.music:play()
                end
            end

            function {$name}:stopMusic()
                if self.music then
                    self.music:stop()
                end
            end

            function {$name}:setMusicVolume(vol)
                self.musicVolume = math.max(0, math.min(1, vol))
                if self.music then
                    self.music:setVolume(self.musicVolume)
                end
            end

            function {$name}:setSfxVolume(vol)
                self.sfxVolume = math.max(0, math.min(1, vol))
            end

            function {$name}:toggleMute()
                self.muted = not self.muted
                if self.music then
                    if self.muted then
                        self.music:pause()
                    else
                        self.music:play()
                    end
                end
            end

            return {$name}
            LUA;
    }

    private function generateLevelLoader(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Level data parsing and entity spawning
            local {$name} = {}
            {$name}.__index = {$name}

            function {$name}.new()
                local self = setmetatable({}, {$name})
                self.levels = {}
                self.currentLevel = nil
                self.currentIndex = 0
                return self
            end

            function {$name}:register(name, data)
                self.levels[name] = data
            end

            function {$name}:load(name)
                local data = self.levels[name]
                if not data then
                    return nil, 'Level not found: ' .. tostring(name)
                end

                self.currentLevel = data
                self.currentIndex = data.index or 0

                local result = {
                    name = name,
                    tilemap = data.tilemap or {},
                    entities = {},
                    spawns = data.spawns or {},
                    playerSpawn = data.playerSpawn or {x = 100, y = 100},
                    properties = data.properties or {},
                }

                -- Process spawn points
                for _, spawn in ipairs(result.spawns) do
                    table.insert(result.entities, {
                        type = spawn.type,
                        x = spawn.x,
                        y = spawn.y,
                        properties = spawn.properties or {},
                    })
                end

                return result
            end

            function {$name}:loadFromFile(filename)
                if not love.filesystem.getInfo(filename) then
                    return nil, 'File not found'
                end
                local content = love.filesystem.read(filename)
                if not content then
                    return nil, 'Failed to read file'
                end
                -- Expects a Lua table returned from the file
                local fn, err = load('return ' .. content)
                if not fn then
                    return nil, 'Parse error: ' .. tostring(err)
                end
                local ok, data = pcall(fn)
                if not ok then
                    return nil, 'Execution error: ' .. tostring(data)
                end
                return data
            end

            function {$name}:getCount()
                local count = 0
                for _ in pairs(self.levels) do count = count + 1 end
                return count
            end

            function {$name}:getCurrentName()
                if self.currentLevel then
                    return self.currentLevel.name or ''
                end
                return ''
            end

            return {$name}
            LUA;
    }

    // ── Scene Generators ────────────────────────────────────────────────

    private function generateMenuScene(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Main menu scene
            local {$name} = {}

            local buttons = {}
            local selectedIndex = 1
            local titleFont
            local buttonFont

            function {$name}:enter()
                titleFont = love.graphics.newFont(48)
                buttonFont = love.graphics.newFont(24)

                buttons = {
                    { text = 'Play', action = function() end },
                    { text = 'Settings', action = function() end },
                    { text = 'Quit', action = function() love.event.quit() end },
                }
                selectedIndex = 1
            end

            function {$name}:update(dt)
                -- Animate hover effects if desired
            end

            function {$name}:draw()
                local w = love.graphics.getWidth()
                local h = love.graphics.getHeight()

                -- Background
                love.graphics.setColor(0.1, 0.1, 0.15)
                love.graphics.rectangle('fill', 0, 0, w, h)

                -- Title
                love.graphics.setFont(titleFont)
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf('My Game', 0, h * 0.15, w, 'center')

                -- Buttons
                love.graphics.setFont(buttonFont)
                local buttonY = h * 0.45
                local buttonH = 50
                local buttonW = 200
                local spacing = 15

                for i, btn in ipairs(buttons) do
                    local x = (w - buttonW) / 2
                    local y = buttonY + (i - 1) * (buttonH + spacing)

                    if i == selectedIndex then
                        love.graphics.setColor(0.3, 0.5, 0.8)
                    else
                        love.graphics.setColor(0.2, 0.2, 0.3)
                    end
                    love.graphics.rectangle('fill', x, y, buttonW, buttonH, 8, 8)

                    love.graphics.setColor(1, 1, 1)
                    love.graphics.printf(btn.text, x, y + 12, buttonW, 'center')
                end

                -- Instructions
                love.graphics.setColor(0.5, 0.5, 0.5)
                love.graphics.setFont(love.graphics.newFont(14))
                love.graphics.printf('Arrow keys to navigate, Enter to select', 0, h - 40, w, 'center')

                love.graphics.setColor(1, 1, 1)
            end

            function {$name}:keypressed(key)
                if key == 'up' then
                    selectedIndex = selectedIndex > 1 and selectedIndex - 1 or #buttons
                elseif key == 'down' then
                    selectedIndex = selectedIndex < #buttons and selectedIndex + 1 or 1
                elseif key == 'return' or key == 'space' then
                    buttons[selectedIndex].action()
                end
            end

            function {$name}:exit()
                -- Cleanup
            end

            return {$name}
            LUA;
    }

    private function generateGameplayScene(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Core gameplay scene
            local {$name} = {}

            local paused = false
            local entities = {}
            local score = 0
            local timer = 0

            function {$name}:enter(level_data)
                paused = false
                entities = {}
                score = 0
                timer = 0

                -- Initialize game objects here
                -- e.g., player = Player.new(100, 100)
                -- e.g., load level from level_data
            end

            function {$name}:update(dt)
                if paused then return end
                timer = timer + dt

                -- Update game logic here
                -- e.g., player:update(dt)
                -- e.g., check collisions
                -- e.g., spawn enemies
            end

            function {$name}:draw()
                -- Draw game world
                love.graphics.setColor(0.1, 0.1, 0.15)
                love.graphics.rectangle('fill', 0, 0, love.graphics.getWidth(), love.graphics.getHeight())

                -- Draw entities
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf('Gameplay Scene — Score: ' .. score, 10, 10, 400, 'left')
                love.graphics.printf('Time: ' .. string.format('%.1f', timer), 10, 30, 400, 'left')

                -- Draw game objects here
                -- e.g., player:draw()

                if paused then
                    love.graphics.setColor(0, 0, 0, 0.5)
                    love.graphics.rectangle('fill', 0, 0, love.graphics.getWidth(), love.graphics.getHeight())
                    love.graphics.setColor(1, 1, 1)
                    love.graphics.printf('PAUSED', 0, love.graphics.getHeight() / 2 - 20,
                        love.graphics.getWidth(), 'center')
                end
            end

            function {$name}:keypressed(key)
                if key == 'escape' or key == 'p' then
                    paused = not paused
                end
                -- Forward input to game objects
                -- e.g., player:keypressed(key)
            end

            function {$name}:exit()
                entities = {}
            end

            return {$name}
            LUA;
    }

    private function generatePauseScene(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Pause overlay
            local {$name} = {}

            local buttons = {}
            local selectedIndex = 1
            local font

            function {$name}:enter(resume_callback, quit_callback)
                font = love.graphics.newFont(24)
                selectedIndex = 1
                buttons = {
                    { text = 'Resume', action = resume_callback or function() end },
                    { text = 'Restart', action = function() end },
                    { text = 'Quit to Menu', action = quit_callback or function() end },
                }
            end

            function {$name}:update(dt)
                -- Pause menu animations
            end

            function {$name}:draw()
                local w = love.graphics.getWidth()
                local h = love.graphics.getHeight()

                -- Semi-transparent overlay
                love.graphics.setColor(0, 0, 0, 0.6)
                love.graphics.rectangle('fill', 0, 0, w, h)

                -- Pause title
                love.graphics.setFont(font)
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf('PAUSED', 0, h * 0.25, w, 'center')

                -- Buttons
                local buttonY = h * 0.4
                local buttonH = 45
                local buttonW = 180
                local spacing = 12

                for i, btn in ipairs(buttons) do
                    local x = (w - buttonW) / 2
                    local y = buttonY + (i - 1) * (buttonH + spacing)

                    if i == selectedIndex then
                        love.graphics.setColor(0.3, 0.5, 0.8, 0.9)
                    else
                        love.graphics.setColor(0.2, 0.2, 0.3, 0.7)
                    end
                    love.graphics.rectangle('fill', x, y, buttonW, buttonH, 6, 6)

                    love.graphics.setColor(1, 1, 1)
                    love.graphics.printf(btn.text, x, y + 10, buttonW, 'center')
                end

                love.graphics.setColor(1, 1, 1)
            end

            function {$name}:keypressed(key)
                if key == 'up' then
                    selectedIndex = selectedIndex > 1 and selectedIndex - 1 or #buttons
                elseif key == 'down' then
                    selectedIndex = selectedIndex < #buttons and selectedIndex + 1 or 1
                elseif key == 'return' or key == 'space' then
                    buttons[selectedIndex].action()
                elseif key == 'escape' then
                    buttons[1].action()  -- Resume
                end
            end

            function {$name}:exit()
            end

            return {$name}
            LUA;
    }

    private function generateGameOverScene(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Game over screen
            local {$name} = {}

            local buttons = {}
            local selectedIndex = 1
            local finalScore = 0
            local titleFont
            local font

            function {$name}:enter(score, retry_callback, menu_callback)
                finalScore = score or 0
                titleFont = love.graphics.newFont(40)
                font = love.graphics.newFont(22)
                selectedIndex = 1
                buttons = {
                    { text = 'Retry', action = retry_callback or function() end },
                    { text = 'Main Menu', action = menu_callback or function() end },
                }
            end

            function {$name}:update(dt)
            end

            function {$name}:draw()
                local w = love.graphics.getWidth()
                local h = love.graphics.getHeight()

                -- Background
                love.graphics.setColor(0.1, 0.05, 0.05)
                love.graphics.rectangle('fill', 0, 0, w, h)

                -- Game Over text
                love.graphics.setFont(titleFont)
                love.graphics.setColor(0.9, 0.2, 0.2)
                love.graphics.printf('GAME OVER', 0, h * 0.2, w, 'center')

                -- Score
                love.graphics.setFont(font)
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf('Final Score: ' .. finalScore, 0, h * 0.35, w, 'center')

                -- Buttons
                local buttonY = h * 0.5
                local buttonH = 45
                local buttonW = 180
                local spacing = 12

                for i, btn in ipairs(buttons) do
                    local x = (w - buttonW) / 2
                    local y = buttonY + (i - 1) * (buttonH + spacing)

                    if i == selectedIndex then
                        love.graphics.setColor(0.3, 0.5, 0.8)
                    else
                        love.graphics.setColor(0.2, 0.2, 0.3)
                    end
                    love.graphics.rectangle('fill', x, y, buttonW, buttonH, 6, 6)

                    love.graphics.setColor(1, 1, 1)
                    love.graphics.printf(btn.text, x, y + 10, buttonW, 'center')
                end

                love.graphics.setColor(1, 1, 1)
            end

            function {$name}:keypressed(key)
                if key == 'up' then
                    selectedIndex = selectedIndex > 1 and selectedIndex - 1 or #buttons
                elseif key == 'down' then
                    selectedIndex = selectedIndex < #buttons and selectedIndex + 1 or 1
                elseif key == 'return' or key == 'space' then
                    buttons[selectedIndex].action()
                end
            end

            function {$name}:exit()
            end

            return {$name}
            LUA;
    }

    private function generateSettingsScene(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Settings menu
            local {$name} = {}

            local options = {}
            local selectedIndex = 1
            local font

            function {$name}:enter(back_callback)
                font = love.graphics.newFont(20)
                selectedIndex = 1

                options = {
                    { text = 'Music Volume', type = 'slider', value = 0.7, min = 0, max = 1, step = 0.1 },
                    { text = 'SFX Volume', type = 'slider', value = 1.0, min = 0, max = 1, step = 0.1 },
                    { text = 'Fullscreen', type = 'toggle', value = false },
                    { text = 'VSync', type = 'toggle', value = true },
                    { text = 'Back', type = 'action', action = back_callback or function() end },
                }
            end

            function {$name}:update(dt)
            end

            function {$name}:draw()
                local w = love.graphics.getWidth()
                local h = love.graphics.getHeight()

                love.graphics.setColor(0.1, 0.1, 0.15)
                love.graphics.rectangle('fill', 0, 0, w, h)

                love.graphics.setFont(love.graphics.newFont(32))
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf('Settings', 0, 30, w, 'center')

                love.graphics.setFont(font)
                local startY = 120
                local rowH = 50

                for i, opt in ipairs(options) do
                    local y = startY + (i - 1) * rowH

                    if i == selectedIndex then
                        love.graphics.setColor(0.2, 0.3, 0.5, 0.5)
                        love.graphics.rectangle('fill', w * 0.15, y - 5, w * 0.7, rowH - 10, 4, 4)
                    end

                    love.graphics.setColor(1, 1, 1)
                    love.graphics.print(opt.text, w * 0.2, y + 5)

                    if opt.type == 'slider' then
                        local barX = w * 0.55
                        local barW = w * 0.25
                        love.graphics.setColor(0.3, 0.3, 0.3)
                        love.graphics.rectangle('fill', barX, y + 12, barW, 8, 4, 4)
                        love.graphics.setColor(0.4, 0.7, 1)
                        love.graphics.rectangle('fill', barX, y + 12, barW * opt.value, 8, 4, 4)
                        love.graphics.setColor(1, 1, 1)
                        love.graphics.printf(string.format('%.0f%%', opt.value * 100), barX + barW + 10, y + 5, 60, 'left')
                    elseif opt.type == 'toggle' then
                        love.graphics.setColor(opt.value and {0.3, 0.8, 0.3} or {0.5, 0.2, 0.2})
                        love.graphics.printf(opt.value and 'ON' or 'OFF', w * 0.55, y + 5, 100, 'center')
                    end
                end

                love.graphics.setColor(1, 1, 1)
            end

            function {$name}:keypressed(key)
                if key == 'up' then
                    selectedIndex = selectedIndex > 1 and selectedIndex - 1 or #options
                elseif key == 'down' then
                    selectedIndex = selectedIndex < #options and selectedIndex + 1 or 1
                elseif key == 'return' or key == 'space' then
                    local opt = options[selectedIndex]
                    if opt.type == 'toggle' then
                        opt.value = not opt.value
                    elseif opt.type == 'action' and opt.action then
                        opt.action()
                    end
                elseif key == 'left' then
                    local opt = options[selectedIndex]
                    if opt.type == 'slider' then
                        opt.value = math.max(opt.min, opt.value - opt.step)
                    end
                elseif key == 'right' then
                    local opt = options[selectedIndex]
                    if opt.type == 'slider' then
                        opt.value = math.min(opt.max, opt.value + opt.step)
                    end
                elseif key == 'escape' then
                    local lastOpt = options[#options]
                    if lastOpt.action then lastOpt.action() end
                end
            end

            function {$name}:exit()
            end

            return {$name}
            LUA;
    }

    private function generateLevelSelectScene(string $name): string
    {
        return <<<LUA
            -- {$name}.lua — Level selection grid
            local {$name} = {}

            local levels = {}
            local selectedIndex = 1
            local cols = 4
            local font
            local titleFont
            local onSelect

            function {$name}:enter(level_list, select_callback, back_callback)
                font = love.graphics.newFont(18)
                titleFont = love.graphics.newFont(32)
                levels = level_list or {}
                selectedIndex = 1
                onSelect = select_callback or function() end

                -- Default levels if none provided
                if #levels == 0 then
                    for i = 1, 12 do
                        table.insert(levels, {
                            name = 'Level ' .. i,
                            unlocked = i <= 3,
                            stars = i <= 2 and math.random(1, 3) or 0,
                        })
                    end
                end
            end

            function {$name}:update(dt)
            end

            function {$name}:draw()
                local w = love.graphics.getWidth()
                local h = love.graphics.getHeight()

                love.graphics.setColor(0.1, 0.1, 0.15)
                love.graphics.rectangle('fill', 0, 0, w, h)

                love.graphics.setFont(titleFont)
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf('Select Level', 0, 20, w, 'center')

                love.graphics.setFont(font)
                local cellSize = 80
                local padding = 15
                local gridW = cols * (cellSize + padding) - padding
                local startX = (w - gridW) / 2
                local startY = 90

                for i, level in ipairs(levels) do
                    local col = (i - 1) % cols
                    local row = math.floor((i - 1) / cols)
                    local x = startX + col * (cellSize + padding)
                    local y = startY + row * (cellSize + padding)

                    -- Cell background
                    if not level.unlocked then
                        love.graphics.setColor(0.15, 0.15, 0.2)
                    elseif i == selectedIndex then
                        love.graphics.setColor(0.3, 0.5, 0.8)
                    else
                        love.graphics.setColor(0.2, 0.25, 0.35)
                    end
                    love.graphics.rectangle('fill', x, y, cellSize, cellSize, 8, 8)

                    -- Level number or lock
                    love.graphics.setColor(1, 1, 1)
                    if level.unlocked then
                        love.graphics.printf(tostring(i), x, y + 15, cellSize, 'center')
                        -- Stars
                        local starY = y + cellSize - 25
                        for s = 1, 3 do
                            love.graphics.setColor(s <= (level.stars or 0) and {1, 0.85, 0} or {0.3, 0.3, 0.3})
                            love.graphics.printf('★', x + (s - 1) * (cellSize / 3), starY, cellSize / 3, 'center')
                        end
                    else
                        love.graphics.setColor(0.4, 0.4, 0.4)
                        love.graphics.printf('🔒', x, y + 25, cellSize, 'center')
                    end
                end

                love.graphics.setColor(0.5, 0.5, 0.5)
                love.graphics.printf('Arrow keys to navigate, Enter to select, Escape to go back',
                    0, h - 35, w, 'center')
                love.graphics.setColor(1, 1, 1)
            end

            function {$name}:keypressed(key)
                local row = math.floor((selectedIndex - 1) / cols)
                local col = (selectedIndex - 1) % cols

                if key == 'right' then
                    if col < cols - 1 and selectedIndex < #levels then
                        selectedIndex = selectedIndex + 1
                    end
                elseif key == 'left' then
                    if col > 0 then
                        selectedIndex = selectedIndex - 1
                    end
                elseif key == 'down' then
                    if selectedIndex + cols <= #levels then
                        selectedIndex = selectedIndex + cols
                    end
                elseif key == 'up' then
                    if selectedIndex - cols >= 1 then
                        selectedIndex = selectedIndex - cols
                    end
                elseif key == 'return' or key == 'space' then
                    local level = levels[selectedIndex]
                    if level and level.unlocked then
                        onSelect(selectedIndex, level)
                    end
                end
            end

            function {$name}:exit()
            end

            return {$name}
            LUA;
    }
}
