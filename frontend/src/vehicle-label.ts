import type { Vehicle } from "./types"

/** Engine often sends $l10n_shopItem_fooBar — show a readable fallback. */
export function vehicleDisplayName(name: string) {
  const raw = name.trim()
  const match = raw.match(/^\$l10n_(?:shopItem_)?(.+)$/i)
  if (!match?.[1]) return raw
  return match[1]
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .replace(/[_-]+/g, " ")
    .trim()
}

export function modDisplayName(modKey: string | null | undefined) {
  if (!modKey) return null
  return modKey.replace(/^FS25_/i, "").replace(/_/g, " ")
}

export function modZipLabel(modKey: string | null | undefined) {
  if (!modKey) return null
  return modKey.toLowerCase().endsWith(".zip") ? modKey : `${modKey}.zip`
}

/** Longest keys first. Keys of length ≤ 3 match whole tokens only. */
const BRANDS: readonly [string, string][] = [
  ["MASSEYFERGUSON", "Massey Ferguson"],
  ["SCHUITEMAKER", "Schuitemaker"],
  ["ROSTSELMASH", "Rostselmash"],
  ["KVERNELAND", "Kverneland"],
  ["ANNABURGER", "Annaburger"],
  ["STRAUTMANN", "Strautmann"],
  ["KOECKERLING", "Köckerling"],
  ["KOCKERLING", "Köckerling"],
  ["NEWHOLLAND", "New Holland"],
  ["ZUNHAMMER", "Zunhammer"],
  ["WEIDEMANN", "Weidemann"],
  ["DEUTZFAHR", "Deutz-Fahr"],
  ["POETTINGER", "Pöttinger"],
  ["POTTINGER", "Pöttinger"],
  ["VAEDERSTAD", "Väderstad"],
  ["VADERSTAD", "Väderstad"],
  ["CHALLENGER", "Challenger"],
  ["JOHNDEERE", "John Deere"],
  ["VERSATILE", "Versatile"],
  ["LIEBHERR", "Liebherr"],
  ["MANITOU", "Manitou"],
  ["BERGMANN", "Bergmann"],
  ["EINBOECK", "Einböck"],
  ["TREFFLER", "Treffler"],
  ["BRANTNER", "Brantner"],
  ["FARMTECH", "Farmtech"],
  ["VEENHUIS", "Veenhuis"],
  ["ANDERSON", "Anderson"],
  ["GOEWEIL", "Göweil"],
  ["AMAZONE", "Amazone"],
  ["KRAMER", "Kramer"],
  ["HORSCH", "Horsch"],
  ["LEMKEN", "Lemken"],
  ["GRIMME", "Grimme"],
  ["FLIEGL", "Fliegl"],
  ["KUBOTA", "Kubota"],
  ["MEILLER", "Meiller"],
  ["KAWECO", "Kaweco"],
  ["JOSKIN", "Joskin"],
  ["LINDNER", "Lindner"],
  ["SCANIA", "Scania"],
  ["SAMSON", "Samson"],
  ["FENDT", "Fendt"],
  ["CLAAS", "CLAAS"],
  ["VALTRA", "Valtra"],
  ["STEYR", "Steyr"],
  ["MERLO", "Merlo"],
  ["KRONE", "Krone"],
  ["KUHN", "Kuhn"],
  ["KOTTE", "Kotte"],
  ["VOLVO", "Volvo"],
  ["CASEIH", "Case IH"],
  ["AGCO", "AGCO"],
  ["SAME", "SAME"],
  ["JCB", "JCB"],
  ["MAN", "MAN"],
]

function compact(value: string) {
  return value.toUpperCase().replace(/[^A-Z0-9]/g, "")
}

function tokens(value: string) {
  return value
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .toUpperCase()
    .split(/[^A-Z0-9]+/)
    .filter(Boolean)
}

function matchBrand(source: string) {
  const folded = compact(source)
  const parts = new Set(tokens(source))
  for (const [key, label] of BRANDS) {
    if (key.length <= 3) {
      if (parts.has(key)) return label
      continue
    }
    if (folded.includes(key) || parts.has(key)) return label
  }
  return null
}

export function isPallet(vehicle: Pick<Vehicle, "category" | "filename" | "storeKey" | "name">) {
  if (vehicle.category === "pallet") return true
  const hay = `${vehicle.filename ?? ""} ${vehicle.storeKey ?? ""} ${vehicle.name ?? ""}`.toLowerCase()
  return hay.includes("pallet")
}

export function vehicleBrand(vehicle: Pick<Vehicle, "name" | "brand" | "filename" | "modKey">) {
  const explicit = vehicle.brand?.trim()
  if (explicit) return explicit
  for (const source of [vehicle.modKey, vehicle.filename, vehicle.name]) {
    if (!source) continue
    const found = matchBrand(source)
    if (found) return found
  }
  return null
}
