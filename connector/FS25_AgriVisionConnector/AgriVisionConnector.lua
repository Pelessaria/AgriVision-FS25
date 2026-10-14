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
AgriVisionConnector.VERSION = "0.1.2.6"
AgriVisionConnector.SCHEMA = "1"
AgriVisionConnector.TICK_MS = 5000
AgriVisionConnector.FULL_VEHICLE_EVERY_MS = 60000
AgriVisionConnector.WAIT_LOG_EVERY_MS = 30000

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
    self.tickAccumMs = 0
    self.fullAccumMs = 0
    self.waitLogAccumMs = 0
    self.lastFullVehiclesAt = 0
    self.processedCommands = {}

    local profile = getUserProfileAppPath()
    self.baseDir = profile .. "modSettings/" .. self.MOD_NAME .. "/"
    self.stateDir = self.baseDir .. "state/"
    self.iconsDir = self.baseDir .. "icons/"
    self.storeIconsDir = self.iconsDir .. "store/"
    self.modIconsDir = self.iconsDir .. "mods/"
    self.pendingStoreIcons = {}
    self.copiedStorePaths = {}
    -- Vehicles/store icons must wait until the mission has spawned the fleet.
    -- Mod load runs before the savegame — scanning here always yields unique=0.
    self.vehiclesPrimed = false

    ensureDir(profile .. "modSettings/")
    ensureDir(self.baseDir)
    ensureDir(self.stateDir)
    ensureDir(self.iconsDir)
    ensureDir(self.storeIconsDir)
    -- mod icons deferred (needed later for mod list UI, not vehicle overview)

    log(
        string.format(
            "active, baseDir=%s copyFile=%s fileExists=%s getFileSize=%s deleteFile=%s XMLFile=%s",
            self.baseDir,
            tostring(copyFile ~= nil),
            tostring(fileExists ~= nil),
            tostring(getFileSize ~= nil),
            tostring(deleteFile ~= nil),
            tostring(XMLFile ~= nil)
        )
    )
    self:writeHello()
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
    -- Dedicated-server sandbox: io.open works, os.* is often nil (crash: os.remove).
    if io == nil or io.open == nil then
        log("no io.open — hello/vehicles not written")
        return false
    end
    local f = io.open(path, "w")
    if f == nil then
        log("cannot write " .. path)
        return false
    end
    f:write(body)
    f:close()
    return true
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
    self.pendingStoreIcons = {}
    local vehiclesTable = self:missionVehicles()
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

            local modKey = self:modKeyFromFilename(filename)
            local storeKey = self:storeKeyFromFilename(filename)
            local imageSrc = self:vehicleStoreImage(vehicle, filename)
            if storeKey ~= nil and imageSrc ~= nil and self.pendingStoreIcons[storeKey] == nil then
                self.pendingStoreIcons[storeKey] = {
                    src = imageSrc,
                    filename = filename,
                }
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
            if storeKey ~= nil then
                table.insert(attrs, string.format('storeKey="%s"', self:xmlEscape(storeKey)))
            end
            if modKey ~= nil then
                table.insert(attrs, string.format('modKey="%s"', self:xmlEscape(modKey)))
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
    local vehiclesXml = self:collectVehiclesFullXml()
    local pendingCount = 0
    for _ in pairs(self.pendingStoreIcons or {}) do
        pendingCount = pendingCount + 1
    end
    local body = string.format(
        [[<?xml version="1.0" encoding="utf-8"?>
<vehiclesFull schema="%s" generation="%s" writtenAtUtc="%s" kind="full">
%s</vehiclesFull>
]],
        self.SCHEMA,
        tostring(gen),
        self:xmlEscape(utcNow()),
        vehiclesXml
    )
    self:writeTextFile(self.stateDir .. "vehicles_full.xml", body)
    log(string.format("vehicles_full written storeIconCandidates=%s", tostring(pendingCount)))
    self:writeStoreIconsManifest()
    self.lastFullVehiclesAt = g_time or 0
