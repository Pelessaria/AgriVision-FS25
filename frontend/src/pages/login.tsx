import { useState, type FormEvent } from "react"
import { useTranslation } from "react-i18next"
import { Navigate, useNavigate } from "react-router-dom"
import { LoaderCircle } from "lucide-react"
import { BrandMark } from "../components/ui"
import { i18n } from "../i18n"
import { useStore } from "../store"
import { isEmail } from "../validate"

function chooseLocale(lng: "de" | "en") {
  void i18n.changeLanguage(lng)
  localStorage.setItem("fs25.locale", lng)
  document.documentElement.lang = lng
}

export function LoginPage() {
  const { t, i18n: current } = useTranslation()
  const navigate = useNavigate()
  const { sessionUserId, signIn } = useStore()
  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
  const [emailError, setEmailError] = useState("")
  const [passwordError, setPasswordError] = useState("")
  const [formError, setFormError] = useState("")
  const [pending, setPending] = useState(false)

  if (sessionUserId) return <Navigate to="/" replace />

  function submit(event: FormEvent) {
    event.preventDefault()
    const nextEmail = isEmail(email) ? "" : t("login.emailInvalid")
    const nextPassword = password.length >= 12 ? "" : t("login.passwordShort")
    setEmailError(nextEmail)
    setPasswordError(nextPassword)
    setFormError("")
    if (nextEmail || nextPassword) return
    setPending(true)
    window.setTimeout(() => {
      if (password === "wrong-password") {
        setFormError(t("login.failed"))
        setPending(false)
        return
      }
      signIn()
      navigate("/")
    }, 700)
  }

  return (
    <div className="login">
      <aside className="login-aside">
        <div>
          <BrandMark large />
          <p className="hint">{t("login.kicker")}</p>
          <h1>{t("appName")}</h1>
          <p className="lead">{t("login.lead")}</p>
        </div>
        <p className="hint">{t("demoBanner")}</p>
      </aside>
      <section className="login-panel">
        <form className="login-card" onSubmit={submit} noValidate>
          <div className="login-tools">
            <div className="lang" role="group" aria-label={t("language")}>
              <button type="button" aria-pressed={current.language !== "en"} aria-label={t("german")} onClick={() => chooseLocale("de")}>
                DE
              </button>
              <button type="button" aria-pressed={current.language === "en"} aria-label={t("english")} onClick={() => chooseLocale("en")}>
                EN
              </button>
            </div>
          </div>
          <h2>{t("login.title")}</h2>
          <div className="stack">
            {formError ? (
              <p className="form-error" role="alert">
                {formError}
              </p>
            ) : null}
            <label className="field">
              <span>{t("login.email")}</span>
              <input
                type="email"
                autoComplete="username"
                value={email}
                aria-invalid={emailError ? true : undefined}
                onChange={(event) => setEmail(event.target.value)}
              />
              {emailError ? <span className="field-error">{emailError}</span> : null}
            </label>
            <label className="field">
              <span>{t("login.password")}</span>
              <input
                type="password"
                autoComplete="current-password"
                value={password}
                aria-invalid={passwordError ? true : undefined}
                onChange={(event) => setPassword(event.target.value)}
              />
              {passwordError ? <span className="field-error">{passwordError}</span> : <span className="hint">{t("login.hint")}</span>}
            </label>
            <button className="btn btn-primary" type="submit" disabled={pending}>
              {pending ? <LoaderCircle className="icon spin" /> : null}
              {pending ? t("login.submitting") : t("login.submit")}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}
