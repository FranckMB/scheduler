import { useState } from "react";

import { useMe } from "@/features/auth/queries";
import type { MeResponse } from "@/features/auth/api";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { readableForeground } from "@/shared/lib/color";

import { useUpdateAppearance } from "./queries";

const HEX = /^#[0-9a-fA-F]{6}$/;
const DEFAULT_ACCENT = "#3b82f6";

function IdentitySection({ accentColor }: { accentColor: string | null }) {
  const update = useUpdateAppearance();
  const [color, setColor] = useState(accentColor ?? DEFAULT_ACCENT);
  const valid = HEX.test(color);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Identité</CardTitle>
        <CardDescription>La couleur d'accent personnalise les boutons, liens et éléments actifs de tout le club.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
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
          {/* Aperçu : un bouton dans la couleur choisie (montre aussi le contraste du texte). */}
          <div className="rounded-md px-3 py-2 text-sm font-medium" style={valid ? { backgroundColor: color, color: readableForeground(color) } : undefined}>
            Aperçu
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button onClick={() => update.mutate({ accentColor: color })} disabled={!valid || update.isPending}>
            Enregistrer
          </Button>
          {null !== accentColor ? (
            <Button variant="ghost" onClick={() => update.mutate({ accentColor: null })} disabled={update.isPending}>
              Réinitialiser
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
      {/* Hub extensible : logo, membres, réinitialisation… viendront ici. */}
      <IdentitySection accentColor={me.club?.accentColor ?? null} />
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
