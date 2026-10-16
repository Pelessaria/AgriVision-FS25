import { type SyntheticEvent } from "react"
import { useTranslation } from "react-i18next"
import { Link, useParams } from "react-router-dom"
import { Package, Tractor } from "lucide-react"
import { EmptyState, MissingValue, MissingWorkspace, PermissionBlock, useTimeLabel } from "../components/ui"
import { farmDisplayName } from "../farm-label"
import { useStore } from "../store"
import { useWorkspaceRoute } from "../use-workspace"
import type { Vehicle } from "../types"

function positionOf(vehicle: Vehicle) {
  if (vehicle.x === null || vehicle.z === null) return null
  const format = (value: number) => value.toFixed(0)
  return `${format(vehicle.x)} / ${format(vehicle.z)}`
}

function vehicleDisplayName(name: string) {
  const raw = name.trim()
  const match = raw.match(/^\$l10n_(?:shopItem_)?(.+)$/i)
  if (!match?.[1]) return raw
  return match[1]
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .replace(/[_-]+/g, " ")
    .trim()
}

function modZipLabel(modKey: string | null | undefined) {
  if (!modKey) return null
  return modKey.toLowerCase().endsWith(".zip") ? modKey : `${modKey}.zip`
}

function modDisplayName(modKey: string | null | undefined) {
  if (!modKey) return null
  return modKey.replace(/^FS25_/i, "").replace(/_/g, " ")
}

function shortFilename(path: string | null | undefined) {
  if (!path) return null
  const normalized = path.replace(/\\/g, "/")
  const parts = normalized.split("/")
  return parts[parts.length - 1] || normalized
}

function onIconError(event: SyntheticEvent<HTMLImageElement>) {
  const wrap = event.currentTarget.parentElement
  event.currentTarget.remove()
  wrap?.querySelector(".icon-fallback")?.classList.remove("is-hidden")
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
  const linkedFarm = vehicle ? farmByGameId(id, vehicle.farmId) : null
  const farmName = linkedFarm ? farmDisplayName(linkedFarm, t) : null
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

  const modKey = vehicle.modKey ?? null
  const modName = modDisplayName(modKey)
  const modZip = modZipLabel(modKey)
  const fileLabel = shortFilename(vehicle.filename)
  const farmLabel =
    farmName ?? (vehicle.farmId === null ? t("unavailable") : t("vehicles.farmId", { id: vehicle.farmId }))
  const hours =
    vehicle.hours === null
      ? t("unavailable")
      : vehicle.hours.toLocaleString(i18n.language, { maximumFractionDigits: 1 })

  return (
    <section className="equip-detail">
      <Link className="equip-back" to={`/w/${id}/equipment`}>
        {t("vehicles.backToList")}
      </Link>

      <div className="equip-detail-layout">
        <div className="equip-detail-media" aria-hidden="true">
          {vehicle.iconUrl ? <img src={vehicle.iconUrl} alt="" onError={onIconError} /> : null}
          <Tractor className={`icon icon-fallback${vehicle.iconUrl ? " is-hidden" : ""}`} />
        </div>

        <div className="equip-detail-copy">
          <p className="equip-kicker">
            {t(categoryKey)}
            <span>·</span>
            {t("vehicles.observed", { time: stamp(vehicle.observedAt, t("unavailable")) })}
          </p>
          <h1>{vehicleDisplayName(vehicle.name)}</h1>

          <div className="equip-stats">
            <div>
              <span>{t("vehicles.hours")}</span>
              <strong>{hours}</strong>
            </div>
            <div>
              <span>{t("vehicles.farm")}</span>
              <strong>{farmLabel}</strong>
            </div>
            <div>
              <span>{t("vehicles.position")}</span>
              <strong>
                <MissingValue text={positionOf(vehicle)} />
              </strong>
            </div>
            {fill ? (
              <div>
                <span>{t("vehicles.fill")}</span>
                <strong>{fill}</strong>
              </div>
            ) : null}
          </div>

          {modKey ? (
            <article className="equip-mod-card">
              <span className="equip-mod-icon" aria-hidden="true">
                {vehicle.modIconUrl ? <img src={vehicle.modIconUrl} alt="" onError={onIconError} /> : null}
                <Package className={`icon icon-fallback${vehicle.modIconUrl ? " is-hidden" : ""}`} />
              </span>
              <div className="equip-mod-copy">
                <p className="equip-kicker">{t("vehicles.modTitle")}</p>
                <strong>{modName}</strong>
                <code>{modZip}</code>
                {fileLabel ? <span className="equip-mod-file">{fileLabel}</span> : null}
              </div>
            </article>
          ) : (
            <p className="equip-base">{t("vehicles.modBaseHint")}</p>
          )}

          {vehicle.filename ? (
            <p className="equip-path" title={vehicle.filename}>
              {vehicle.filename}
            </p>
          ) : null}
        </div>
      </div>
    </section>
  )
}
