-- {{TITLE}} — Puzzle Template
-- A match-3 style grid puzzle game

local coqui = require('lib.coqui_api')

-- ── Constants ───────────────────────────────────────────────────────

local GRID_COLS = 8
local GRID_ROWS = 8
local CELL_SIZE = 50
local CELL_PAD = 4
local GRID_OFFSET_X = 0
local GRID_OFFSET_Y = 60

local COLORS = {
    {0.9, 0.3, 0.3},  -- Red
    {0.3, 0.7, 0.9},  -- Blue
    {0.3, 0.9, 0.4},  -- Green
    {0.9, 0.8, 0.2},  -- Yellow
    {0.8, 0.4, 0.9},  -- Purple
}

-- ── Game State ──────────────────────────────────────────────────────

local grid = {}
local selected = nil
local score = 0
local moves = 0
local animating = false

-- ── Grid Functions ──────────────────────────────────────────────────

local function initGrid()
    grid = {}
    for row = 1, GRID_ROWS do
        grid[row] = {}
        for col = 1, GRID_COLS do
            grid[row][col] = math.random(1, #COLORS)
        end
    end
    -- Clear initial matches
    local matched = true
    while matched do
        matched = false
        for row = 1, GRID_ROWS do
            for col = 1, GRID_COLS do
                -- Horizontal
                if col <= GRID_COLS - 2 and
                   grid[row][col] == grid[row][col + 1] and
                   grid[row][col] == grid[row][col + 2] then
                    grid[row][col] = math.random(1, #COLORS)
                    matched = true
                end
                -- Vertical
                if row <= GRID_ROWS - 2 and
                   grid[row][col] == grid[row + 1][col] and
                   grid[row][col] == grid[row + 2][col] then
                    grid[row][col] = math.random(1, #COLORS)
                    matched = true
                end
            end
        end
    end
end

local function findMatches()
    local matches = {}
    -- Horizontal
    for row = 1, GRID_ROWS do
        local col = 1
        while col <= GRID_COLS - 2 do
            local val = grid[row][col]
            if val > 0 then
                local endCol = col
                while endCol + 1 <= GRID_COLS and grid[row][endCol + 1] == val do
                    endCol = endCol + 1
                end
                if endCol - col >= 2 then
                    for c = col, endCol do
                        matches[row .. ',' .. c] = true
                    end
                end
                col = endCol + 1
            else
                col = col + 1
            end
        end
    end
    -- Vertical
    for col = 1, GRID_COLS do
        local row = 1
        while row <= GRID_ROWS - 2 do
            local val = grid[row][col]
            if val > 0 then
                local endRow = row
                while endRow + 1 <= GRID_ROWS and grid[endRow + 1][col] == val do
                    endRow = endRow + 1
                end
                if endRow - row >= 2 then
                    for r = row, endRow do
                        matches[r .. ',' .. col] = true
                    end
                end
                row = endRow + 1
            else
                row = row + 1
            end
        end
    end
    return matches
end

local function clearMatches(matches)
    local count = 0
    for key, _ in pairs(matches) do
        local row, col = key:match('(%d+),(%d+)')
        row, col = tonumber(row), tonumber(col)
        grid[row][col] = 0
        count = count + 1
    end
    return count
end

local function applyGravity()
    for col = 1, GRID_COLS do
        local writeRow = GRID_ROWS
        for row = GRID_ROWS, 1, -1 do
            if grid[row][col] > 0 then
                grid[writeRow][col] = grid[row][col]
                if writeRow ~= row then
                    grid[row][col] = 0
                end
                writeRow = writeRow - 1
            end
        end
        -- Fill empty cells at top
        for row = writeRow, 1, -1 do
            grid[row][col] = math.random(1, #COLORS)
        end
    end
end

local function swapCells(r1, c1, r2, c2)
    grid[r1][c1], grid[r2][c2] = grid[r2][c2], grid[r1][c1]
end

local function areAdjacent(r1, c1, r2, c2)
    return (math.abs(r1 - r2) + math.abs(c1 - c2)) == 1
end

local function processBoard()
    local totalCleared = 0
    local matches = findMatches()
    while next(matches) do
        local cleared = clearMatches(matches)
        totalCleared = totalCleared + cleared
        applyGravity()
        matches = findMatches()
    end
    if totalCleared > 0 then
        score = score + totalCleared * 10
    end
    return totalCleared
end

-- ── Love Callbacks ──────────────────────────────────────────────────

function love.load()
    coqui.configure({ endpoint = 'http://localhost:3300' })
    local w = GRID_COLS * (CELL_SIZE + CELL_PAD) + CELL_PAD
    GRID_OFFSET_X = (love.graphics.getWidth() - w) / 2
    love.graphics.setBackgroundColor(0.1, 0.1, 0.15)
    initGrid()
end

function love.update(dt)
    coqui.poll()
end

function love.draw()
    -- Title and score
    love.graphics.setColor(1, 1, 1)
    love.graphics.printf('{{TITLE}}', 0, 10, love.graphics.getWidth(), 'center')
    love.graphics.printf('Score: ' .. score .. '  |  Moves: ' .. moves,
        0, 35, love.graphics.getWidth(), 'center')

    -- Grid
    for row = 1, GRID_ROWS do
        for col = 1, GRID_COLS do
            local x = GRID_OFFSET_X + (col - 1) * (CELL_SIZE + CELL_PAD) + CELL_PAD
            local y = GRID_OFFSET_Y + (row - 1) * (CELL_SIZE + CELL_PAD) + CELL_PAD
            local val = grid[row][col]

            -- Background
            love.graphics.setColor(0.18, 0.18, 0.22)
            love.graphics.rectangle('fill', x, y, CELL_SIZE, CELL_SIZE, 6, 6)

            -- Cell color
            if val > 0 and COLORS[val] then
                love.graphics.setColor(COLORS[val])
                love.graphics.rectangle('fill', x + 3, y + 3, CELL_SIZE - 6, CELL_SIZE - 6, 4, 4)
            end

            -- Selection highlight
            if selected and selected.row == row and selected.col == col then
                love.graphics.setColor(1, 1, 1, 0.5)
                love.graphics.setLineWidth(3)
                love.graphics.rectangle('line', x, y, CELL_SIZE, CELL_SIZE, 6, 6)
                love.graphics.setLineWidth(1)
            end
        end
    end

    -- Instructions
    love.graphics.setColor(0.5, 0.5, 0.5)
    love.graphics.printf('Click to select, click adjacent cell to swap. R to restart.',
        0, love.graphics.getHeight() - 25, love.graphics.getWidth(), 'center')
end

function love.mousepressed(mx, my, button)
    if button ~= 1 then return end

    -- Find clicked cell
    for row = 1, GRID_ROWS do
        for col = 1, GRID_COLS do
            local x = GRID_OFFSET_X + (col - 1) * (CELL_SIZE + CELL_PAD) + CELL_PAD
            local y = GRID_OFFSET_Y + (row - 1) * (CELL_SIZE + CELL_PAD) + CELL_PAD
            if mx >= x and mx <= x + CELL_SIZE and my >= y and my <= y + CELL_SIZE then
                if not selected then
                    selected = { row = row, col = col }
                else
                    if areAdjacent(selected.row, selected.col, row, col) then
                        -- Swap
                        swapCells(selected.row, selected.col, row, col)
                        local cleared = processBoard()
                        if cleared == 0 then
                            -- Swap back if no match
                            swapCells(selected.row, selected.col, row, col)
                        else
                            moves = moves + 1
                        end
                    end
                    selected = nil
                end
                return
            end
        end
    end
    selected = nil
end

function love.keypressed(key)
    if key == 'r' then
        score = 0
        moves = 0
        selected = nil
        initGrid()
    end
    if key == 'escape' then
        love.event.quit()
    end
end