end

function AgriVisionConnector:modKeyFromFilename(filename)
    if filename == nil or filename == "" then
        return nil
    end
    local path = tostring(filename):gsub("\\", "/")
    local key = string.match(path, "%$moddir%$([^/]+)")
    if key == nil then
        key = string.match(path, "/(FS25_[^/]+)/")
    end
    if key == nil then
        key = string.match(path, "^(FS25_[^/]+)/")
    end
    if key == nil or key == "" then
        return nil
    end
    key = key:gsub("%.zip$", "")
    return key
end

--- Stable key for vehicle store image (type-level, shared by duplicates).
function AgriVisionConnector:storeKeyFromFilename(filename)
    if filename == nil or filename == "" then
        return nil
    end
    local path = tostring(filename):gsub("\\", "/"):lower()
    path = path:gsub("%$moddir%$", "mod/")
    path = path:gsub("%.xml$", "")
    path = path:gsub("[^a-z0-9]+", "_"):gsub("^_", ""):gsub("_$", "")
    if path == "" then
        return nil
    end
    if #path > 96 then
        path = path:sub(-96)
    end
    return path
end

function AgriVisionConnector:resolveStoreImagePath(raw)
    if raw == nil then
        return nil
    end
    local path = tostring(raw)
    if path == "" then
        return nil
    end
    if NetworkUtil ~= nil and NetworkUtil.convertFromNetworkFilename ~= nil then
        local ok, converted = pcall(function()
            return NetworkUtil.convertFromNetworkFilename(path)
        end)
        if ok and converted ~= nil and tostring(converted) ~= "" then
            path = tostring(converted)
        end
    end

    local candidates = { path }

    -- GIANTS often stores relative or $data/… paths; Utils.getFilename expands them.
    if Utils ~= nil and Utils.getFilename ~= nil then
        local ok, resolved = pcall(function()
            return Utils.getFilename(path, "")
        end)
        if ok and resolved ~= nil and tostring(resolved) ~= "" then
            table.insert(candidates, tostring(resolved))
        end
    end

    -- $moddir$ModName/… → profile mods folder (folder or zip).
    local modName, modRest = string.match(path, "^%$moddir%$([^/]+)/(.+)$")
    if modName ~= nil and modRest ~= nil then
        local profile = nil
        if getUserProfileAppPath ~= nil then
            local ok, p = pcall(getUserProfileAppPath)
            if ok then
                profile = p
            end
        end
        if profile ~= nil then
            table.insert(candidates, profile .. "mods/" .. modName .. "/" .. modRest)
            table.insert(candidates, profile .. "mods/" .. modName .. ".zip/" .. modRest)
        end
        if g_modNameToDirectory ~= nil and g_modNameToDirectory[modName] ~= nil then
            table.insert(candidates, g_modNameToDirectory[modName] .. modRest)
        end
    end

    -- Strip $data/ and try under game install if getAppBasePath exists.
    local stripped = path
    if string.sub(stripped, 1, 6) == "$data/" then
        stripped = "data/" .. string.sub(stripped, 7)
    elseif string.sub(stripped, 1, 5) == "$data" then
        stripped = "data" .. string.sub(stripped, 6)
    end
    if getAppBasePath ~= nil then
        local ok, base = pcall(getAppBasePath)
        if ok and base ~= nil and tostring(base) ~= "" then
            table.insert(candidates, tostring(base) .. stripped)
            if stripped ~= path then
                table.insert(candidates, tostring(base) .. path)
            end
        end
    end

    -- Prefer paths the Engine can see. Keep a fallback for copyFile — some zip
    -- virtual paths copy even when fileExists is picky — but copyIconFile will
    -- delete empty destinations (0.1.2.5 wrote 0-byte stubs).
    local fallback = nil
    for _, candidate in ipairs(candidates) do
        if candidate ~= nil and candidate ~= "" then
            if self:fileLooksPresent(candidate) then
                return candidate
            end
            if fallback == nil then
                fallback = candidate
            end
        end
    end
    return fallback
