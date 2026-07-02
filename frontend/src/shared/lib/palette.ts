/** Extract up to 3 dominant colours from an image URL, client-side via canvas. */

function toHex(r: number, g: number, b: number): string {
  const h = (v: number) => Math.max(0, Math.min(255, v)).toString(16).padStart(2, "0");
  return `#${h(r)}${h(g)}${h(b)}`;
}

export function extractPalette(url: string): Promise<string[]> {
  return new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = () => {
      const size = 48;
      const canvas = document.createElement("canvas");
      canvas.width = size;
      canvas.height = size;
      const ctx = canvas.getContext("2d");
      if (null === ctx) {
        resolve([]);
        return;
      }
      ctx.drawImage(img, 0, 0, size, size);
      let data: Uint8ClampedArray;
      try {
        data = ctx.getImageData(0, 0, size, size).data;
      } catch {
        resolve([]);
        return;
      }

      // Bucket colours by 5-bit-per-channel quantisation; keep count + sum for the average.
      const buckets = new Map<number, { n: number; r: number; g: number; b: number }>();
      for (let i = 0; i < data.length; i += 4) {
        const a = data[i + 3];
        if (a < 128) {
          continue; // skip transparent
        }
        const r = data[i];
        const g = data[i + 1];
        const b = data[i + 2];
        const key = ((r >> 3) << 10) | ((g >> 3) << 5) | (b >> 3);
        const cur = buckets.get(key) ?? { n: 0, r: 0, g: 0, b: 0 };
        cur.n += 1;
        cur.r += r;
        cur.g += g;
        cur.b += b;
        buckets.set(key, cur);
      }

      const sorted = [...buckets.values()].sort((x, y) => y.n - x.n).map((c) => ({ r: c.r / c.n, g: c.g / c.n, b: c.b / c.n }));

      // Pick up to 3, skipping near-duplicates of already-picked colours.
      const picked: { r: number; g: number; b: number }[] = [];
      const far = (c: { r: number; g: number; b: number }) => picked.every((p) => Math.abs(p.r - c.r) + Math.abs(p.g - c.g) + Math.abs(p.b - c.b) > 60);
      for (const c of sorted) {
        if (far(c)) {
          picked.push(c);
        }
        if (3 === picked.length) {
          break;
        }
      }
      resolve(picked.map((c) => toHex(Math.round(c.r), Math.round(c.g), Math.round(c.b))));
    };
    img.onerror = () => resolve([]);
    img.src = url;
  });
}
