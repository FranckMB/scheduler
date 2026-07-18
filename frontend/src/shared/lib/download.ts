/** Trigger a browser download of a URL (same-origin or blob:) under a chosen filename. */
export function download(url: string, filename: string): void {
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.rel = "noopener";
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Revoke on the next macrotask: a synchronous revoke can cancel the download
  // the click just started in some browsers.
  if (url.startsWith("blob:")) {
    setTimeout(() => URL.revokeObjectURL(url), 30_000);
  }
}

/** Download a Blob under a chosen filename (objectURL lifecycle handled). */
export function downloadBlob(blob: Blob, filename: string): void {
  download(URL.createObjectURL(blob), filename);
}

/**
 * Turn a human plan name into a safe filename base: accents stripped (NFD),
 * anything non-alphanumeric collapsed to "-", lowercased. Empty → "planning".
 */
export function slugFilename(name: string): string {
  const slug = name
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-zA-Z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .toLowerCase();
  return "" === slug ? "planning" : slug;
}
