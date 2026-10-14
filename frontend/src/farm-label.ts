import type { TFunction } from "i18next"
import type { Farm } from "./types"

export function farmDisplayName(farm: Pick<Farm, "name" | "gameFarmId">, t: TFunction): string {
  if (farm.name && farm.name.trim() !== "") return farm.name
  return t("farm.stubName", { id: farm.gameFarmId })
}
