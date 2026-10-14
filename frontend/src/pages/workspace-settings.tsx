import { useState, type FormEvent } from "react"
import { useTranslation } from "react-i18next"
import { LoaderCircle } from "lucide-react"
import { FieldTip, MissingWorkspace, Modal, PermissionBlock } from "../components/ui"
import { useStore } from "../store"
import { useWorkspaceRoute } from "../use-workspace"
import { isFeedUrl, isFeedUrlWithCode } from "../validate"

export function WorkspaceSettingsPage() {
  const { t } = useTranslation()
  const store = useStore()
  const { workspace, allowed, manage } = useWorkspaceRoute()
  const [name, setName] = useState(workspace?.name ?? "")
  const [nameError, setNameError] = useState("")
  const [feed, setFeed] = useState(workspace?.feedUrl ?? "")
  const [feedError, setFeedError] = useState("")
  const [syncedId, setSyncedId] = useState(workspace?.id)
  if (workspace && syncedId !== workspace.id) {
    setSyncedId(workspace.id)
    setName(workspace.name)
    setFeed(workspace.feedUrl)
    setNameError("")
    setFeedError("")
  }
  const [testing, setTesting] = useState(false)
  const [dialog, setDialog] = useState<"download" | "disable" | "confirm" | "code" | null>(null)
  const [code, setCode] = useState("")

  if (!workspace) return <MissingWorkspace />
  if (!allowed || !manage) return <PermissionBlock />

  async function saveName(event: FormEvent) {
    event.preventDefault()
    const trimmed = name.trim()
    if (!trimmed) {
      setNameError(t("create.nameRequired"))
      return
    }
    if (trimmed.length < 2) {
      setNameError(t("create.nameShort"))
      return
    }
    if (trimmed.length > 40) {
      setNameError(t("create.nameLong"))
      return
    }
    setNameError("")
    try {
      await store.renameWorkspace(workspace!.id, trimmed)
      store.pushToast("ok", t("settings.saved"))
    } catch {
      store.pushToast("warn", t("settings.saved"))
    }
  }

  async function saveFeed(event: FormEvent) {
    event.preventDefault()
    const trimmed = feed.trim()
    const configured = Boolean(workspace?.feedConfigured)
    const ok =
      trimmed === "" ||
      isFeedUrlWithCode(trimmed) ||
      (configured && isFeedUrl(trimmed) && !trimmed.includes("?"))
    if (!ok) {
      setFeedError(t("wsettings.feedInvalid"))
      return
    }
    setFeedError("")
    const result = await store.setFeedUrl(workspace!.id, trimmed)
    if (!result.ok) {
      setFeedError(result.error ?? t("wsettings.feedInvalid"))
      store.pushToast("warn", result.error ?? t("wsettings.testOffline"))
      return
    }
    store.pushToast("ok", t("wsettings.feedSaved"))
  }

  async function test() {
    setTesting(true)
    const result = await store.testConnection(workspace!.id)
    setTesting(false)
    const key =
      result === "ok"
        ? "wsettings.testOk"
        : result === "unpaired"
          ? "wsettings.testUnpaired"
          : result === "disabled"
            ? "wsettings.testDisabled"
            : "wsettings.testOffline"
    store.pushToast(result === "ok" ? "ok" : "warn", t(key))
  }

  return (
    <section>
      <div className="page-head">
        <div>
          <h1>{t("wsettings.title")}</h1>
          <p>{t("wsettings.lead")}</p>
        </div>
      </div>
      <div className="settings-page">
        <header className="panel settings-hero">
          <span className="ws-no">{workspace.number}</span>
          <div>
            <h2>{workspace.name}</h2>
            {workspace.mapName ? <p className="faint">{workspace.mapName}</p> : null}
            <div className="status-line">
              <span className={`dot ${workspace.disabled ? "unknown" : workspace.linkState}`} />
              <span>{workspace.disabled ? t("card.disabled") : t(`link.${workspace.linkState}`)}</span>
            </div>
          </div>
        </header>
        <div className="settings-grid">
          <div className="settings-col">
            <form className="panel settings-card" onSubmit={(event) => void saveName(event)}>
              <h2>{t("wsettings.name")}</h2>
              <label className="field">
                <span className="sr">{t("wsettings.name")}</span>
                <input value={name} aria-invalid={nameError ? true : undefined} onChange={(event) => setName(event.target.value)} />
                {nameError ? <span className="field-error">{nameError}</span> : <span className="hint">{t("create.nameHint")}</span>}
              </label>
              <div className="setting-actions">
                <button className="btn btn-primary" type="submit">{t("save")}</button>
              </div>
            </form>
            <form className="panel settings-card" onSubmit={(event) => void saveFeed(event)}>
              <div className="field-label-row">
                <h2>{t("wsettings.feed")}</h2>
                <FieldTip label={t("wsettings.feedTipLabel")}>
                  <p>{t("wsettings.feedTip")}</p>
                </FieldTip>
              </div>
              <ol className="setup-steps">
                <li>{t("wsettings.feedStep1")}</li>
                <li>{t("wsettings.feedStep2")}</li>
                <li>{t("wsettings.feedStep3")}</li>
                <li>{t("wsettings.feedStep4")}</li>
              </ol>
              <label className="field">
                <span className="field-label-row">
                  <span>{t("wsettings.feedField")}</span>
                  <FieldTip label={t("wsettings.feedUrlTipLabel")}>
                    <p>{t("wsettings.feedUrlTip")}</p>
                    <p className="field-tip-example">{t("wsettings.feedUrlExample")}</p>
                  </FieldTip>
                </span>
                <input
                  value={feed}
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder={t("wsettings.feedPlaceholder")}
                  aria-invalid={feedError ? true : undefined}
                  onChange={(event) => setFeed(event.target.value)}
                />
                {feedError ? (
                  <span className="field-error">{feedError}</span>
                ) : (
                  <span className="hint">
                    {workspace.feedConfigured ? t("wsettings.feedHintConfigured") : t("wsettings.feedHint")}
                  </span>
                )}
              </label>
              {workspace.gameVersion || workspace.linkState === "online" ? (
                <p className="instance-version">
                  <span>{t("card.gameVersion")}</span>
                  <strong>{workspace.gameVersion ?? "—"}</strong>
                  {workspace.playersOnline !== null ? (
                    <>
                      <span>{t("card.players")}</span>
                      <strong>
                        {workspace.playerCapacity === null
                          ? workspace.playersOnline
                          : `${workspace.playersOnline}/${workspace.playerCapacity}`}
                      </strong>
                    </>
                  ) : null}
                </p>
              ) : null}
              <div className="setting-actions">
                <button className="btn btn-secondary" type="submit">{t("save")}</button>
              </div>
            </form>
          </div>
          <div className="settings-col">
            <section className="panel settings-card">
              <h2>{t("workspace.connector")}</h2>
              <p className="instance-version">
                <span>{t("workspace.version")}</span>
                <strong>{workspace.connectorVersion ?? t("workspace.noVersion")}</strong>
              </p>
              <div className="setting-actions">
                <button type="button" className="btn btn-secondary" disabled={testing} onClick={() => void test()}>
                  {testing ? <LoaderCircle className="icon spin" /> : null}
                  {t("card.test")}
                </button>
                <button type="button" className="btn btn-ghost" onClick={() => setDialog("confirm")}>
                  {t("card.recouple")}
                </button>
                <button type="button" className="btn btn-ghost" onClick={() => setDialog("download")}>
                  {t("card.download")}
                </button>
              </div>
              <p className="hint">{t("wsettings.compatBody")}</p>
            </section>
            <section className="panel settings-card danger-card">
              <h2>{workspace.disabled ? t("wsettings.enable") : t("wsettings.disable")}</h2>
              <p className="hint">{t("wsettings.disableBody")}</p>
              {workspace.disabled ? (
                <div className="setting-actions">
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={() => {
                      void store.setDisabled(workspace.id, false).then(() => {
                        store.pushToast("ok", t("wsettings.enabled"))
                      })
                    }}
                  >
                    {t("wsettings.enable")}
                  </button>
                </div>
              ) : (
                <div className="setting-actions">
                  <button type="button" className="btn btn-danger" onClick={() => setDialog("disable")}>
                    {t("wsettings.disable")}
                  </button>
                </div>
              )}
            </section>
          </div>
        </div>
      </div>
      {dialog === "download" ? (
        <Modal title={t("wsettings.downloadTitle")} onClose={() => setDialog(null)}>
          <p className="muted">{t("wsettings.downloadBody")}</p>
          <div className="modal-actions">
            <button type="button" className="btn btn-primary" onClick={() => setDialog(null)}>{t("close")}</button>
          </div>
        </Modal>
      ) : null}
      {dialog === "disable" ? (
        <Modal title={t("wsettings.disableTitle")} onClose={() => setDialog(null)}>
          <p className="muted">{t("wsettings.disableBody")}</p>
          <div className="modal-actions">
            <button type="button" className="btn btn-ghost" onClick={() => setDialog(null)}>{t("cancel")}</button>
            <button
              type="button"
              className="btn btn-danger"
              onClick={() => {
                void store.setDisabled(workspace.id, true).then(() => {
                  store.pushToast("warn", t("wsettings.disabled"))
                  setDialog(null)
                })
              }}
            >
              {t("wsettings.disable")}
            </button>
          </div>
        </Modal>
      ) : null}
      {dialog === "confirm" ? (
        <Modal title={t("wsettings.recoupleTitle")} onClose={() => setDialog(null)}>
          <p className="muted">{t("wsettings.recoupleBody")}</p>
          <div className="modal-actions">
            <button type="button" className="btn btn-ghost" onClick={() => setDialog(null)}>{t("cancel")}</button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={() => {
                setCode(store.revealCode(workspace.id))
                setDialog("code")
              }}
            >
              {t("wsettings.recouple")}
            </button>
          </div>
        </Modal>
      ) : null}
      {dialog === "code" ? (
        <Modal
          title={t("wsettings.codeOnce")}
          onClose={() => {
            store.hideCode(workspace.id)
            setDialog(null)
          }}
        >
          <p className="code">{code}</p>
          <p className="hint">{t("create.doneLead")}</p>
          <div className="modal-actions">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => {
                void navigator.clipboard.writeText(code).then(
                  () => store.pushToast("ok", t("create.copied")),
                  () => store.pushToast("warn", t("create.copyFailed")),
                )
              }}
            >
              {t("create.copy")}
            </button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={() => {
                store.hideCode(workspace.id)
                setDialog(null)
              }}
            >
              {t("wsettings.hideCode")}
            </button>
          </div>
        </Modal>
      ) : null}
    </section>
  )
}
