import { Crop, ImagePlus, Trash2 } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import type { MeResponse } from "@/features/auth/api";
import { PendingMembersSection } from "@/features/auth/PendingMembersSection";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { extractPalette } from "@/shared/lib/palette";

import { LogoCropper } from "./LogoCropper";
import { useDeleteLogo, useResetClub, useUpdateAppearance, useUploadLogo } from "./queries";

const HEX = /^#[0-9a-fA-F]{6}$/;
const DEFAULT_ACCENT = "#3b82f6";

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
            <div>
              <label htmlFor="accent-light" className="mb-1 block text-xs text-muted-foreground">
                Thème clair
              </label>
              <div className="flex items-center gap-2">
                <input
                  id="accent-light"
                  aria-label="Couleur d'accent (thème clair)"
                  type="color"
                  className="size-9 shrink-0 rounded border border-input bg-background"
                  value={HEX.test(color) ? color : DEFAULT_ACCENT}
                  onChange={(e) => setColor(e.target.value)}
                />
                <Input aria-label="Couleur claire (hexadécimal)" className="h-9 w-28 font-mono text-xs" value={color} placeholder="#3b82f6" onChange={(e) => setColor(e.target.value)} />
                <div className="rounded-md border border-border bg-white px-3 py-2 text-sm font-medium" style={HEX.test(color) ? { color } : undefined}>
                  Aa
                </div>
              </div>
            </div>
            <div>
              <label htmlFor="accent-dark" className="mb-1 block text-xs text-muted-foreground">
                Thème sombre
              </label>
              <div className="flex items-center gap-2">
                <input
                  id="accent-dark"
                  aria-label="Couleur d'accent (thème sombre)"
                  type="color"
                  className="size-9 shrink-0 rounded border border-input bg-background"
                  value={HEX.test(colorDark) ? colorDark : DEFAULT_ACCENT}
                  onChange={(e) => setColorDark(e.target.value)}
                />
                <Input aria-label="Couleur sombre (hexadécimal)" className="h-9 w-28 font-mono text-xs" value={colorDark} placeholder="#3b82f6" onChange={(e) => setColorDark(e.target.value)} />
                <div className="rounded-md border border-border bg-neutral-900 px-3 py-2 text-sm font-medium" style={HEX.test(colorDark) ? { color: colorDark } : undefined}>
                  Aa
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button onClick={save} disabled={!valid || busy}>
            {busy ? <Spinner className="size-4" /> : null}
            Enregistrer
          </Button>
          {(null !== accentColor || null !== accentColorDark) ? (
            <Button variant="ghost" onClick={() => updateAppearance.mutate({ accentColor: null, accentColorDark: null })} disabled={busy}>
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
