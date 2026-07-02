import { useEffect, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";

const BOX = 220; // circular crop viewport (px)
const OUT = 256; // exported square size (px)

/**
 * Circular logo cropper: pan (drag) + zoom, then export the framed area as a
 * square PNG so every display (bubble/header/waiting) just uses object-cover on
 * an already-framed image — no per-display transform.
 */
export function LogoCropper({ file, onCropped, onCancel }: { file: File; onCropped: (blob: Blob) => void; onCancel: () => void }) {
  const [img, setImg] = useState<HTMLImageElement | null>(null);
  const [scale, setScale] = useState(1);
  const [offset, setOffset] = useState({ x: 0, y: 0 });
  const [minScale, setMinScale] = useState(1);
  const drag = useRef<{ x: number; y: number } | null>(null);

  useEffect(() => {
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
      // Base scale so the image covers the crop box.
      const cover = Math.max(BOX / image.width, BOX / image.height);
      setMinScale(cover);
      setScale(cover);
      setOffset({ x: 0, y: 0 });
      setImg(image);
    };
    image.src = url;
    return () => URL.revokeObjectURL(url);
  }, [file]);

  const onPointerDown = (e: React.PointerEvent) => {
    drag.current = { x: e.clientX - offset.x, y: e.clientY - offset.y };
    (e.target as Element).setPointerCapture(e.pointerId);
  };
  const onPointerMove = (e: React.PointerEvent) => {
    if (null === drag.current) {
      return;
    }
    setOffset({ x: e.clientX - drag.current.x, y: e.clientY - drag.current.y });
  };
  const onPointerUp = () => {
    drag.current = null;
  };

  const validate = () => {
    if (null === img) {
      return;
    }
    const canvas = document.createElement("canvas");
    canvas.width = OUT;
    canvas.height = OUT;
    const ctx = canvas.getContext("2d");
    if (null === ctx) {
      return;
    }
    const ratio = OUT / BOX;
    const w = img.width * scale * ratio;
    const h = img.height * scale * ratio;
    // Centre + offset, same framing as the on-screen circle.
    const x = OUT / 2 - w / 2 + offset.x * ratio;
    const y = OUT / 2 - h / 2 + offset.y * ratio;
    ctx.drawImage(img, x, y, w, h);
    canvas.toBlob((blob) => {
      if (null !== blob) {
        onCropped(blob);
      }
    }, "image/png");
  };

  return (
    <div className="flex flex-col items-center gap-3 rounded-lg border border-border bg-card p-4">
      <div
        className="relative cursor-move touch-none overflow-hidden rounded-full border border-border bg-muted"
        style={{ width: BOX, height: BOX }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerLeave={onPointerUp}
      >
        {null !== img ? (
          <img
            src={img.src}
            alt="Cadrage du logo"
            draggable={false}
            className="pointer-events-none absolute left-1/2 top-1/2 max-w-none select-none"
            style={{ width: img.width * scale, height: img.height * scale, transform: `translate(-50%, -50%) translate(${offset.x}px, ${offset.y}px)` }}
          />
        ) : null}
      </div>
      <label className="flex w-full max-w-xs items-center gap-2 text-xs text-muted-foreground">
        Zoom
        <input type="range" min={minScale} max={minScale * 3} step={0.01} value={scale} onChange={(e) => setScale(Number(e.target.value))} className="flex-1" />
      </label>
      <div className="flex items-center gap-2">
        <Button size="sm" onClick={validate} disabled={null === img}>
          Valider le cadrage
        </Button>
        <Button size="sm" variant="ghost" onClick={onCancel}>
          Annuler
        </Button>
      </div>
    </div>
  );
}
