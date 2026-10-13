import { useTranslation } from "react-i18next"
import { Link, useParams } from "react-router-dom"
import { EmptyState, MissingValue, MissingWorkspace, PermissionBlock, useTimeLabel } from "../components/ui"
import { useStore } from "../store"
import { useWorkspaceRoute } from "../use-workspace"
import type { Vehicle } from "../types"

function positionOf(vehicle: Vehicle) {
  if (vehicle.x === null || vehicle.y === null || vehicle.z === null) return null
  const format = (value: number) => value.toFixed(1)
  return `${format(vehicle.x)}, ${format(vehicle.y)}, ${format(vehicle.z)}`
}

export function VehicleDetailPage() {
  const { t, i18n } = useTranslation()
  const stamp = useTimeLabel()
  const { vehicleId = "" } = useParams()
  const { vehicles, moduleAccess, farmByGameId } = useStore()
  const { id, workspace, allowed } = useWorkspaceRoute()
  const vehicle = vehicles.find((item) => item.id === vehicleId && item.workspaceId === id)
  const fill = vehicle?.fill ? (i18n.language === "en" ? vehicle.fill.en : vehicle.fill.de) : null
  const equipmentAccess = workspace ? moduleAccess(workspace.id, "equipment") : null
  const farmName = vehicle ? farmByGameId(id, vehicle.farmId)?.name : null
  const categoryKey =
    vehicle?.category === "harvester" ? "vehicles.selfpropelled" : vehicle ? `vehicles.${vehicle.category}` : ""

  if (!workspace) return <MissingWorkspace />
  if (!allowed || !equipmentAccess) return <PermissionBlock />
  if (!vehicle) {
    return (
      <EmptyState
        title={t("vehicles.unknownTitle")}
        body={t("vehicles.unknownBody")}
        action={
          <Link className="btn btn-secondary" to={`/w/${id}/equipment`}>
            {t("back")}
          </Link>
        }
      />
    )
  }

  return (
    <section className="panel">
      <p className="hint">{t("vehicles.observed", { time: stamp(vehicle.observedAt, t("unavailable")) })}</p>
      <h1>{vehicle.name}</h1>
      <span className="badge">{t("demo")}</span>
      <dl className="detail">
        <div>
          <dt>{t("vehicles.category")}</dt>
          <dd>{t(categoryKey)}</dd>
        </div>
        <div>
          <dt>{t("vehicles.farm")}</dt>
          <dd>
            {farmName ??
              (vehicle.farmId === null ? t("unavailable") : t("vehicles.farmId", { id: vehicle.farmId }))}
          </dd>
        </div>
        <div>
          <dt>{t("vehicles.position")}</dt>
          <dd><MissingValue text={positionOf(vehicle)} /></dd>
        </div>
        <div>
          <dt>{t("vehicles.heading")}</dt>
          <dd><MissingValue text={null} /></dd>
        </div>
        <div>
          <dt>{t("vehicles.fill")}</dt>
          <dd><MissingValue text={fill} /></dd>
        </div>
        <div>
          <dt>{t("vehicles.hours")}</dt>
          <dd>{vehicle.hours === null ? t("unavailable") : vehicle.hours.toLocaleString(i18n.language)}</dd>
        </div>
      </dl>
      <p>
        <Link className="btn btn-secondary" to={`/w/${id}/equipment`}>
          {t("back")}
        </Link>
      </p>
    </section>
  )
}
