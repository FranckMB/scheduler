/**
 * Copie un texte dans le presse-papier. `navigator.clipboard` d'abord (contexte
 * sécurisé), repli sur une `<textarea>` + `execCommand("copy")` pour les vieux
 * navigateurs / http local. Renvoie `true` si la copie a abouti.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
  if (typeof navigator !== "undefined" && navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // Permission refusée / hors contexte sécurisé → on tente le repli.
    }
  }

  if (typeof document === "undefined") {
    return false;
  }
  try {
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    const ok = document.execCommand("copy");
    document.body.removeChild(textarea);
    return ok;
  } catch {
    return false;
  }
}
