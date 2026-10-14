import { useCallback, useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { useTranslation } from "react-i18next"
import { LoaderCircle, RefreshCw } from "lucide-react"
import { apiRequiredMods, type RequiredModStatus, type RequiredModsResponse } from "../api"

export function RequiredModsPanel({
  workspaceId,
  settingsPath,
  compact,
}: {
  workspaceId: string
  settingsPath: string
  compact?: boolean
}) {
  const { t } = useTranslation()
  const [data, setData] = useState<RequiredModsResponse | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(false)

  const load = useCallback(async () => {
    setBusy(true)
    setError(false)
    try {
      const result = await apiRequiredMods(workspaceId)
      setData(result)
    } catch {
      setError(true)
    } finally {
      setBusy(false)
    }
  }, [workspaceId])

  useEffect(() => {
    void load()
  }, [load])

  function statusLabel(mod: RequiredModStatus) {
    if (mod.status === "active") return t("wsettings.requiredModsActive")
    if (mod.status === "installed") return t("wsettings.requiredModsOnlyInstalled")
    return t("wsettings.requiredModsMissing")
  }

  return (
    <section className={compact ? "required-mods compact" : "panel required-mods"}>
      <div className="field-label-row required-mods-head">
        <h2>{t("wsettings.requiredModsTitle")}</h2>
        <button type="button" className="btn btn-ghost btn-small" disabled={busy} onClick={() => void load()}>
          {busy ? <LoaderCircle className="icon spin" /> : <RefreshCw className="icon" />}
          {t("wsettings.requiredModsRefresh")}
        </button>
      </div>
      {!compact ? <p className="hint">{t("wsettings.requiredModsLead")}</p> : null}
      {busy && !data ? <p className="muted">{t("wsettings.requiredModsChecking")}</p> : null}
      {error ? <p className="field-error">{t("wsettings.connectorDeployFail")}</p> : null}
      {data && !data.hostAccess ? <p className="hint">{t("wsettings.requiredModsNoHost")}</p> : null}

      {data ? (
        <ul className="required-mods-list">
          {data.mods.map((mod) => (
            <li key={mod.id} className={`required-mod-row status-${mod.status}`}>
              <div className="required-mod-main">
                <strong>{mod.title}</strong>
                <span className={`required-mod-badge status-${mod.status}`}>{statusLabel(mod)}</span>
              </div>
              <div className="required-mod-flags">
                <span className={mod.installed ? "ok" : "no"}>
                  {t("wsettings.requiredModsInstalled")} — {mod.installed ? t("yes") : t("no")}
                </span>
                <span className={mod.activated ? "ok" : "no"}>
                  {t("wsettings.requiredModsActivated")} — {mod.activated ? t("yes") : t("no")}
                </span>
              </div>
              <div className="required-mod-meta faint">
                {mod.runningVersion
                  ? t("wsettings.requiredModsVersion", { version: mod.runningVersion })
                  : null}
                {mod.packageVersion
                  ? ` · ${t("wsettings.requiredModsPackage", { version: mod.packageVersion })}`
                  : null}
                {mod.installedPath ? ` · ${mod.installedPath}` : null}
              </div>
            </li>
          ))}
        </ul>
      ) : null}

      <p className="muted">
        <Link to={settingsPath}>{t("wsettings.requiredModsOpenPackage")}</Link>
      </p>
    </section>
  )
}