end

function AgriVisionConnector:vehicleStoreImage(vehicle, filename)
    local candidates = {}
    if vehicle ~= nil and vehicle.getImageFilename ~= nil then
        local ok, image = pcall(function()
            return vehicle:getImageFilename()
        end)
        if ok and image ~= nil and tostring(image) ~= "" then
            table.insert(candidates, tostring(image))
        end
    end
    if vehicle ~= nil and vehicle.storeItem ~= nil and vehicle.storeItem.imageFilename ~= nil then
        local image = tostring(vehicle.storeItem.imageFilename)
        if image ~= "" then
            table.insert(candidates, image)
        end
    end
    if g_storeManager ~= nil and g_storeManager.getItemByXMLFilename ~= nil and filename ~= nil and filename ~= "" then
        local ok, item = pcall(function()
            return g_storeManager:getItemByXMLFilename(filename)
        end)
        if ok and item ~= nil and item.imageFilename ~= nil and tostring(item.imageFilename) ~= "" then
            table.insert(candidates, tostring(item.imageFilename))
        end
    end
    for _, raw in ipairs(candidates) do
        local resolved = self:resolveStoreImagePath(raw)
        if resolved ~= nil then
            return resolved
        end
    end
    return nil
end

function AgriVisionConnector:missionVehicles()
    if g_currentMission == nil then
        return nil
    end
    local vs = g_currentMission.vehicleSystem
    if vs ~= nil then
        if vs.vehicles ~= nil then
            return vs.vehicles
        end
        -- Some builds expose vehicles only via getter.
        if vs.getVehicles ~= nil then
            local ok, list = pcall(function()
                return vs:getVehicles()
            end)
            if ok and list ~= nil then
                return list
            end
        end
    end
    if g_currentMission.vehicles ~= nil then
        return g_currentMission.vehicles
    end
    return nil
end

function AgriVisionConnector:countVehicles(vehiclesTable)
    if vehiclesTable == nil then
        return 0
    end
    local n = 0
    for _, vehicle in pairs(vehiclesTable) do
        if vehicle ~= nil then
            n = n + 1
        end
    end
    return n
end

function AgriVisionConnector:missionHasVehicles()
    return self:countVehicles(self:missionVehicles()) > 0
end

function AgriVisionConnector:missionReadyHint()
    if g_currentMission == nil then
        return "g_currentMission=nil"
    end
    local vs = g_currentMission.vehicleSystem
    if vs == nil then
        return "vehicleSystem=nil"
    end
    local n = self:countVehicles(self:missionVehicles())
    return string.format("vehicles=%s", tostring(n))
end

--- Dedicated FS25 sandbox: io.open only allows write mode ('w'). Never use io read
--- for existence or binary copy (Farmhand / GIANTS rule). Use Engine file APIs.
function AgriVisionConnector:engineFileExists(path)
    if path == nil or path == "" or fileExists == nil then
        return false
    end
    local ok, exists = pcall(fileExists, path)
    return ok and exists == true
end

function AgriVisionConnector:engineFileSize(path)
    if path == nil or path == "" or getFileSize == nil then
        return nil
    end
    local ok, size = pcall(getFileSize, path)
    if ok and type(size) == "number" then
        return size
    end
    return nil
end

function AgriVisionConnector:deletePath(path)
    if path == nil or path == "" or deleteFile == nil then
        return
    end
    pcall(deleteFile, path)
end

function AgriVisionConnector:fileLooksPresent(path)
    if not self:engineFileExists(path) then
        return false
    end
    local size = self:engineFileSize(path)
    if size ~= nil and size <= 0 then
        return false
    end
    return true
end

