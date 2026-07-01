import { Check, X } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent } from "@/shared/components/ui/card";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { useApproveMember, usePendingMembers, useRejectMember } from "./queries";

export function PendingMembersPage() {
  const { data, isLoading } = usePendingMembers(true);
  const approve = useApproveMember();
  const reject = useRejectMember();

  if (isLoading) {
    return <FullPageSpinner />;
  }

  const members = data?.members ?? [];

  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-1 text-xl font-semibold">Demandes d'adhésion</h1>
      <p className="mb-4 text-sm text-muted-foreground">Approuvez ou refusez les personnes qui souhaitent rejoindre votre club.</p>

      {members.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">Aucune demande en attente.</CardContent>
        </Card>
      ) : (
        <ul className="flex flex-col gap-2">
          {members.map((member) => {
            const busy = approve.isPending || reject.isPending;
            return (
              <li key={member.id}>
                <Card>
                  <CardContent className="flex items-center justify-between gap-4 py-4">
                    <div>
                      <p className="font-medium">{member.firstName} {member.lastName}</p>
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
      )}
    </div>
  );
}
