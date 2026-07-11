import { Crop, ImagePlus, Trash2 } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import type { FfbbOrganisme, MeResponse } from "@/features/auth/api";
import { PendingMembersSection } from "@/features/auth/PendingMembersSection";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { readableForeground } from "@/shared/lib/color";
import { extractPalette } from "@/shared/lib/palette";

import { useDownloadExport } from "@/features/profile/queries";

import { LogoCropper } from "./LogoCropper";
import { useDeleteLogo, useResetClub, useUpdateAppearance, useUpdateClubInfo, useUploadLogo } from "./queries";

const HEX = /^#[0-9a-fA-F]{6}$/;
const DEFAULT_ACCENT = "#3b82f6";

/** One accent picker (swatch + hex input + filled preview) for a given theme. */
function AccentField({ id, label, value, onChange }: { id: string; label: string; value: string; onChange: (v: string) => void }) {
  const valid = HEX.test(value);
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-xs text-muted-foreground">
        {label}
      </label>
      <div className="flex items-center gap-2">
        <input
          id={id}
          aria-label={`Couleur d'accent (${label})`}
          type="color"
          className="size-9 shrink-0 rounded border border-input bg-background"
          value={valid ? value : DEFAULT_ACCENT}
          onChange={(e) => onChange(e.target.value)}
        />
        <Input aria-label={`Couleur ${label} (hexadécimal)`} className="h-9 w-28 font-mono text-xs" value={value} placeholder="#3b82f6" onChange={(e) => onChange(e.target.value)} />
        {/* Filled swatch → the accent stays legible whatever its lightness. */}
        <div className="rounded-md px-3 py-2 text-sm font-medium" style={valid ? { backgroundColor: value, color: readableForeground(value) } : undefined}>
          Aa
        </div>
      </div>
    </div>
  );
}

function IdentitySection({
  accentColor,
  accentColorDark,
  accentPalette,
  logoUrl,
  clubName,
}: {
  accentColor: string | null;
  accentColorDark: string | null;
  accentPalette: string[] | null;
  logoUrl: string | null;
  clubName: string;
}) {
  const updateAppearance = useUpdateAppearance();
  const uploadLogo = useUploadLogo();
  const deleteLogo = useDeleteLogo();

  const [color, setColor] = useState(accentColor ?? DEFAULT_ACCENT);
  // Dark-theme accent defaults to the light one until the manager picks a distinct one.
  const [colorDark, setColorDark] = useState(accentColorDark ?? accentColor ?? DEFAULT_ACCENT);
  const [palette, setPalette] = useState<string[]>(accentPalette ?? []);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(logoUrl);
  const [cropFile, setCropFile] = useState<File | null>(null);

  const valid = HEX.test(color) && HEX.test(colorDark);
  const busy = uploadLogo.isPending || updateAppearance.isPending || deleteLogo.isPending;

  const onCropped = async (blob: Blob) => {
    const cropped = new File([blob], "logo.png", { type: "image/png" });
    setFile(cropped);
    setCropFile(null);
    const objectUrl = URL.createObjectURL(cropped);
    setPreview(objectUrl);
    const pal = await extractPalette(objectUrl);
    if (pal.length > 0) {
      setPalette(pal);
      setColor(pal[0]);
    }
  };

  const save = async () => {
    if (null !== file) {
      await uploadLogo.mutateAsync(file);
      setFile(null);
    }
    await updateAppearance.mutateAsync({ accentColor: color, accentColorDark: colorDark, ...(palette.length > 0 ? { accentPalette: palette } : {}) });
  };

  const removeLogo = () => {
    deleteLogo.mutate();
    setPreview(null);
    setFile(null);
    setPalette([]);
  };

  // Re-open the cropper on the CURRENT logo (fetch it back into a File).
  const recrop = async () => {
    if (null === preview) {
      return;
    }
    const res = await fetch(preview);
    const blob = await res.blob();
    setCropFile(new File([blob], "logo.png", { type: blob.type || "image/png" }));
  };

  return (
    <div className="space-y-5">
      <p className="text-sm text-muted-foreground">Logo et couleur d'accent du club. La couleur personnalise les boutons, liens et éléments actifs de toute l'interface.</p>
      {/* Logo — cropper when a new file is being framed, else preview + actions */}
        {null !== cropFile ? (
          <LogoCropper file={cropFile} onCropped={onCropped} onCancel={() => setCropFile(null)} />
        ) : (
          <div className="flex items-center gap-4">
            <button
              type="button"
              onClick={() => void recrop()}
              disabled={null === preview}
              title={null !== preview ? "Recadrer le logo" : undefined}
              className="flex size-16 items-center justify-center overflow-hidden rounded-full border border-border bg-muted text-lg font-bold text-muted-foreground enabled:cursor-pointer enabled:hover:opacity-90"
            >
              {null !== preview ? <img src={preview} alt="Logo" className="size-full object-cover" /> : clubName.trim().charAt(0).toUpperCase() || "C"}
            </button>
            <div className="flex flex-wrap items-center gap-2">
              <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 py-1.5 text-sm hover:bg-muted">
                <ImagePlus className="size-4" />
                Choisir un logo
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  className="hidden"
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (undefined !== f) {
                      setCropFile(f);
                    }
                    e.target.value = "";
                  }}
                />
              </label>
              {null !== preview ? (
                <Button size="sm" variant="outline" onClick={() => void recrop()} disabled={busy}>
                  <Crop className="size-4" />
                  Recadrer
                </Button>
              ) : null}
              {null !== logoUrl ? (
                <Button size="sm" variant="ghost" className="text-destructive" onClick={removeLogo} disabled={busy}>
                  <Trash2 className="size-4" />
                  Retirer
                </Button>
              ) : null}
              <span className="text-xs text-muted-foreground">PNG / JPEG / WebP, ≤ 500 Ko.</span>
            </div>
          </div>
        )}

        {/* Suggested palette from the logo */}
        {palette.length > 0 ? (
          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground">Couleurs du logo :</span>
            {palette.map((hex) => (
              <button
                key={hex}
                type="button"
                title={`Utiliser ${hex}`}
                aria-label={`Utiliser ${hex}`}
                onClick={() => setColor(hex)}
                className="size-6 rounded-full border border-border"
                style={{ backgroundColor: hex }}
              />
            ))}
          </div>
        ) : null}

        {/* Accent — one colour per theme (light + dark), applied by useApplyClubTheme. */}
        <div>
          <p className="mb-2 text-xs text-muted-foreground">Couleur d'accent — une pour le thème clair, une pour le sombre. Elle habille boutons, liens et éléments actifs de toute l'interface.</p>
          <div className="flex flex-wrap gap-4">
            <AccentField id="accent-light" label="Thème clair" value={color} onChange={setColor} />
            <AccentField id="accent-dark" label="Thème sombre" value={colorDark} onChange={setColorDark} />
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button onClick={save} disabled={!valid || busy}>
            {busy ? <Spinner className="size-4" /> : null}
            Enregistrer
          </Button>
          {(null !== accentColor || null !== accentColorDark) ? (
            <Button
              variant="ghost"
              onClick={() => {
                // Reset the server AND the local pickers, else a subsequent Save
                // re-persists the old colours the fields still show.
                setColor(DEFAULT_ACCENT);
                setColorDark(DEFAULT_ACCENT);
                updateAppearance.mutate({ accentColor: null, accentColorDark: null });
              }}
              disabled={busy}
            >
              Réinitialiser les couleurs
            </Button>
          ) : null}
        </div>
    </div>
  );
}

