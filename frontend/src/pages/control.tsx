import { useEffect, useMemo, useState } from "react"
import { useTranslation } from "react-i18next"
import { Link, useNavigate } from "react-router-dom"
import { LoaderCircle, MoreHorizontal, Plus, Settings } from "lucide-react"
import { EmptyState, MissingValue, Modal, useTimeLabel } from "../components/ui"
import { mapPreviewUrl } from "../map-url"
import { useStore } from "../store"
import type { Workspace } from "../types"

type Filter = "all" | "online" | "offline" | "unpaired" | "warning"
type Sort = "name" | "status" | "seen"
type Dialog =
  | { kind: "download" }
  | { kind: "disable"; id: string }
  | { kind: "confirm-code"; id: string }
  | { kind: "code"; id: string; code: string }
  | null

function MapPreview({ url, label }: { url: string | null; label: string }) {
  const { t } = useTranslation()
  const [failed, setFailed] = useState(false)
  return (
    <div className="map-preview">
      {url && !failed ? (
        <img src={url} alt={label} loading="lazy" decoding="async" onError={() => setFailed(true)} />
      ) : (
        <span className="faint">{t("unavailable")}</span>
      )}
    </div>
  )
}

function noticeOf(workspace: Workspace) {
  if (workspace.disabled) return "disabled" as const
  return workspace.notice
}

function matches(workspace: Workspace, filter: Filter, query: string) {
  if (query && !workspace.name.toLocaleLowerCase().includes(query)) return false
  if (filter === "all") return true
  if (filter === "warning") {
    return workspace.disabled || workspace.linkState === "error" || workspace.notice === "stale" || workspace.notice === "feed"
  }
  if (workspace.disabled) return false
  return workspace.linkState === filter
}

