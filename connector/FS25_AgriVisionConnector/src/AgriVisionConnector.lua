--[[
  AgriVision Connector v0.1 — dedicated-server snapshot writer.

  Primary transport: files under modSettings/FS25_AgriVisionConnector/
  HTTPS from Lua is NOT required and not used here (sandbox / performance open).

  Contract: docs/CONNECTOR-CONTRACT.md

  This file uses only widely attested Engine entry points where possible.
  Farm balance / name reads are guarded; missing values are omitted (UI: unavailable).
]]

AgriVisionConnector = {}
AgriVisionConnector.MOD_NAME = "FS25_AgriVisionConnector"
AgriVisionConnector.VERSION = "0.1.0.0"
AgriVisionConnector.SCHEMA = "1"
AgriVisionConnector.TICK_MS = 5000
AgriVisionConnector.FULL_VEHICLE_EVERY_MS = 60000

local function log(msg)
    print(string.format("AgriVisionConnector: %s", tostring(msg)))
end

local function utcNow()
    -- Engine date helpers vary; ISO-ish fallback for file metadata.
    if getDate ~= nil then
        return tostring(getDate("%Y-%m-%dT%H:%M:%SZ"))
    end
    return "unknown"
end

local function ensureDir(path)
    if createFolder ~= nil then
        createFolder(path)
    end
end

function AgriVisionConnector:init()
    if g_server == nil then
        log("not a server — idle")
        self.active = false
        return
    end

    self.active = true
    self.generation = 0
    self.lastTickAt = 0
    self.lastFullVehiclesAt = 0
    self.processedCommands = {}

    local profile = getUserProfileAppPath()
    self.baseDir = profile .. "modSettings/" .. self.MOD_NAME .. "/"
    self.stateDir = self.baseDir .. "state/"
    self.iconsDir = self.baseDir .. "icons/"

    ensureDir(profile .. "modSettings/")
    ensureDir(self.baseDir)
    ensureDir(self.stateDir)
    ensureDir(self.iconsDir)

    log("active, baseDir=" .. self.baseDir)
    self:writeHello()
    self:writeVehiclesFull()
    self:writeIconsManifestStartup()
end

function AgriVisionConnector:nextGeneration()
    self.generation = self.generation + 1
    return self.generation
end

function AgriVisionConnector:xmlEscape(value)
    if value == nil then
        return ""
    end
    local s = tostring(value)
    s = string.gsub(s, "&", "&amp;")
    s = string.gsub(s, "<", "&lt;")
    s = string.gsub(s, ">", "&gt;")
    s = string.gsub(s, '"', "&quot;")
    return s
end

function AgriVisionConnector:writeTextFile(path, body)
    -- Prefer Engine XML helpers when available; fall back to io for local dedicated tests.
    if io ~= nil and io.open ~= nil then
        local tmp = path .. ".tmp"
        local f = io.open(tmp, "w")
        if f == nil then
            log("cannot write " .. tmp)
            return false
        end
        f:write(body)
        f:close()
        os.remove(path)
        local ok, err = os.rename(tmp, path)
        if not ok then
            -- rename may be restricted; keep tmp content by copying
            local dest = io.open(path, "w")
            if dest == nil then
                log("rename/copy failed for " .. path .. " " .. tostring(err))
                return false
            end
            local src = io.open(tmp, "r")
            if src ~= nil then
                dest:write(src:read("*a"))
                src:close()
            end
            dest:close()
            os.remove(tmp)
        end
        return true
    end
    log("no io.open — hello/vehicles not written (check Engine XML API on target build)")
    return false
end

function AgriVisionConnector:collectFarmsXml()
    local parts = { "<farms>" }
    if g_farmManager ~= nil and g_farmManager.getFarms ~= nil then
        local farms = g_farmManager:getFarms()
        if farms ~= nil then
            for _, farm in pairs(farms) do
                if farm ~= nil and farm.farmId ~= nil and farm.farmId > 0 then
                    local attrs = string.format(' id="%s"', self:xmlEscape(farm.farmId))
                    if farm.name ~= nil and farm.name ~= "" then
                        attrs = attrs .. string.format(' name="%s"', self:xmlEscape(farm.name))
                    end
                    if farm.getBalance ~= nil then
                        local ok, balance = pcall(function()
                            return farm:getBalance()
                        end)
                        if ok and type(balance) == "number" then
                            attrs = attrs .. string.format(' money="%s"', self:xmlEscape(balance))
                        end
                    end
                    table.insert(parts, string.format("    <farm%s />", attrs))
                end
            end
        end
    end
    table.insert(parts, "</farms>")
    return table.concat(parts, "\n")
end

