import { Link } from "react-router-dom"
import { useTranslation } from "react-i18next"
import { EmptyState, MissingValue, MissingWorkspace, PermissionBlock, useTimeLabel } from "../components/ui"
import { APP_MODULES } from "../modules"
import { useStore } from "../store"
import { useWorkspaceRoute } from "../use-workspace"

function money(value: number | null, locale: string) {
  if (value === null) return null
  return new Intl.NumberFormat(locale, {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  }).format(value)
}

export function WorkspaceHomePage() {
  const { t, i18n } = useTranslation()
  const stamp = useTimeLabel()
  const { activity, moduleAccess, selectedFarm, vehicles } = useStore()
  const { workspace, allowed } = useWorkspaceRoute()

  if (!workspace) return <MissingWorkspace />
  if (!allowed) return <PermissionBlock />

  const farm = selectedFarm(workspace.id)
  const notice = workspace.disabled ? "disabled" : workspace.notice
  const shortcuts = APP_MODULES.filter((module) => moduleAccess(workspace.id, module.id))
  const farmVehicles = farm
    ? vehicles.filter((item) => item.workspaceId === workspace.id && item.farmId === farm.gameFarmId)
    : []
  const entries = activity
    .filter((item) => item.workspaceId === workspace.id)
    .sort((a, b) => Date.parse(b.at) - Date.parse(a.at))
    .slice(0, 5)

  if (!farm) {
    return (
      <section>
        <div className="page-head">
          <div>
            <h1>{workspace.name}</h1>
            <p>{t("workspace.lead")}</p>
          </div>
        </div>
        <EmptyState title={t("farm.noneTitle")} body={t("farm.noneBody")} />
      </section>
    )
  }

  return (
    <section className="ws-dash">
      <div className="page-head">
        <div>
          <h1>{farm.name}</h1>
          <p>{t("workspace.farmLead", { server: workspace.name })}</p>
        </div>
        <div className="ws-dash-meta">
          <span className="ws-no">{farm.gameFarmId}</span>
          <span className="status-line">
            <span className={`dot ${workspace.disabled ? "unknown" : workspace.linkState}`} />
            {workspace.disabled ? t("card.disabled") : t(`link.${workspace.linkState}`)}
          </span>
          <span className="faint">
            {workspace.lastSyncAt
              ? t("workspace.sync", { time: stamp(workspace.lastSyncAt, "") })
              : t("workspace.neverSync")}
          </span>
        </div>
      </div>

      {notice ? <p className="notice">{t(`notice.${notice}`)}</p> : null}

      <div className="stat-grid ws-dash-stats">
        <div className="stat-tile">
          <span>{t("workspace.balance")}</span>
          <strong>
            <MissingValue text={money(farm.money, i18n.language)} />
          </strong>
        </div>
        <div className="stat-tile">
          <span>{t("farm.hectares")}</span>
          <strong>
            <MissingValue
              text={
                farm.hectares === null
                  ? null
                  : t("farm.hectaresValue", { value: farm.hectares.toLocaleString(i18n.language) })
              }
            />
          </strong>
        </div>
        <div className="stat-tile">
          <span>{t("modules.equipment")}</span>
          <strong>{farmVehicles.length}</strong>
          <em>{t("farm.equipmentHint")}</em>
        </div>
        <div className="stat-tile">
          <span>{t("modules.animals")}</span>
          <strong>
            <MissingValue text={farm.animals === null ? null : String(farm.animals)} />
          </strong>
        </div>
        <div className="stat-tile">
          <span>{t("modules.storage")}</span>
          <strong>
            <MissingValue
              text={
                farm.storageFill === null
                  ? null
                  : t("farm.storageValue", { value: Math.round(farm.storageFill * 100) })
              }
            />
          </strong>
        </div>
        <div className="stat-tile">
          <span>{t("workspace.gameTime")}</span>
          <strong>
            <MissingValue text={workspace.gameTime} />
          </strong>
          <em>
            <MissingValue text={workspace.mapName} />
          </em>
        </div>
      </div>

      <div className="ws-dash-grid">
        <section className="panel">
          <h2>{t("workspace.shortcuts")}</h2>
          <p className="hint">{t("workspace.shortcutsLead")}</p>
          {shortcuts.length === 0 ? <p className="muted">{t("workspace.shortcutsEmpty")}</p> : null}
          <div className="ws-module-grid">
            {shortcuts.map((module) => (
              <Link key={module.id} className="ws-module-link" to={`/w/${workspace.id}/${module.path}`}>
                <strong>{t(module.labelKey)}</strong>
                <span className="faint">{t("workspace.openModule")}</span>
              </Link>
            ))}
          </div>
        </section>

        <section className="panel">
          <h2>{t("workspace.activity")}</h2>
          <p className="hint">{t("workspace.activityLead")}</p>
          {entries.length === 0 ? <p className="muted">{t("workspace.activityEmpty")}</p> : null}
          <ul className="activity">
            {entries.map((item) => (
              <li key={item.id}>
                <span>{t(`activity.${item.key}`)}</span>
                <span className="faint">{stamp(item.at, t("unavailable"))}</span>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </section>
  )
}
