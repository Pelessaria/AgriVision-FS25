import { useTranslation } from "react-i18next"
import { useParams } from "react-router-dom"
import { EmptyState, PermissionBlock } from "../components/ui"
import { moduleById, type ModuleId } from "../modules"
import { useStore } from "../store"
import { useWorkspaceRoute } from "../use-workspace"

const LEAD_KEYS: Partial<Record<ModuleId, string>> = {
  map: "modules.mapLead",
  fields: "modules.fieldsLead",
  animals: "modules.animalsLead",
  storage: "modules.storageLead",
  finance: "modules.financeLead",
  trade: "modules.tradeLead",
  farm: "modules.farmLead",
}

export function ModulePage() {
  const { t } = useTranslation()
  const { moduleId = "" } = useParams()
  const module = moduleById(moduleId)
  const route = useWorkspaceRoute()
  const store = useStore()

  if (!module) {
    return <EmptyState title={t("modules.missingTitle")} body={t("modules.missingBody")} />
  }

  const access = route.workspace ? store.moduleAccess(route.workspace.id, module.id) : null
  if (!route.workspace || !route.allowed || !access) return <PermissionBlock />

  const leadKey = LEAD_KEYS[module.id]

  return (
    <section className="panel stack">
      <h1>{t(module.labelKey)}</h1>
      {leadKey ? <p className="muted">{t(leadKey)}</p> : null}
      <p className="hint">{t("modules.comingSoon")}</p>
      <p className="faint">{access === "write" ? t("modules.canWrite") : t("modules.readOnly")}</p>
    </section>
  )
}