function AgriVisionConnector:copyIconFile(src, dest)
    if src == nil or dest == nil or src == "" or dest == "" then
        return false
    end
    -- Non-empty dest already on disk (or verified this session).
    if self.copiedStorePaths ~= nil and self.copiedStorePaths[dest] then
        return true
    end
    if self:fileLooksPresent(dest) then
        if self.copiedStorePaths == nil then
            self.copiedStorePaths = {}
        end
        self.copiedStorePaths[dest] = true
        return true
    end
    -- Drop 0-byte leftovers from older connector builds.
    if self:engineFileExists(dest) then
        self:deletePath(dest)
    end
    if copyFile == nil then
        return false
    end
    local srcVisible = self:engineFileExists(src)
    local ok = pcall(function()
        copyFile(src, dest, true)
    end)
    if not ok or not self:engineFileExists(dest) then
        self:deletePath(dest)
        return false
    end
    -- Reject empty stubs when the Engine can report size (FS25 dedicated).
    local size = self:engineFileSize(dest)
    if size ~= nil and size <= 0 then
        self:deletePath(dest)
        return false
    end
    -- Without getFileSize, only keep dest if the Engine could see the source.
    -- Blind copyFile(missingSrc) is what left 96× 0-byte icons in 0.1.2.5.
    if size == nil and not srcVisible then
        self:deletePath(dest)
        return false
    end
    if self.copiedStorePaths == nil then
        self.copiedStorePaths = {}
    end
    self.copiedStorePaths[dest] = true
    return true
end

function AgriVisionConnector:iconExtFromPath(path)
    local lower = string.lower(tostring(path or ""))
    if string.find(lower, "%.dds") then
        return "dds"
    end
    if string.find(lower, "%.jpe?g") then
        return "jpg"
    end
    return "png"
end

