import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react"
import { useTranslation } from "react-i18next"
import { Camera, ShieldCheck } from "lucide-react"
import { i18n } from "../i18n"
import { useStore } from "../store"
import type { MailEncryption, MailSettings } from "../types"
import { isEmail } from "../validate"

type SettingsTab = "profile" | "general" | "mail"

function chooseLocale(lng: "de" | "en") {
  void i18n.changeLanguage(lng)
  localStorage.setItem("fs25.locale", lng)
  document.documentElement.lang = lng
}

function initials(name: string) {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toLocaleUpperCase() ?? "")
    .join("")
}

export function SettingsPage() {
  const { t, i18n: current } = useTranslation()
  const store = useStore()
  const actor = store.actor
  const isAdmin = actor?.globalRole === "admin" && actor.active
  const fileRef = useRef<HTMLInputElement>(null)
  const [tab, setTab] = useState<SettingsTab>("profile")

  const [firstName, setFirstName] = useState("")
  const [lastName, setLastName] = useState("")
  const [alias, setAlias] = useState("")
  const [notificationEmail, setNotificationEmail] = useState("")
  const [avatarDataUrl, setAvatarDataUrl] = useState<string | null>(null)
  const [twoFactorEnabled, setTwoFactorEnabled] = useState(false)
  const [profileError, setProfileError] = useState("")

  const [currentPassword, setCurrentPassword] = useState("")
  const [nextPassword, setNextPassword] = useState("")
  const [confirmPassword, setConfirmPassword] = useState("")
  const [passwordError, setPasswordError] = useState("")

  const [mail, setMail] = useState<MailSettings>(store.mailSettings)
  const [mailError, setMailError] = useState("")
  const [mailTesting, setMailTesting] = useState(false)

  useEffect(() => {
    if (!actor) return
    setFirstName(actor.firstName)
    setLastName(actor.lastName)
    setAlias(actor.alias)
    setNotificationEmail(actor.notificationEmail || actor.email)
    setAvatarDataUrl(actor.avatarDataUrl)
    setTwoFactorEnabled(actor.twoFactorEnabled)
  }, [actor])

  useEffect(() => {
    setMail(store.mailSettings)
  }, [store.mailSettings])

  useEffect(() => {
    if (tab === "mail" && !isAdmin) setTab("profile")
  }, [isAdmin, tab])

  function onAvatar(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    if (!file) return
    if (!file.type.startsWith("image/")) {
      store.pushToast("warn", t("settings.avatarType"))
      return
    }
    if (file.size > 2 * 1024 * 1024) {
      store.pushToast("warn", t("settings.avatarSize"))
      return
    }
    const reader = new FileReader()
    reader.onload = () => {
      if (typeof reader.result === "string") setAvatarDataUrl(reader.result)
    }
    reader.readAsDataURL(file)
  }

  function saveProfile(event: FormEvent) {
    event.preventDefault()
    if (!actor) return
    if (notificationEmail.trim() && !isEmail(notificationEmail)) {
      setProfileError(t("settings.notificationInvalid"))
      return
    }
    setProfileError("")
    store.updateProfile(actor.id, {
      firstName: firstName.trim(),
      lastName: lastName.trim(),
      alias: alias.trim(),
      notificationEmail: notificationEmail.trim() || actor.email,
      avatarDataUrl,
      twoFactorEnabled,
    })
    store.pushToast("ok", t("settings.profileSaved"))
  }

  function savePassword(event: FormEvent) {
    event.preventDefault()
    if (!currentPassword || currentPassword.length < 12) {
      setPasswordError(t("settings.passwordCurrentShort"))
      return
    }
    if (nextPassword.length < 12) {
      setPasswordError(t("login.passwordShort"))
      return
    }
    if (nextPassword !== confirmPassword) {
      setPasswordError(t("settings.passwordMismatch"))
      return
    }
    if (currentPassword === "wrong-password") {
      setPasswordError(t("settings.passwordWrong"))
      return
    }
    setPasswordError("")
    setCurrentPassword("")
    setNextPassword("")
    setConfirmPassword("")
    store.pushToast("ok", t("settings.passwordSaved"))
  }

  function saveMail(event: FormEvent) {
    event.preventDefault()
    if (!isAdmin) return
    if (!mail.host.trim()) {
      setMailError(t("settings.mailHostRequired"))
      return
    }
    if (!mail.fromEmail.trim() || !isEmail(mail.fromEmail)) {
      setMailError(t("settings.mailFromInvalid"))
      return
    }
    setMailError("")
    store.setMailSettings(mail)
    store.pushToast("ok", t("settings.mailSaved"))
  }

  function testMail() {
    if (!isAdmin) return
    setMailTesting(true)
    window.setTimeout(() => {
      setMailTesting(false)
      if (!mail.host.trim()) {
        store.pushToast("warn", t("settings.mailHostRequired"))
        return
      }
      store.pushToast("ok", t("settings.mailTestOk"))
    }, 700)
  }

  const display = actor
    ? `${firstName} ${lastName}`.trim() || actor.name
    : ""

  return (
    <section className="settings-page-wrap">
      <header className="access-head">
        <div className="access-head-copy">
          <h1>{t("settings.title")}</h1>
          <p>{t("settings.lead")}</p>
        </div>
        <nav className="access-tabs" role="tablist" aria-label={t("settings.title")}>
          <button
            type="button"
            role="tab"
            aria-selected={tab === "profile"}
            className={tab === "profile" ? "access-tab active" : "access-tab"}
            onClick={() => setTab("profile")}
          >
            {t("settings.tabProfile")}
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={tab === "general"}
            className={tab === "general" ? "access-tab active" : "access-tab"}
            onClick={() => setTab("general")}
          >
            {t("settings.tabGeneral")}
          </button>
          {isAdmin ? (
            <button
              type="button"
              role="tab"
              aria-selected={tab === "mail"}
              className={tab === "mail" ? "access-tab active" : "access-tab"}
              onClick={() => setTab("mail")}
            >
              {t("settings.tabMail")}
            </button>
          ) : null}
        </nav>
      </header>

      {tab === "profile" && actor ? (
        <div className="settings-stack">
          <form className="panel settings-card" onSubmit={saveProfile}>
            <div className="settings-card-head">
              <h2>{t("settings.profile")}</h2>
              <p className="hint">{t("settings.profileLead")}</p>
            </div>

            <div className="profile-avatar-row">
              <button
                type="button"
                className="profile-avatar"
                onClick={() => fileRef.current?.click()}
                aria-label={t("settings.avatarChange")}
              >
                {avatarDataUrl ? (
                  <img src={avatarDataUrl} alt="" />
                ) : (
                  <span aria-hidden="true">{initials(display)}</span>
                )}
                <span className="profile-avatar-overlay" aria-hidden="true">
                  <Camera className="icon" />
                </span>
              </button>
              <div className="profile-avatar-copy">
                <div>
                  <strong>{display}</strong>
                  <p className="faint">{actor.email}</p>
                </div>
                <div className="profile-avatar-actions">
                  <button type="button" className="btn btn-secondary" onClick={() => fileRef.current?.click()}>
                    {t("settings.avatarChange")}
                  </button>
                  {avatarDataUrl ? (
                    <button type="button" className="btn btn-ghost" onClick={() => setAvatarDataUrl(null)}>
                      {t("settings.avatarRemove")}
                    </button>
                  ) : null}
                </div>
                <p className="hint">{t("settings.avatarHint")}</p>
              </div>
              <input
                ref={fileRef}
                className="sr"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                onChange={onAvatar}
              />
            </div>

            <div className="settings-form-grid">
              <label className="field">
                <span>{t("settings.firstName")}</span>
                <input value={firstName} onChange={(event) => setFirstName(event.target.value)} autoComplete="given-name" />
                <span className="field-hint">{t("settings.optional")}</span>
              </label>
              <label className="field">
                <span>{t("settings.lastName")}</span>
                <input value={lastName} onChange={(event) => setLastName(event.target.value)} autoComplete="family-name" />
                <span className="field-hint">{t("settings.optional")}</span>
              </label>
              <label className="field">
                <span>{t("settings.alias")}</span>
                <input value={alias} onChange={(event) => setAlias(event.target.value)} autoComplete="nickname" />
                <span className="field-hint">{t("settings.aliasHint")}</span>
              </label>
              <label className="field">
                <span>{t("settings.notificationEmail")}</span>
                <input
                  type="email"
                  value={notificationEmail}
                  onChange={(event) => setNotificationEmail(event.target.value)}
                  autoComplete="email"
                />
                <span className="field-hint">{t("settings.notificationHint")}</span>
              </label>
            </div>
            {profileError ? <p className="field-error">{profileError}</p> : null}

            <div className="settings-toggle-row">
              <div>
                <strong>{t("settings.twoFactor")}</strong>
                <p className="faint">{t("settings.twoFactorHint")}</p>
              </div>
              <button
                type="button"
                className="switch"
                role="switch"
                aria-checked={twoFactorEnabled}
                aria-label={t("settings.twoFactor")}
                onClick={() => setTwoFactorEnabled((value) => !value)}
              >
                <span />
              </button>
            </div>
            {twoFactorEnabled ? (
              <p className="access-note">
                <ShieldCheck className="icon inline-icon" aria-hidden="true" />
                {t("settings.twoFactorDemo")}
              </p>
            ) : null}

            <div className="setting-actions">
              <button type="submit" className="btn btn-primary">
                {t("settings.saveProfile")}
              </button>
            </div>
          </form>

          <form className="panel settings-card" onSubmit={savePassword}>
            <div className="settings-card-head">
              <h2>{t("settings.passwordTitle")}</h2>
              <p className="hint">{t("settings.passwordLead")}</p>
            </div>
            <div className="settings-form-grid single">
              <label className="field">
                <span>{t("settings.passwordCurrent")}</span>
                <input
                  type="password"
                  value={currentPassword}
                  onChange={(event) => setCurrentPassword(event.target.value)}
                  autoComplete="current-password"
                />
              </label>
              <label className="field">
                <span>{t("settings.passwordNew")}</span>
                <input
                  type="password"
                  value={nextPassword}
                  onChange={(event) => setNextPassword(event.target.value)}
                  autoComplete="new-password"
                />
              </label>
              <label className="field">
                <span>{t("settings.passwordConfirm")}</span>
                <input
                  type="password"
                  value={confirmPassword}
                  onChange={(event) => setConfirmPassword(event.target.value)}
                  autoComplete="new-password"
                />
              </label>
            </div>
            {passwordError ? <p className="field-error">{passwordError}</p> : null}
            <div className="setting-actions">
              <button type="submit" className="btn btn-secondary">
                {t("settings.passwordSave")}
              </button>
            </div>
          </form>
        </div>
      ) : null}

      {tab === "general" ? (
        <div className="settings-stack">
          <section className="panel settings-card">
            <div className="settings-card-head">
              <h2>{t("language")}</h2>
              <p className="hint">{t("settings.languageLead")}</p>
            </div>
            <div className="lang" role="group" aria-label={t("language")}>
              <button type="button" aria-pressed={current.language !== "en"} onClick={() => chooseLocale("de")}>
                {t("german")}
              </button>
              <button type="button" aria-pressed={current.language === "en"} onClick={() => chooseLocale("en")}>
                {t("english")}
              </button>
            </div>
          </section>
          <section className="panel settings-card">
            <div className="settings-card-head">
              <h2>{t("settings.viewAs")}</h2>
              <p className="hint">{t("settings.viewAsHint")}</p>
            </div>
            <label className="field">
              <span>{t("settings.viewAs")}</span>
              <select
                value={store.viewUserId ?? store.sessionUserId ?? ""}
                onChange={(event) => store.setViewUser(event.target.value)}
              >
                {store.users.map((user) => (
                  <option key={user.id} value={user.id}>
                    {user.active ? user.name : `${user.name} (${t("users.inactive")})`}
                  </option>
                ))}
              </select>
            </label>
          </section>
        </div>
      ) : null}

      {tab === "mail" && isAdmin ? (
        <form className="panel settings-card" onSubmit={saveMail}>
          <div className="settings-card-head">
            <h2>{t("settings.mailTitle")}</h2>
            <p className="hint">{t("settings.mailLead")}</p>
          </div>
          <div className="settings-form-grid">
            <label className="field">
              <span>{t("settings.mailHost")}</span>
              <input
                value={mail.host}
                onChange={(event) => setMail((currentMail) => ({ ...currentMail, host: event.target.value }))}
                placeholder="smtp.example.com"
                autoComplete="off"
              />
            </label>
            <label className="field">
              <span>{t("settings.mailPort")}</span>
              <input
                type="number"
                min={1}
                max={65535}
                value={mail.port}
                onChange={(event) =>
                  setMail((currentMail) => ({ ...currentMail, port: Number(event.target.value) || 587 }))
                }
              />
            </label>
            <label className="field">
              <span>{t("settings.mailEncryption")}</span>
              <select
                value={mail.encryption}
                onChange={(event) =>
                  setMail((currentMail) => ({
                    ...currentMail,
                    encryption: event.target.value as MailEncryption,
                  }))
                }
              >
                <option value="none">{t("settings.mailEncNone")}</option>
                <option value="starttls">{t("settings.mailEncStarttls")}</option>
                <option value="tls">{t("settings.mailEncTls")}</option>
              </select>
            </label>
            <label className="field">
              <span>{t("settings.mailUsername")}</span>
              <input
                value={mail.username}
                onChange={(event) => setMail((currentMail) => ({ ...currentMail, username: event.target.value }))}
                autoComplete="off"
              />
            </label>
            <label className="field">
              <span>{t("settings.mailPassword")}</span>
              <input
                type="password"
                value={mail.password}
                onChange={(event) => setMail((currentMail) => ({ ...currentMail, password: event.target.value }))}
                autoComplete="new-password"
              />
              <span className="field-hint">{t("settings.mailPasswordHint")}</span>
            </label>
            <label className="field">
              <span>{t("settings.mailFromName")}</span>
              <input
                value={mail.fromName}
                onChange={(event) => setMail((currentMail) => ({ ...currentMail, fromName: event.target.value }))}
              />
            </label>
            <label className="field">
              <span>{t("settings.mailFromEmail")}</span>
              <input
                type="email"
                value={mail.fromEmail}
                onChange={(event) => setMail((currentMail) => ({ ...currentMail, fromEmail: event.target.value }))}
                placeholder="noreply@example.com"
              />
            </label>
          </div>
          {mailError ? <p className="field-error">{mailError}</p> : null}
          <div className="setting-actions">
            <button type="button" className="btn btn-secondary" disabled={mailTesting} onClick={testMail}>
              {mailTesting ? t("settings.mailTesting") : t("settings.mailTest")}
            </button>
            <button type="submit" className="btn btn-primary">
              {t("settings.mailSave")}
            </button>
          </div>
        </form>
      ) : null}
    </section>
  )
}