export function ControlPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const stamp = useTimeLabel()
  const store = useStore()
  const [query, setQuery] = useState("")
  const [filter, setFilter] = useState<Filter>("all")
  const [sort, setSort] = useState<Sort>("name")
  const [phase, setPhase] = useState<"ready" | "loading" | "error">("ready")
  const [menuId, setMenuId] = useState<string | null>(null)
  const [testing, setTesting] = useState<string | null>(null)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [updatedAt, setUpdatedAt] = useState(() => new Date().toISOString())

  useEffect(() => {
    if (!menuId) return
    function onPointer(event: PointerEvent) {
      const target = event.target
      if (target instanceof Element && target.closest(`[data-menu="${menuId}"]`)) return
      setMenuId(null)
    }
    window.addEventListener("pointerdown", onPointer)
    return () => window.removeEventListener("pointerdown", onPointer)
  }, [menuId])

  const accessible = store.accessibleWorkspaces
  const canCreate = store.canCreateWorkspace()
  const liveMode = store.liveMode
  const ready = store.ready

  const visible = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase()
    const items = accessible.filter((workspace) => matches(workspace, filter, needle))
    const statusRank: Record<string, number> = { online: 0, error: 1, offline: 2, unpaired: 3, unknown: 4 }
    items.sort((a, b) => {
      if (sort === "seen") {
        return (Date.parse(b.lastSeenAt ?? "") || -1) - (Date.parse(a.lastSeenAt ?? "") || -1)
      }
      if (sort === "status") return (statusRank[a.linkState] ?? 5) - (statusRank[b.linkState] ?? 5)
      return a.name.localeCompare(b.name, i18n.language)
    })
    return items
  }, [accessible, filter, i18n.language, query, sort])

  async function refresh(syncFeeds = false) {
    setPhase("loading")
    try {
      if (liveMode) {
        await store.refreshWorkspaces({ sync: syncFeeds })
      } else {
        await new Promise((resolve) => window.setTimeout(resolve, 400))
      }
      setUpdatedAt(new Date().toISOString())
      setPhase("ready")
    } catch {
      setPhase("error")
    }
  }

  // Pull live feed stats when opening the instance list (not only after "Verbindung prüfen").
  useEffect(() => {
    if (!ready || !liveMode) return
    void refresh(true)
  }, [ready, liveMode])

  async function testConnection(id: string) {
    setMenuId(null)
    setTesting(id)
    const result = await store.testConnection(id)
    setTesting(null)
    const tone = result === "ok" ? "ok" : "warn"
    const key =
      result === "ok"
        ? "wsettings.testOk"
        : result === "unpaired"
          ? "wsettings.testUnpaired"
          : result === "disabled"
            ? "wsettings.testDisabled"
            : "wsettings.testOffline"
    store.pushToast(tone, t(key))
  }

  function closeDialog() {
    setDialog(null)
  }

  return (
    <section>
      <div className="page-head">
        <div>
          <h1>{t("control.title")}</h1>
          <p>{t("control.lead")}</p>
        </div>
        <div className="toolbar">
          <button type="button" className="btn btn-secondary" onClick={() => void refresh(true)} disabled={phase === "loading"}>
            {phase === "loading" ? <LoaderCircle className="icon spin" /> : null}
            {phase === "loading" ? t("control.refreshing") : t("control.refresh")}
          </button>
          {phase === "error" ? null : (
            <button type="button" className="btn btn-ghost" onClick={() => setPhase("error")}>
              {t("control.showError")}
            </button>
          )}
        </div>
      </div>
      <div className="filters">
        <label className="field">
          <span>{t("control.search")}</span>
          <input value={query} onChange={(event) => setQuery(event.target.value)} />
        </label>
        <label className="field">
          <span>{t("control.filter")}</span>
          <select value={filter} onChange={(event) => setFilter(event.target.value as Filter)}>
            <option value="all">{t("control.all")}</option>
            <option value="online">{t("control.online")}</option>
            <option value="offline">{t("control.offline")}</option>
            <option value="unpaired">{t("control.unpaired")}</option>
            <option value="warning">{t("control.warning")}</option>
          </select>
        </label>
        <label className="field">
          <span>{t("control.sort")}</span>
          <select value={sort} onChange={(event) => setSort(event.target.value as Sort)}>
            <option value="name">{t("control.sortName")}</option>
            <option value="status">{t("control.sortStatus")}</option>
            <option value="seen">{t("control.sortSeen")}</option>
          </select>
        </label>
      </div>
      <p className="meta-row">
        <span>{t("control.count", { count: phase === "ready" ? visible.length : 0 })}</span>
        <span>
          {t("control.updatedAt", { time: stamp(updatedAt, t("unavailable")) })}
        </span>
      </p>
      {phase === "loading" ? (
        <div className="cards" aria-busy="true">
          <div className="skeleton" />
          <div className="skeleton" />
          <div className="skeleton" />
        </div>
      ) : null}
      {phase === "error" ? (
        <EmptyState
          title={t("control.errorTitle")}
          body={t("control.errorBody")}
          action={
            <button type="button" className="btn btn-primary" onClick={() => void refresh(true)}>
              {t("control.retry")}
            </button>
          }
        />
      ) : null}
      {phase === "ready" && accessible.length === 0 ? (
        <EmptyState
          title={t("control.noAccessTitle")}
          body={t("control.noAccessBody")}
          action={
            canCreate ? (
              <Link className="btn btn-primary" to="/instances/new">
                {t("control.create")}
              </Link>
            ) : undefined
          }
        />
      ) : null}
      {phase === "ready" && accessible.length > 0 && visible.length === 0 ? (
        <EmptyState
          title={t("control.emptyTitle")}
          body={t("control.emptyBody")}
          action={
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => {
                setQuery("")
                setFilter("all")
                setSort("name")
              }}
            >
              {t("control.clear")}
            </button>
          }
        />
      ) : null}
      {phase === "ready" && accessible.length > 0 ? (
        <div className="cards">
          {visible.map((workspace) => {
            const notice = noticeOf(workspace)
            const statusKey = workspace.disabled ? "disabled" : workspace.linkState
            const players =
              workspace.playersOnline === null
                ? null
                : workspace.playerCapacity === null
                  ? String(workspace.playersOnline)
                  : t("card.playersValue", { online: workspace.playersOnline, capacity: workspace.playerCapacity })
            const tone =
              notice || workspace.linkState === "error"
                ? "alert"
                : workspace.disabled
                  ? "offline"
                  : workspace.linkState
            return (
              <article
                key={workspace.id}
                className={["instance-card", `tone-${tone}`, menuId === workspace.id ? "menu-open" : ""]
                  .filter(Boolean)
                  .join(" ")}
              >
                <button
                  type="button"
                  className="map-hit"
                  aria-label={t("card.openMap", { name: workspace.name })}
                  onClick={() => navigate(`/w/${workspace.id}`)}
                >
                  <MapPreview url={mapPreviewUrl(workspace)} label={workspace.mapName ?? t("card.map")} />
                  <span className={`status-pill ${workspace.disabled ? "unknown" : workspace.linkState}`}>
                    <span className={`dot ${workspace.disabled ? "unknown" : workspace.linkState}`} />
                    {workspace.disabled ? t("card.disabled") : t(`link.${workspace.linkState}`)}
                  </span>
                </button>
                <div className="card-body">
                  <header className="card-head">
                    <div className="card-title-row">
                      <div className="title-with-no">
                        <span className="ws-no">{workspace.number}</span>
                        <div>
                          <h2>{workspace.name}</h2>
                          <p className="card-meta">
                            {workspace.lastSeenAt
                              ? t("card.lastContact", { time: stamp(workspace.lastSeenAt, t("unavailable")) })
                              : t("card.neverContact")}
                            {workspace.connectorVersion ? ` · v${workspace.connectorVersion}` : ""}
                          </p>
                        </div>
                      </div>
                    </div>
                    {notice ? <p className="card-alert">{t(`notice.${notice}`)}</p> : null}
                  </header>
                  <dl className="card-metrics">
                    <div>
                      <dt>{t("card.players")}</dt>
                      <dd><MissingValue text={players} /></dd>
                    </div>
                    <div>
                      <dt>{t("card.map")}</dt>
                      <dd><MissingValue text={workspace.mapName} /></dd>
                    </div>
                    <div>
                      <dt>{t("card.gameTime")}</dt>
                      <dd><MissingValue text={workspace.gameTime} /></dd>
                    </div>
                    <div>
                      <dt>{t("card.gameVersion")}</dt>
                      <dd><MissingValue text={workspace.gameVersion ?? null} /></dd>
                    </div>
                  </dl>
                  <div className="card-actions">
                    <button type="button" className="btn btn-primary" onClick={() => navigate(`/w/${workspace.id}`)}>
                      {t("card.open")}
                    </button>
                    <button
                      type="button"
                      className="btn btn-secondary btn-icon"
                      aria-label={t("card.settings")}
                      onClick={() => navigate(`/w/${workspace.id}/settings`)}
                    >
                      <Settings className="icon" />
                    </button>
                    <div className="menu-wrap" data-menu={workspace.id}>
                      <button
                        type="button"
                        className="btn btn-secondary btn-icon"
                        aria-label={t("card.more")}
                        aria-expanded={menuId === workspace.id}
                        onClick={() => setMenuId((current) => (current === workspace.id ? null : workspace.id))}
                      >
                        <MoreHorizontal className="icon" />
                      </button>
                      {menuId === workspace.id ? (
                        <div className="dropdown">
                          <button type="button" onClick={() => { setMenuId(null); setDialog({ kind: "confirm-code", id: workspace.id }) }}>
                            {t("card.recouple")}
                          </button>
                          <button type="button" onClick={() => { setMenuId(null); setDialog({ kind: "download" }) }}>
                            {t("card.download")}
                          </button>
                          <button type="button" disabled={testing === workspace.id} onClick={() => void testConnection(workspace.id)}>
                            {testing === workspace.id ? t("control.refreshing") : t("card.test")}
                          </button>
                          {workspace.disabled ? (
                            <button
                              type="button"
                              onClick={() => {
                                void store.setDisabled(workspace.id, false).then(() => {
                                  store.pushToast("ok", t("wsettings.enabled"))
                                  setMenuId(null)
                                })
                              }}
                            >
                              {t("card.enable")}
                            </button>
                          ) : (
                            <button type="button" onClick={() => { setMenuId(null); setDialog({ kind: "disable", id: workspace.id }) }}>
                              {t("card.disable")}
                            </button>
                          )}
                        </div>
                      ) : null}
                    </div>
                  </div>
                </div>
                <span className="sr">{statusKey}</span>
              </article>
            )
          })}
          {canCreate ? (
            <Link className="instance-card add-card" to="/instances/new" aria-label={t("control.create")}>
              <Plus className="add-plus" aria-hidden="true" />
              <span>{t("control.create")}</span>
            </Link>
          ) : null}
        </div>
      ) : null}
      {dialog?.kind === "download" ? (
        <Modal title={t("wsettings.downloadTitle")} onClose={closeDialog}>
          <p className="muted">{t("wsettings.downloadBody")}</p>
          <div className="modal-actions">
            <button type="button" className="btn btn-primary" onClick={closeDialog}>
              {t("close")}
            </button>
          </div>
        </Modal>
      ) : null}
      {dialog?.kind === "disable" ? (
        <Modal title={t("wsettings.disableTitle")} onClose={closeDialog}>
          <p className="muted">{t("wsettings.disableBody")}</p>
          <div className="modal-actions">
            <button type="button" className="btn btn-ghost" onClick={closeDialog}>
              {t("cancel")}
            </button>
            <button
              type="button"
              className="btn btn-danger"
              onClick={() => {
                void store.setDisabled(dialog.id, true).then(() => {
                  store.pushToast("warn", t("wsettings.disabled"))
                  closeDialog()
                })
              }}
            >
              {t("wsettings.disable")}
            </button>
          </div>
        </Modal>
      ) : null}
      {dialog?.kind === "confirm-code" ? (
        <Modal title={t("wsettings.recoupleTitle")} onClose={closeDialog}>
          <p className="muted">{t("wsettings.recoupleBody")}</p>
          <div className="modal-actions">
            <button type="button" className="btn btn-ghost" onClick={closeDialog}>
              {t("cancel")}
            </button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={() => setDialog({ kind: "code", id: dialog.id, code: store.revealCode(dialog.id) })}
            >
              {t("wsettings.recouple")}
            </button>
          </div>
        </Modal>
      ) : null}
      {dialog?.kind === "code" ? (
        <Modal title={t("wsettings.codeOnce")} onClose={() => { store.hideCode(dialog.id); closeDialog() }}>
          <p className="code">{dialog.code}</p>
          <p className="hint">{t("create.doneLead")}</p>
          <div className="modal-actions">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => {
                void navigator.clipboard.writeText(dialog.code).then(
                  () => store.pushToast("ok", t("create.copied")),
                  () => store.pushToast("warn", t("create.copyFailed")),
                )
              }}
            >
              {t("create.copy")}
            </button>
            <button type="button" className="btn btn-primary" onClick={closeDialog}>
              {t("wsettings.hideCode")}
            </button>
          </div>
        </Modal>
      ) : null}
    </section>
  )
}
