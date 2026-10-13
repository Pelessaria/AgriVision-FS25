import { useEffect, type ReactNode } from "react"
import { Link } from "react-router-dom"
import { X } from "lucide-react"
import { useTranslation } from "react-i18next"
import { formatWhen } from "../demo"
import { useStore } from "../store"

// oxlint-disable-next-line react/only-export-components -- shared hook for the prototype screens
export function useTimeLabel() {
  const { i18n } = useTranslation()
  const locale = i18n.resolvedLanguage === "en" ? "en-GB" : "de-DE"
  return (iso: string | null, fallback: string) => formatWhen(iso, locale, fallback)
}

export function MissingValue({ text }: { text: string | null }) {
  const { t } = useTranslation()
  if (!text) return <span className="faint">{t("unavailable")}</span>
  return <>{text}</>
}

export function EmptyState({
  title,
  body,
  action,
}: {
  title: string
  body: string
  action?: ReactNode
}) {
  return (
    <section className="state-panel">
      <h2>{title}</h2>
      <p>{body}</p>
      {action ? <div className="state-panel-actions">{action}</div> : null}
    </section>
  )
}

export function PermissionBlock() {
  const { t } = useTranslation()
  return (
    <EmptyState
      title={t("permission.title")}
      body={t("permission.body")}
      action={
        <Link className="btn btn-secondary" to="/instances">
          {t("permission.back")}
        </Link>
      }
    />
  )
}

export function MissingWorkspace() {
  const { t } = useTranslation()
  return (
    <EmptyState
      title={t("workspace.missingTitle")}
      body={t("workspace.missingBody")}
      action={
        <Link className="btn btn-secondary" to="/instances">
          {t("workspace.back")}
        </Link>
      }
    />
  )
}

export function Modal({
  title,
  onClose,
  children,
}: {
  title: string
  onClose: () => void
  children: ReactNode
}) {
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose()
    }
    window.addEventListener("keydown", onKey)
    return () => window.removeEventListener("keydown", onKey)
  }, [onClose])

  return (
    <div
      className="modal-back"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div className="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <h2 id="modal-title">{title}</h2>
        {children}
      </div>
    </div>
  )
}

export function Toasts() {
  const { t } = useTranslation()
  const { toasts, dismissToast } = useStore()
  if (toasts.length === 0) return null
  return (
    <div className="toasts" aria-live="polite">
      {toasts.map((toast) => (
        <div className={`toast ${toast.tone}`} key={toast.id}>
          <span>{toast.text}</span>
          <button
            type="button"
            className="btn btn-ghost btn-icon"
            aria-label={t("toast.dismiss")}
            onClick={() => dismissToast(toast.id)}
          >
            <X className="icon" />
          </button>
        </div>
      ))}
    </div>
  )
}

export function BrandMark({ large = false }: { large?: boolean }) {
  const size = large ? 72 : 40
  return (
    <img
      className={large ? "brand-mark brand-mark-lg" : "brand-mark"}
      src="/logo.svg"
      alt=""
      width={size}
      height={size}
    />
  )
}
