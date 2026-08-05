import type { ReactNode, SelectHTMLAttributes } from "react";

import { cn } from "@/shared/lib/utils";

import { Select } from "./select";
import { VenueSwatch } from "./venue-swatch";

interface VenueLike {
  id: string;
  name: string;
  color: string | null;
}

interface VenueSelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, "children"> {
  venues: VenueLike[];
  /** Optional leading option (e.g. "— gymnase —") rendered before the venues. */
  placeholder?: string;
  /** Options de TÊTE rendues avant les gymnases (ex. placeholder disabled). */
  children?: ReactNode;
  /** Classes for the wrapping flex box (width lives here, not on the select). */
  wrapperClassName?: string;
}

/**
 * Sélecteur de gymnase partagé (demande fondateur 2026-08-05) : la pastille de
 * couleur du gymnase SÉLECTIONNÉ est accolée au champ — l'identification d'un
 * coup d'œil, sans renoncer au `<select>` natif (clavier, mobile, tests).
 * ⚠ Limite assumée : les `<option>` natives ne peuvent pas porter de pastille —
 * la liste OUVERTE reste textuelle. La pastille DANS la liste n'existe que sur
 * les listes custom (ex. « Associer à… » du panneau salles à proximité).
 */
export function VenueSelect({ venues, placeholder, children, className, wrapperClassName, ...props }: VenueSelectProps) {
  const selected = venues.find((v) => v.id === String(props.value ?? ""));
  return (
    <span className={cn("inline-flex items-center gap-1.5", wrapperClassName)}>
      <VenueSwatch color={selected?.color ?? null} className="size-2.5" />
      <Select className={cn("min-w-0 flex-1", className)} {...props}>
        {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
        {children}
        {venues.map((v) => (
          <option key={v.id} value={v.id}>
            {v.name}
          </option>
        ))}
      </Select>
    </span>
  );
}
