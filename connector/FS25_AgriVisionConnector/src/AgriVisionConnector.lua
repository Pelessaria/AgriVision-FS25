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
AgriVisionConnector.VERSION = "0.1.1.1"
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

function AgriVisionConnector:vehicleWorldPos(vehicle)
    if vehicle == nil then
        return nil, nil, nil
    end
    local ok, x, y, z = pcall(function()
        if vehicle.rootNode ~= nil and getWorldTranslation ~= nil then
            return getWorldTranslation(vehicle.rootNode)
        end
        if vehicle.components ~= nil and vehicle.components[1] ~= nil and vehicle.components[1].node ~= nil and getWorldTranslation ~= nil then
            return getWorldTranslation(vehicle.components[1].node)
        end
        return nil, nil, nil
    end)
    if ok then
        return x, y, z
    end
    return nil, nil, nil
end

function AgriVisionConnector:collectVehiclesFullXml()
    local parts = {}
    if g_currentMission == nil then
        return "    <!-- mission unavailable -->\n"
    end

    local vehiclesTable = nil
    if g_currentMission.vehicleSystem ~= nil and g_currentMission.vehicleSystem.vehicles ~= nil then
        vehiclesTable = g_currentMission.vehicleSystem.vehicles
    elseif g_currentMission.vehicles ~= nil then
        vehiclesTable = g_currentMission.vehicles
    end
    if vehiclesTable == nil then
        return "    <!-- vehicle list unavailable on this build -->\n"
    end

    local count = 0
    for _, vehicle in pairs(vehiclesTable) do
        if vehicle ~= nil then
            local farmId = nil
            if vehicle.getOwnerFarmId ~= nil then
                local ok, id = pcall(function()
                    return vehicle:getOwnerFarmId()
                end)
                if ok and type(id) == "number" and id > 0 then
                    farmId = id
                end
            elseif type(vehicle.ownerFarmId) == "number" and vehicle.ownerFarmId > 0 then
                farmId = vehicle.ownerFarmId
            end

            local uniqueId = nil
            if vehicle.getUniqueId ~= nil then
                local ok, uid = pcall(function()
                    return vehicle:getUniqueId()
                end)
                if ok and uid ~= nil and tostring(uid) ~= "" then
                    uniqueId = tostring(uid)
                end
            elseif vehicle.uniqueId ~= nil then
                uniqueId = tostring(vehicle.uniqueId)
            end

            local filename = vehicle.configFileName or vehicle.xmlFilename or ""
            local name = ""
            if vehicle.getName ~= nil then
                local ok, n = pcall(function()
                    return vehicle:getName()
                end)
                if ok and n ~= nil then
                    name = tostring(n)
                end
            end
            if name == "" and vehicle.name ~= nil then
                name = tostring(vehicle.name)
            end

            local operatingTime = nil
            if type(vehicle.operatingTime) == "number" then
                -- Engine stores ms; contract expects hours.
                operatingTime = vehicle.operatingTime / 3600000
            end

            local x, y, z = self:vehicleWorldPos(vehicle)
            local attrs = {}
            if uniqueId ~= nil then
                table.insert(attrs, string.format('uniqueId="%s"', self:xmlEscape(uniqueId)))
            end
            if filename ~= "" then
                table.insert(attrs, string.format('filename="%s"', self:xmlEscape(filename)))
            end
            if farmId ~= nil then
                table.insert(attrs, string.format('farmId="%s"', self:xmlEscape(farmId)))
            end
            if name ~= "" then
                table.insert(attrs, string.format('name="%s"', self:xmlEscape(name)))
            end
            if operatingTime ~= nil then
                table.insert(attrs, string.format('operatingTime="%.2f"', operatingTime))
            end
            if x ~= nil and z ~= nil then
                table.insert(attrs, string.format('x="%.2f" y="%.2f" z="%.2f"', x, y or 0, z))
            end
            if #attrs > 0 then
                table.insert(parts, string.format("  <vehicle %s />\n", table.concat(attrs, " ")))
                count = count + 1
            end
        end
    end

    if count == 0 then
        return "    <!-- no vehicles enumerated -->\n"
    end
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
