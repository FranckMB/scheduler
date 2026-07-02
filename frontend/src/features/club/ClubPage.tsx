import { ImagePlus, Trash2 } from "lucide-react";
import { useState } from "react";

import { useMe } from "@/features/auth/queries";
import type { MeResponse } from "@/features/auth/api";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { readableForeground } from "@/shared/lib/color";
import { extractPalette } from "@/shared/lib/palette";

import { LogoCropper } from "./LogoCropper";
import { useDeleteLogo, useUpdateAppearance, useUploadLogo } from "./queries";

const HEX = /^#[0-9a-fA-F]{6}$/;
const DEFAULT_ACCENT = "#3b82f6";

function IdentitySection({ accentColor, accentPalette, logoUrl, clubName }: { accentColor: string | null; accentPalette: string[] | null; logoUrl: string | null; clubName: string }) {
  const updateAppearance = useUpdateAppearance();
  const uploadLogo = useUploadLogo();
  const deleteLogo = useDeleteLogo();

  const [color, setColor] = useState(accentColor ?? DEFAULT_ACCENT);
  const [palette, setPalette] = useState<string[]>(accentPalette ?? []);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(logoUrl);
  const [cropFile, setCropFile] = useState<File | null>(null);

  const valid = HEX.test(color);
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
    await updateAppearance.mutateAsync({ accentColor: color, ...(palette.length > 0 ? { accentPalette: palette } : {}) });
  };

  const removeLogo = () => {
    deleteLogo.mutate();
    setPreview(null);
    setFile(null);
    setPalette([]);
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Identité</CardTitle>
        <CardDescription>Logo et couleur d'accent du club. La couleur personnalise les boutons, liens et éléments actifs de toute l'interface.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        {/* Logo — cropper when a new file is being framed, else preview + actions */}
        {null !== cropFile ? (
          <LogoCropper file={cropFile} onCropped={onCropped} onCancel={() => setCropFile(null)} />
        ) : (
          <div className="flex items-center gap-4">
            <div className="flex size-16 items-center justify-center overflow-hidden rounded-full border border-border bg-muted text-lg font-bold text-muted-foreground">
              {null !== preview ? <img src={preview} alt="Logo" className="size-full object-cover" /> : clubName.trim().charAt(0).toUpperCase() || "C"}
            </div>
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

        {/* Accent */}
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="accent" className="mb-1 block text-xs text-muted-foreground">
              Couleur d'accent
            </label>
            <div className="flex items-center gap-2">
              <input
                id="accent"
                aria-label="Couleur d'accent"
                type="color"
                className="size-9 shrink-0 rounded border border-input bg-background"
                value={valid ? color : DEFAULT_ACCENT}
                onChange={(e) => setColor(e.target.value)}
              />
              <Input aria-label="Couleur (hexadécimal)" className="h-9 w-28 font-mono text-xs" value={color} placeholder="#3b82f6" onChange={(e) => setColor(e.target.value)} />
            </div>
          </div>
          <div className="rounded-md px-3 py-2 text-sm font-medium" style={valid ? { backgroundColor: color, color: readableForeground(color) } : undefined}>
            Aperçu
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button onClick={save} disabled={!valid || busy}>
            {busy ? <Spinner className="size-4" /> : null}
            Enregistrer
          </Button>
          {null !== accentColor ? (
            <Button variant="ghost" onClick={() => updateAppearance.mutate({ accentColor: null })} disabled={busy}>
              Réinitialiser la couleur
            </Button>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}

function ClubHub({ me }: { me: MeResponse }) {
  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-1 text-xl font-semibold">Gestion du club</h1>
      <p className="mb-4 text-sm text-muted-foreground">{me.club?.name ?? "—"}</p>
      {/* Hub extensible : membres, réinitialisation… viendront ici. */}
      <IdentitySection
        accentColor={me.club?.accentColor ?? null}
        accentPalette={me.club?.accentPalette ?? null}
        logoUrl={me.club?.logoUrl ?? null}
        clubName={me.club?.name ?? ""}
      />
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
