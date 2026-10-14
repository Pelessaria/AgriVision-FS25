import { useEffect, useMemo, useState } from "react"
import { useTranslation } from "react-i18next"
import { useNavigate } from "react-router-dom"
import { Tractor } from "lucide-react"
import { apiWorkspaceVehicles } from "../api"
import { EmptyState, MissingValue, MissingWorkspace, PermissionBlock, useTimeLabel } from "../components/ui"
import { farmDisplayName } from "../farm-label"
import { useWorkspaceRoute } from "../use-workspace"
import { useStore } from "../store"
import type { Vehicle, VehicleCategory } from "../types"

type EquipmentFilter = "all" | "tractor" | "trailer" | "tool" | "selfpropelled"

function positionOf(vehicle: Vehicle) {
  if (vehicle.x === null || vehicle.y === null || vehicle.z === null) return null
  const format = (value: number) => value.toFixed(1)
  return `${format(vehicle.x)}, ${format(vehicle.y)}, ${format(vehicle.z)}`
}

function matchesFilter(vehicle: Vehicle, filter: EquipmentFilter) {
  if (filter === "all") return true
  if (filter === "selfpropelled") return vehicle.category === "harvester"
  return vehicle.category === filter
}

function categoryLabelKey(category: VehicleCategory) {
  if (category === "harvester") return "vehicles.selfpropelled"
  return `vehicles.${category}`
}

