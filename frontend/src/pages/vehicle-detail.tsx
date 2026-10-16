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
  if (vehicle.x === null || vehicle.y === null || vehicle.z === null) return null
  const format = (value: number) => value.toFixed(1)
  return `${format(vehicle.x)}, ${format(vehicle.y)}, ${format(vehicle.z)}`
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

  return (
    <section className="equip-detail">
      <div className="equip-detail-hero">
        <span className="equip-detail-icon" aria-hidden="true">
          {vehicle.iconUrl ? <img src={vehicle.iconUrl} alt="" onError={onIconError} /> : null}
          <Tractor className={`icon icon-fallback${vehicle.iconUrl ? " is-hidden" : ""}`} />
        </span>
        <div className="equip-detail-head">
          <p className="hint">{t("vehicles.observed", { time: stamp(vehicle.observedAt, t("unavailable")) })}</p>
          <h1>{vehicleDisplayName(vehicle.name)}</h1>
          <p className="equip-detail-cat">{t(categoryKey)}</p>
        </div>
      </div>

      <div className="equip-detail-grid">
        <div className="equip-detail-panel">
          <h2>{t("vehicles.overview")}</h2>
          <dl className="detail">
            <div>
              <dt>{t("vehicles.farm")}</dt>
              <dd>
                {farmName ??
                  (vehicle.farmId === null ? t("unavailable") : t("vehicles.farmId", { id: vehicle.farmId }))}
              </dd>
            </div>
            <div>
              <dt>{t("vehicles.position")}</dt>
              <dd>
                <MissingValue text={positionOf(vehicle)} />
              </dd>
            </div>
            <div>
              <dt>{t("vehicles.hours")}</dt>
              <dd>{vehicle.hours === null ? t("unavailable") : vehicle.hours.toLocaleString(i18n.language)}</dd>
            </div>
            <div>
              <dt>{t("vehicles.fill")}</dt>
              <dd>
                <MissingValue text={fill} />
              </dd>
            </div>
          </dl>
        </div>

        <div className="equip-detail-panel">
          <h2>{t("vehicles.modTitle")}</h2>
          {modKey ? (
            <div className="equip-mod-row">
              <span className="equip-mod-icon" aria-hidden="true">
                {vehicle.modIconUrl ? (
                  <img src={vehicle.modIconUrl} alt="" onError={onIconError} />
                ) : null}
                <Package className={`icon icon-fallback${vehicle.modIconUrl ? " is-hidden" : ""}`} />
              </span>
              <dl className="detail equip-mod-meta">
                <div>
                  <dt>{t("vehicles.modName")}</dt>
                  <dd>{modName}</dd>
                </div>
                <div>
                  <dt>{t("vehicles.modZip")}</dt>
                  <dd>
                    <code>{modZip}</code>
                  </dd>
                </div>
                <div>
                  <dt>{t("vehicles.modKey")}</dt>
                  <dd>
                    <code>{modKey}</code>
                  </dd>
                </div>
                <div>
                  <dt>{t("vehicles.filename")}</dt>
                  <dd>
                    <MissingValue text={fileLabel} />
                  </dd>
                </div>
              </dl>
            </div>
          ) : (
            <p className="hint">{t("vehicles.modBaseHint")}</p>
          )}
          {vehicle.filename && modKey ? (
            <p className="equip-mod-path faint" title={vehicle.filename}>
              {vehicle.filename}
            </p>
          ) : null}
        </div>
      </div>

      <p>
        <Link className="btn btn-secondary" to={`/w/${id}/equipment`}>
          {t("back")}
        </Link>
      </p>
    </section>
  )
}
