import { Eye, EyeOff } from "lucide-react";
import type * as React from "react";
import { useState } from "react";

import { cn } from "@/shared/lib/utils";

import { Input } from "./input";

/** Password field with a show/hide (eye) toggle. Forwards every native input
 *  prop to the underlying Input; the toggle only swaps the input type. */
export function PasswordInput({ className, ...props }: React.InputHTMLAttributes<HTMLInputElement> & { ref?: React.Ref<HTMLInputElement> }) {
  const [visible, setVisible] = useState(false);

  return (
    <div className="relative">
      <Input {...props} type={visible ? "text" : "password"} className={cn("pr-10", className)} />
      <button
        type="button"
        tabIndex={-1}
        onClick={() => setVisible((v) => !v)}
        aria-label={visible ? "Masquer le mot de passe" : "Afficher le mot de passe"}
        className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground transition-colors hover:text-foreground"
      >
        {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
      </button>
    </div>
  );
}
