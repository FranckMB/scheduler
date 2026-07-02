import { useEffect, useState } from "react";

const PHRASES = [
  "Placement des équipes prioritaires…",
  "Respect des disponibilités des gymnases…",
  "Vérification des créneaux des coachs…",
  "Application de vos contraintes…",
  "Optimisation de la répartition sur la semaine…",
  "Recherche du meilleur planning possible…",
];

/** Animated waiting screen shown while a schedule generates (first run and regenerations). */
export function GenerationWaiting({ initial, logoUrl }: { initial: string; logoUrl: string | null }) {
  const [i, setI] = useState(0);
  useEffect(() => {
    const t = setInterval(() => setI((n) => (n + 1) % PHRASES.length), 3000);
    return () => clearInterval(t);
  }, []);
  return (
    <div className="flex flex-col items-center gap-6 py-12 text-center">
      <div className="relative flex size-24 items-center justify-center">
        <span className="absolute inline-flex size-full animate-ping rounded-full bg-accent/20" />
        <span className="relative inline-flex size-20 animate-pulse items-center justify-center overflow-hidden rounded-full bg-accent/15 text-2xl font-bold text-accent">
          {null !== logoUrl ? <img src={logoUrl} alt="" className="size-full object-cover" /> : initial}
        </span>
      </div>
      <div className="space-y-1">
        <p className="text-lg font-medium">Génération du planning…</p>
        <p key={i} className="animate-in fade-in text-sm text-muted-foreground">
          {PHRASES[i]}
        </p>
      </div>
      <p className="max-w-sm text-xs text-muted-foreground">La génération peut prendre 1 à 3 min selon la taille du club. Vous pouvez laisser cet écran ouvert.</p>
    </div>
  );
}
