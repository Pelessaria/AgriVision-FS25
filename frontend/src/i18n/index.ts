import i18n from "i18next"
import { initReactI18next } from "react-i18next"
import { de } from "./de"
import { en } from "./en"

function storedLocale() {
  try {
    const stored = localStorage.getItem("fs25.locale")
    return stored === "en" || stored === "de" ? stored : "de"
  } catch {
    return "de"
  }
}

const lng = storedLocale()
document.documentElement.lang = lng

void i18n.use(initReactI18next).init({
  resources: {
    de: { translation: de },
    en: { translation: en },
  },
  lng,
  fallbackLng: "en",
  interpolation: { escapeValue: false },
})

export { i18n }
