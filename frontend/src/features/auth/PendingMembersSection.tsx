import { Check, X } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Card, CardContent } from "@/shared/components/ui/card";
import { Spinner } from "@/shared/components/ui/spinner";

import { useApproveMember, usePendingMembers, useRejectMember } from "./queries";

/**
 * Member-approval list, embeddable (e.g. as a section of the Club hub).
 * Renders no page chrome (title/description live in the host).
 */
export function PendingMembersSection() {
  const { data, isLoading, isError } = usePendingMembers(true);
  const approve = useApproveMember();
  const reject = useRejectMember();

  if (isLoading) {
    return (
      <div className="flex justify-center py-6">
        <Spinner className="size-5" />
      </div>
    );
  }

  if (isError) {
    return <p role="alert" className="py-4 text-center text-sm text-destructive">Impossible de charger les demandes. Réessayez plus tard.</p>;
  }

  const members = data?.members ?? [];

  if (members.length === 0) {
    return <EmptyHint className="py-4 text-center">Aucune demande en attente.</EmptyHint>;
  }

  return (
    <ul className="flex flex-col gap-2">
      {members.map((member) => {
        const busy = approve.isPending || reject.isPending;
        return (
          <li key={member.id}>
            <Card>
              <CardContent className="flex items-center justify-between gap-4 py-4">
                <div>
                  <p className="font-medium">
                    {member.firstName} {member.lastName}
                  </p>
                  <p className="text-sm text-muted-foreground">{member.email}</p>
                </div>
                <div className="flex gap-2">
                  <Button size="sm" disabled={busy} onClick={() => approve.mutate(member.id)}>
                    <Check /> Approuver
                  </Button>
                  <Button size="sm" variant="outline" disabled={busy} onClick={() => reject.mutate(member.id)}>
                    <X /> Refuser
                  </Button>
                </div>
              </CardContent>
            </Card>
          </li>
        );
      })}
    </ul>
  );
}