--- Vehicle store icons only (unique types in the current mission). Mod-pack icons are deferred.
function AgriVisionConnector:writeStoreIconsManifest()
    ensureDir(self.storeIconsDir)
    local gen = self:nextGeneration()
    local rows = {}
    local copiedCount = 0
    local failedCount = 0
    local pending = self.pendingStoreIcons or {}
    local failSamples = {}

    for storeKey, meta in pairs(pending) do
        local src = meta.src
        local ext = self:iconExtFromPath(src)
        local rel = string.format("icons/store/%s.%s", storeKey, ext)
        local dest = self.baseDir .. rel
        local ok = self:copyIconFile(src, dest)
        if ok then
            copiedCount = copiedCount + 1
            table.insert(
                rows,
                string.format(
                    '  <store key="%s" filename="%s" iconFile="%s" />\n',
                    self:xmlEscape(storeKey),
                    self:xmlEscape(meta.filename or ""),
                    self:xmlEscape(rel)
                )
            )
        else
            failedCount = failedCount + 1
            if #failSamples < 5 then
                table.insert(failSamples, string.format("%s src=%s", tostring(storeKey), tostring(src)))
            end
        end
    end

    table.sort(rows)
    local body = string.format(
        [[<?xml version="1.0" encoding="utf-8"?>
<iconsManifest schema="%s" generation="%s" writtenAtUtc="%s" scan="vehicles" kind="store">
%s</iconsManifest>
]],
        self.SCHEMA,
        tostring(gen),
        self:xmlEscape(utcNow()),
        table.concat(rows, "")
    )
    self:writeTextFile(self.stateDir .. "icons_manifest.xml", body)
    log(
        string.format(
            "store icons unique=%s copiedOrPresent=%s failed=%s copyFile=%s fileExists=%s getFileSize=%s",
            tostring(#rows),
            tostring(copiedCount),
            tostring(failedCount),
            tostring(copyFile ~= nil),
            tostring(fileExists ~= nil),
            tostring(getFileSize ~= nil)
        )
    )
    for _, sample in ipairs(failSamples) do
        log("store icon fail: " .. sample)
    end
end

function AgriVisionConnector:processCommands()
    local path = self.baseDir .. "command.xml"
    -- Dedicated sandbox: never io.open read. Engine XML API only (Farmhand pattern).
    if XMLFile == nil or XMLFile.loadIfExists == nil then
        return
    end
    if fileExists ~= nil then
        local okExists, exists = pcall(fileExists, path)
        if okExists and not exists then
            return
        end
    end

    local xmlFile = nil
    local okLoad = pcall(function()
        xmlFile = XMLFile.loadIfExists("agriVisionCmd", path)
    end)
    if not okLoad or xmlFile == nil then
        return
    end

    local id = nil
    local kind = nil
    pcall(function()
        id = xmlFile:getString("command#id") or xmlFile:getString("commands#id")
        kind = xmlFile:getString("command#type")
            or xmlFile:getString("commands#cmd")
            or xmlFile:getString("command#cmd")
    end)
    pcall(function()
        xmlFile:delete()
    end)

    if id == nil or id == "" then
        return
    end
    if self.processedCommands[id] then
        if deleteFile ~= nil then
            pcall(deleteFile, path)
        end
        return
    end

    local status = "unsupported"
    if kind == nil or kind == "" or kind == "ping" or kind == "noop" then
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
    if deleteFile ~= nil then
        pcall(deleteFile, path)
    end
end

function AgriVisionConnector:tick(dt)
    if not self.active then
        return
    end
    -- Prefer frame dt; dedicated g_time can stay 0 and starved the old throttle.
    local step = tonumber(dt) or 0
    if step < 0 then
        step = 0
    end
    -- Engine sometimes reports seconds.
    if step > 0 and step < 1 then
        step = step * 1000
    end
    -- If called with nil/0 dt, still advance so a live hook eventually fires work.
    if step <= 0 then
        step = 16
    end

    self.tickAccumMs = (self.tickAccumMs or 0) + step
    if self.tickAccumMs < self.TICK_MS then
        return
    end
    self.tickAccumMs = 0
    self.fullAccumMs = (self.fullAccumMs or 0) + self.TICK_MS
    self.waitLogAccumMs = (self.waitLogAccumMs or 0) + self.TICK_MS

    local ok, err = pcall(function()
        self:writeHello()
        self:processCommands()
        local hasVehicles = self:missionHasVehicles()
        if not self.vehiclesPrimed and not hasVehicles then
            if self.waitLogAccumMs >= self.WAIT_LOG_EVERY_MS then
                self.waitLogAccumMs = 0
                log("waiting for vehicles (" .. self:missionReadyHint() .. ")")
            end
            return
        end
        local due = (not self.vehiclesPrimed) or (self.fullAccumMs >= self.FULL_VEHICLE_EVERY_MS)
        if due and hasVehicles then
            self.fullAccumMs = 0
            self:writeVehiclesFull()
            self.vehiclesPrimed = true
        end
    end)
    if not ok then
        log("tick error: " .. tostring(err))
    end
end

function AgriVisionConnector:installMissionHook()
    if self.missionHookInstalled then
        return
    end
    if FSBaseMission == nil or Utils == nil or Utils.appendedFunction == nil then
        return
    end
    FSBaseMission.update = Utils.appendedFunction(FSBaseMission.update, function(_, dt)
        AgriVisionConnector:tick(dt)
    end)
    self.missionHookInstalled = true
    log("FSBaseMission.update hook installed")
end

-- addModEventListener calls listener:update(dt). Also keep FSBaseMission hook as backup
-- (dedicated servers have been observed to skip mod listeners after load).
function AgriVisionConnector:update(dt)
    self:installMissionHook()
    self:tick(dt)
end

-- Strong self-reference so the listener table cannot be GC'd.
AgriVisionConnector._listener = AgriVisionConnector
addModEventListener(AgriVisionConnector)
AgriVisionConnector:installMissionHook()
AgriVisionConnector:init()
log("listener registered (missionHook=" .. tostring(AgriVisionConnector.missionHookInstalled == true) .. ")")