export function VehiclesPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const stamp = useTimeLabel()
  const {
    vehicles,
    liveMode,
    moduleAccess,
    selectedFarm,
    farmByGameId,
    replaceWorkspaceFarms,
    replaceWorkspaceVehicles,
    upsertWorkspace,
  } = useStore()
  const { id, workspace, allowed } = useWorkspaceRoute()
  const [query, setQuery] = useState("")
  const [filter, setFilter] = useState<EquipmentFilter>("all")
  const [loading, setLoading] = useState(false)
  const equipmentAccess = workspace ? moduleAccess(workspace.id, "equipment") : null
  const farm = workspace ? selectedFarm(workspace.id) : null

  useEffect(() => {
    if (!workspace || !liveMode || !allowed || !equipmentAccess) return
    let cancelled = false
    setLoading(true)
    void (async () => {
      try {
        const result = await apiWorkspaceVehicles(workspace.id)
        if (cancelled) return
        replaceWorkspaceVehicles(workspace.id, result.vehicles)
        if (result.farms) replaceWorkspaceFarms(workspace.id, result.farms)
        if (result.workspace) upsertWorkspace(result.workspace)
      } catch {
        /* keep previous */
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [workspace?.id, liveMode, allowed, equipmentAccess])

  const rows = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase()
    const inWorkspace = vehicles.filter((vehicle) => vehicle.workspaceId === id)
    const anyFarmBound = inWorkspace.some((vehicle) => vehicle.farmId !== null)
    return inWorkspace
      .filter((vehicle) => {
        if (!farm) return true
        if (vehicle.farmId !== null) {
          return Number(vehicle.farmId) === Number(farm.gameFarmId)
        }
        // No farmId on any vehicle yet (matching still warming) — keep list visible.
        return !anyFarmBound
      })
      .filter((vehicle) => matchesFilter(vehicle, filter))
      .filter((vehicle) => (needle ? vehicle.name.toLocaleLowerCase().includes(needle) : true))
      .sort((a, b) => a.name.localeCompare(b.name, i18n.language))
  }, [farm, filter, i18n.language, id, query, vehicles])

  if (!workspace) return <MissingWorkspace />
  if (!allowed || !equipmentAccess) return <PermissionBlock />

  const filters: { id: EquipmentFilter; label: string }[] = [
    { id: "all", label: t("vehicles.all") },
    { id: "tractor", label: t("vehicles.tractor") },
    { id: "trailer", label: t("vehicles.trailer") },
    { id: "tool", label: t("vehicles.tool") },
    { id: "selfpropelled", label: t("vehicles.selfpropelled") },
  ]

  return (
    <section>
      <div className="page-head">
        <div>
          <h1>{t("modules.equipment")}</h1>
          <p>
            {farm
              ? t("vehicles.leadFarm", { farm: farmDisplayName(farm, t) })
              : t("vehicles.lead")}
          </p>
        </div>
      </div>

      <nav className="access-tabs equipment-tabs" role="tablist" aria-label={t("vehicles.category")}>
        {filters.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={filter === item.id}
            className={filter === item.id ? "access-tab active" : "access-tab"}
            onClick={() => setFilter(item.id)}
          >
            {item.label}
          </button>
        ))}
      </nav>

      <div className="filters">
        <label className="field">
          <span>{t("vehicles.search")}</span>
          <input value={query} onChange={(event) => setQuery(event.target.value)} />
        </label>
      </div>

      {rows.length === 0 ? (
        <EmptyState
          title={t("vehicles.emptyTitle")}
          body={loading ? t("farm.discovering") : t("vehicles.emptyBody")}
        />
      ) : (
        <>
          <div className="table-wrap panel">
            <table>
              <thead>
                <tr>
                  <th className="equip-icon-col">{t("vehicles.icon")}</th>
                  <th>{t("vehicles.detail")}</th>
                  <th>{t("vehicles.category")}</th>
                  <th>{t("vehicles.farm")}</th>
                  <th>{t("vehicles.position")}</th>
                  <th>{t("vehicles.hours")}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((vehicle) => {
                  const linked = farmByGameId(id, vehicle.farmId)
                  const farmName = linked
                    ? farmDisplayName(linked, t)
                    : vehicle.farmId === null
                      ? t("unavailable")
                      : t("vehicles.farmId", { id: vehicle.farmId })
                  return (
                    <tr
                      key={vehicle.id}
                      tabIndex={0}
                      onClick={() => navigate(`/w/${id}/equipment/${vehicle.id}`)}
                      onKeyDown={(event) => {
                        if (event.key === "Enter") navigate(`/w/${id}/equipment/${vehicle.id}`)
                      }}
                    >
                      <td>
                        <span className="equip-icon" aria-hidden="true">
                          {vehicle.iconUrl ? <img src={vehicle.iconUrl} alt="" /> : <Tractor className="icon" />}
                        </span>
                      </td>
                      <td>{vehicle.name}</td>
                      <td>{t(categoryLabelKey(vehicle.category))}</td>
                      <td>{farmName}</td>
                      <td>{positionOf(vehicle) ?? t("unavailable")}</td>
                      <td>{vehicle.hours === null ? t("unavailable") : vehicle.hours.toLocaleString(i18n.language)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div className="mobile-list">
            {rows.map((vehicle) => (
              <button
                key={vehicle.id}
                type="button"
                className="vehicle-card"
                onClick={() => navigate(`/w/${id}/equipment/${vehicle.id}`)}
              >
                <span className="equip-icon" aria-hidden="true">
                  {vehicle.iconUrl ? <img src={vehicle.iconUrl} alt="" /> : <Tractor className="icon" />}
                </span>
                <strong>{vehicle.name}</strong>
                <span>{t(categoryLabelKey(vehicle.category))}</span>
                <span className="faint">
                  {(() => {
                    const linked = farmByGameId(id, vehicle.farmId)
                    if (linked) return farmDisplayName(linked, t)
                    if (vehicle.farmId === null) return t("unavailable")
                    return t("vehicles.farmId", { id: vehicle.farmId })
                  })()}
                </span>
                <span className="faint">
                  <MissingValue text={positionOf(vehicle)} />
                </span>
                <span className="faint">{stamp(vehicle.observedAt, t("unavailable"))}</span>
              </button>
            ))}
          </div>
        </>
      )}
    </section>
  )
}