function DangerSection() {
  const reset = useResetClub();
  const navigate = useNavigate();
  const [confirm, setConfirm] = useState(false);

  return (
    <div className="space-y-3">
      <p className="text-sm text-muted-foreground">
        Supprime définitivement toutes les données saisies (équipes, gymnases, coachs, contraintes, plannings) pour repartir de zéro. Le club et les
        comptes membres sont conservés.
      </p>
      <Button variant="destructive" onClick={() => setConfirm(true)} disabled={reset.isPending}>
        {reset.isPending ? <Spinner className="size-4" /> : <Trash2 className="size-4" />}
        Réinitialiser le club
      </Button>

      <ConfirmDialog
        open={confirm}
        title="Réinitialiser le club ?"
        description="Toutes les équipes, gymnases, coachs, contraintes et plannings seront définitivement supprimés. Cette action est irréversible."
        confirmLabel="Tout supprimer"
        onCancel={() => setConfirm(false)}
        onConfirm={() => {
          setConfirm(false);
          reset.mutate(undefined, { onSuccess: () => navigate("/wizard") });
        }}
      />
    </div>
  );
}

/** One labelled text field bound to local form state. */
function InfoField({ label, value, onChange, type = "text", placeholder }: { label: string; value: string; onChange: (v: string) => void; type?: string; placeholder?: string }) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs text-muted-foreground">{label}</span>
      <Input type={type} value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
    </label>
  );
}

const INFO_KEYS = [
  "committeeCode", "contactPhone", "contactEmail", "address",
  "correspondentName", "correspondentPhone", "correspondentEmail",
  "presidentName", "presidentPhone", "presidentEmail",
  "mainVenueName", "mainVenueAddress",
] as const;

type InfoKey = (typeof INFO_KEYS)[number];

