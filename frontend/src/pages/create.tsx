import { useState, type FormEvent } from "react"
import { useTranslation } from "react-i18next"
import { Link, Navigate, useNavigate } from "react-router-dom"
import { LoaderCircle } from "lucide-react"
import { useStore } from "../store"

export function CreatePage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { canCreateWorkspace, createWorkspace, hideCode, pushToast } = useStore()
  const [name, setName] = useState("")
  const [error, setError] = useState("")
  const [pending, setPending] = useState(false)
  const [created, setCreated] = useState<{ id: string; name: string; code: string } | null>(null)

  if (!canCreateWorkspace()) return <Navigate to="/instances" replace />

  function submit(event: FormEvent) {
    event.preventDefault()
    const trimmed = name.trim()
    if (!trimmed) {
      setError(t("create.nameRequired"))
      return
    }
    if (trimmed.length < 2) {
      setError(t("create.nameShort"))
      return
    }
    if (trimmed.length > 40) {
      setError(t("create.nameLong"))
      return
    }
    setError("")
    setPending(true)
    window.setTimeout(() => {
      const result = createWorkspace(trimmed)
      setCreated({ id: result.workspace.id, name: result.workspace.name, code: result.code })
      setPending(false)
      setName("")
    }, 600)
  }

  if (created) {
    return (
      <section className="panel">
        <h1>{t("create.doneTitle")}</h1>
        <p className="muted">{t("create.doneLead")}</p>
        <p className="hint">{created.name}</p>
        <p className="code">{created.code}</p>
        <span className="badge">{t("demo")}</span>
        <div className="page-actions">
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => {
              void navigator.clipboard.writeText(created.code).then(
                () => pushToast("ok", t("create.copied")),
                () => pushToast("warn", t("create.copyFailed")),
              )
            }}
          >
            {t("create.copy")}
          </button>
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => {
              hideCode(created.id)
              navigate(`/w/${created.id}`)
            }}
          >
            {t("create.open")}
          </button>
          <button
            type="button"
            className="btn btn-ghost"
            onClick={() => {
              hideCode(created.id)
              setCreated(null)
            }}
          >
            {t("create.another")}
          </button>
          <Link
            className="btn btn-ghost"
            to="/instances"
            onClick={() => hideCode(created.id)}
          >
            {t("create.toList")}
          </Link>
        </div>
      </section>
    )
  }

  return (
    <section>
      <div className="page-head">
        <div>
          <h1>{t("create.title")}</h1>
          <p>{t("create.lead")}</p>
        </div>
      </div>
      <form className="panel stack" onSubmit={submit} noValidate>
        <label className="field">
          <span>{t("create.name")}</span>
          <input
            value={name}
            maxLength={40}
            aria-invalid={error ? true : undefined}
            onChange={(event) => setName(event.target.value)}
          />
          {error ? <span className="field-error">{error}</span> : <span className="hint">{t("create.nameHint")}</span>}
        </label>
        <div className="page-actions">
          <button className="btn btn-primary" type="submit" disabled={pending}>
            {pending ? <LoaderCircle className="icon spin" /> : null}
            {pending ? t("create.submitting") : t("create.submit")}
          </button>
          <Link className="btn btn-ghost" to="/instances">
            {t("cancel")}
          </Link>
        </div>
      </form>
    </section>
  )
}