function AgriVisionConnector:writeHello()
    local gen = self:nextGeneration()
    local gameTime = 0
    if g_currentMission ~= nil and g_currentMission.environment ~= nil and g_currentMission.environment.dayTime ~= nil then
        gameTime = g_currentMission.environment.dayTime
    end
    local body = string.format(
        [[<?xml version="1.0" encoding="utf-8"?>
<agrivisionConnector version="%s" schema="%s" generation="%s" writtenAtUtc="%s" gameTimeMs="%s">
%s
</agrivisionConnector>
]],
        self:xmlEscape(self.VERSION),
        self.SCHEMA,
        tostring(gen),
        self:xmlEscape(utcNow()),
        self:xmlEscape(gameTime),
        self:collectFarmsXml()
    )
    self:writeTextFile(self.baseDir .. "hello.xml", body)
end

function AgriVisionConnector:collectVehiclesFullXml()
    local parts = {}
    -- Vehicle enumeration APIs differ by build; keep a safe empty full snapshot if unavailable.
    if g_currentMission == nil or g_currentMission.vehicleSystem == nil then
        return "    <!-- vehicleSystem unavailable on this build -->\n"
    end
    -- Placeholder: concrete iteration is filled after the dedicated-server probe
    -- (vehicles.xml via feed remains the Phase-4 source of truth for meta).
    table.insert(parts, "    <!-- full vehicle walk: enable after server probe confirms iterators -->\n")
    return table.concat(parts, "")
end

function AgriVisionConnector:writeVehiclesFull()
    local gen = self:nextGeneration()
    local body = string.format(
        [[<?xml version="1.0" encoding="utf-8"?>
<vehiclesFull schema="%s" generation="%s" writtenAtUtc="%s" kind="full">
%s</vehiclesFull>
]],
        self.SCHEMA,
        tostring(gen),
        self:xmlEscape(utcNow()),
        self:collectVehiclesFullXml()
    )
    self:writeTextFile(self.stateDir .. "vehicles_full.xml", body)
    self.lastFullVehiclesAt = g_time or 0
end

function AgriVisionConnector:writeIconsManifestStartup()
    -- Icon extraction from mod ZIPs is host/path dependent.
    -- v0 writes an empty manifest so the backend can GC stale icons safely.
    local gen = self:nextGeneration()
    local body = string.format(
        [[<?xml version="1.0" encoding="utf-8"?>
<iconsManifest schema="%s" generation="%s" writtenAtUtc="%s" scan="startup">
</iconsManifest>
]],
        self.SCHEMA,
        tostring(gen),
        self:xmlEscape(utcNow())
    )
    self:writeTextFile(self.stateDir .. "icons_manifest.xml", body)
end

function AgriVisionConnector:processCommands()
    local path = self.baseDir .. "command.xml"
    if io == nil or io.open == nil then
        return
    end
    local f = io.open(path, "r")
    if f == nil then
        return
    end
    local raw = f:read("*a")
    f:close()
    if raw == nil or raw == "" then
        return
    end

    local id = string.match(raw, 'id="([^"]+)"')
    local kind = string.match(raw, 'type="([^"]+)"') or string.match(raw, "<(%w+)")
    if id == nil then
        return
    end
    if self.processedCommands[id] then
        return
    end

    local status = "unsupported"
    if kind == "ping" or kind == "noop" or string.find(raw, "<ping", 1, true) or string.find(raw, "<noop", 1, true) then
        status = "ok"
    end

    self.processedCommands[id] = true
    local result = string.format(
        [[<?xml version="1.0" encoding="utf-8"?>
<commandResult schema="%s" id="%s" status="%s" writtenAtUtc="%s" connectorVersion="%s" />
]],
        self.SCHEMA,
        self:xmlEscape(id),
        status,
        self:xmlEscape(utcNow()),
        self:xmlEscape(self.VERSION)
    )
    self:writeTextFile(self.baseDir .. "command_result.xml", result)
end

function AgriVisionConnector:update(dt)
    if not self.active then
        return
    end
    local now = g_time or 0
    if now - self.lastTickAt < self.TICK_MS then
        return
    end
    self.lastTickAt = now

    local ok, err = pcall(function()
        self:writeHello()
        self:processCommands()
        if now - self.lastFullVehiclesAt >= self.FULL_VEHICLE_EVERY_MS then
            self:writeVehiclesFull()
        end
    end)
    if not ok then
        log("tick error: " .. tostring(err))
    end
end

-- Bootstrap
local function bootstrap()
    local mod = AgriVisionConnector
    mod:init()
    if mod.active and Mission00 ~= nil then
        -- Prefer mission update hook when present
    end
end

bootstrap()

addModEventListener({
    update = function(_, dt)
        AgriVisionConnector:update(dt)
    end,
})