/** FFBB club info (lot B): read-only identity + editable contacts. */
function ClubInfoSection({ club }: { club: NonNullable<MeResponse["club"]> }) {
  const update = useUpdateClubInfo();
  // Seeded from the server values. The parent remounts this component (key on
  // the server signature) when ["me"] refetches, so the inputs re-sync after a
  // save instead of going stale — no effect needed.
  const [form, setForm] = useState<Record<InfoKey, string>>(
    () => Object.fromEntries(INFO_KEYS.map((k) => [k, club[k] ?? ""])) as Record<InfoKey, string>,
  );
  const set = (k: InfoKey) => (v: string) => setForm((f) => ({ ...f, [k]: v }));

  // PATCH is partial: send only the fields the admin actually changed, so a
  // concurrent edit on an untouched field is not clobbered (last-write-wins).
  const save = () => {
    const changed = Object.fromEntries(
      INFO_KEYS.filter((k) => (form[k].trim() || null) !== (club[k] ?? null)).map((k) => [k, form[k].trim() || null]),
    );
    if (Object.keys(changed).length > 0) update.mutate(changed);
  };

  return (
    <div className="space-y-5">
      <div>
        <h3 className="mb-2 text-sm font-semibold">Identité</h3>
        <div className="grid grid-cols-2 gap-3 text-sm">
          <div>
            <span className="mb-1 block text-xs text-muted-foreground">Code FFBB</span>
            <p className="font-mono">{club.ffbbClubCode ?? "—"}</p>
          </div>
          <div>
            <span className="mb-1 block text-xs text-muted-foreground">Ligue</span>
            <p>{club.league ?? "—"}</p>
          </div>
          <div>
            <span className="mb-1 block text-xs text-muted-foreground">Zone de vacances</span>
            <p>{club.schoolZone ?? "—"}</p>
          </div>
          <InfoField label="Comité" value={form.committeeCode} onChange={set("committeeCode")} placeholder="0069" />
        </div>
      </div>

      <div>
        <h3 className="mb-2 text-sm font-semibold">Contact du club</h3>
        <div className="grid grid-cols-2 gap-3">
          <InfoField label="Téléphone" value={form.contactPhone} onChange={set("contactPhone")} type="tel" />
          <InfoField label="Email" value={form.contactEmail} onChange={set("contactEmail")} type="email" />
          <label className="col-span-2 block">
            <span className="mb-1 block text-xs text-muted-foreground">Adresse</span>
            <Input value={form.address} onChange={(e) => set("address")(e.target.value)} />
          </label>
        </div>
      </div>

      <div>
        <h3 className="mb-2 text-sm font-semibold">Correspondant</h3>
        <div className="grid grid-cols-2 gap-3">
          <InfoField label="Nom" value={form.correspondentName} onChange={set("correspondentName")} />
          <InfoField label="Téléphone" value={form.correspondentPhone} onChange={set("correspondentPhone")} type="tel" />
          <InfoField label="Email" value={form.correspondentEmail} onChange={set("correspondentEmail")} type="email" />
        </div>
      </div>

      <div>
        <h3 className="mb-2 text-sm font-semibold">Président</h3>
        <div className="grid grid-cols-2 gap-3">
          <InfoField label="Nom" value={form.presidentName} onChange={set("presidentName")} />
          <InfoField label="Téléphone" value={form.presidentPhone} onChange={set("presidentPhone")} type="tel" />
          <InfoField label="Email" value={form.presidentEmail} onChange={set("presidentEmail")} type="email" />
        </div>
      </div>

      <div>
        <h3 className="mb-2 text-sm font-semibold">Salle principale</h3>
        <div className="grid grid-cols-2 gap-3">
          <InfoField label="Nom" value={form.mainVenueName} onChange={set("mainVenueName")} />
          <label className="block">
            <span className="mb-1 block text-xs text-muted-foreground">Adresse</span>
            <Input value={form.mainVenueAddress} onChange={(e) => set("mainVenueAddress")(e.target.value)} />
          </label>
        </div>
      </div>

      <div className="flex justify-end">
        <Button onClick={save} disabled={update.isPending}>
          Enregistrer
        </Button>
      </div>
    </div>
  );
}

/** Only allow http(s) links — an FFBB-sourced `javascript:`/`data:` URL must never reach href (XSS). */
function safeHttpUrl(url: string | null | undefined): string | null {
  if (!url) return null;
  try {
    const parsed = new URL(url);
    return parsed.protocol === "http:" || parsed.protocol === "https:" ? parsed.href : null;
  } catch {
    return null;
  }
}

/** One read-only FFBB contact block (Club / Comité / Ligue). */
function ContactBlock({ title, data }: { title: string; data: (FfbbOrganisme & { website?: string | null }) | null }) {
  const filled = data && (data.address || data.city || data.phone || data.email);
  const website = safeHttpUrl(data?.website);
  return (
    <div className="rounded-lg border border-border p-3">
      <div className="mb-2 flex items-center gap-2">
        {data?.logoUrl ? <img src={data.logoUrl} alt="" className="h-8 w-8 shrink-0 object-contain" /> : null}
        <div className="min-w-0">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">{title}</p>
          <p className="truncate text-sm font-semibold">{data?.name ?? "—"}</p>
        </div>
      </div>
      {filled ? (
        <dl className="space-y-0.5 text-sm">
          {data.address ? <dd>{data.address}</dd> : null}
          {data.postalCode || data.city ? <dd>{[data.postalCode, data.city].filter(Boolean).join(" ")}</dd> : null}
          {data.phone ? <dd>{data.phone}</dd> : null}
          {data.email ? (
            <dd>
              <a href={`mailto:${data.email}`} className="text-accent underline underline-offset-2">
                {data.email}
              </a>
            </dd>
          ) : null}
          {website ? (
            <dd>
              <a href={website} target="_blank" rel="noreferrer" className="text-accent underline underline-offset-2">
                {website}
              </a>
            </dd>
          ) : null}
        </dl>
      ) : (
        <p className="text-xs text-muted-foreground">Données FFBB non disponibles.</p>
      )}
    </div>
  );
}

/** FFBB institutional contacts (lot C): Club · Comité · Ligue, read-only. */
function ContactsFfbbSection({ club }: { club: NonNullable<MeResponse["club"]> }) {
  return (
    <div className="grid gap-3 sm:grid-cols-3">
      <ContactBlock
        title="Club"
        data={{
          name: club.name,
          address: club.address,
          postalCode: club.postalCode,
          city: club.city,
          phone: club.contactPhone,
          email: club.contactEmail,
          logoUrl: club.logoUrl,
          website: club.website,
        }}
      />
      <ContactBlock title="Comité" data={club.ffbbCommittee} />
      <ContactBlock title="Ligue" data={club.ffbbLeague} />
    </div>
  );
}

/** RGPD — portabilité : export JSON complet du workspace du club (management). */
function ExportClubSection() {
  const exportDownload = useDownloadExport();
  return (
    <div className="space-y-3">
      <p className="text-sm text-muted-foreground">
        Téléchargez une copie complète des données du club (saisons, équipes, coachs, gymnases, contraintes,
        plannings, matchs…) au format JSON — conformité RGPD (portabilité).
      </p>
      <Button
        type="button"
        variant="outline"
        disabled={exportDownload.isPending}
        onClick={() => exportDownload.mutate({ path: "club/export", filename: "donnees-club.json" })}
      >
        {exportDownload.isPending ? <Spinner className="size-4" /> : null}
        Exporter les données du club
      </Button>
    </div>
  );
}

function ClubHub({ me }: { me: MeResponse }) {
  const isAdmin = me.role === "admin";
  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-1 border-l-[3px] border-accent pl-3 text-xl font-semibold">Gestion du club</h1>
      <p className="mb-4 text-sm text-muted-foreground">{me.club?.name ?? "—"}</p>
      <div className="space-y-3">
        {isAdmin ? (
          <AccordionSection title="Demandes" defaultOpen>
            <p className="mb-3 text-sm text-muted-foreground">Approuvez ou refusez les personnes qui souhaitent rejoindre votre club.</p>
            <PendingMembersSection />
          </AccordionSection>
        ) : null}
        <AccordionSection title="Visuel">
          <IdentitySection
            accentColor={me.club?.accentColor ?? null}
            accentColorDark={me.club?.accentColorDark ?? null}
            accentPalette={me.club?.accentPalette ?? null}
            logoUrl={me.club?.logoUrl ?? null}
            clubName={me.club?.name ?? ""}
          />
        </AccordionSection>
        {isAdmin && me.club ? (
          <AccordionSection title="Informations du club">
            {/* key = server signature: remount (re-seed the form) when ["me"] refetches after a save. */}
            <ClubInfoSection key={INFO_KEYS.map((k) => me.club![k] ?? "").join("")} club={me.club} />
          </AccordionSection>
        ) : null}
        {isAdmin && me.club ? (
          <AccordionSection title="Contacts FFBB">
            <p className="mb-3 text-sm text-muted-foreground">
              Coordonnées récupérées automatiquement depuis la FFBB (lecture seule).
            </p>
            <ContactsFfbbSection club={me.club} />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title="Exporter les données">
            <ExportClubSection />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title="Réinitialiser le club">
            <DangerSection />
          </AccordionSection>
        ) : null}
      </div>
    </div>
  );
}

export function ClubPage() {
  const { data: me, isLoading } = useMe();
  if (isLoading || undefined === me) {
    return <FullPageSpinner />;
  }
  return <ClubHub me={me} />;
}
