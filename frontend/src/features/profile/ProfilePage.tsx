import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { useMe } from "@/features/auth/queries";

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between border-b border-border py-3 last:border-0">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className="text-sm font-medium">{value}</span>
    </div>
  );
}

export function ProfilePage() {
  const { data, isLoading } = useMe();

  if (isLoading || !data) {
    return <FullPageSpinner />;
  }

  return (
    <div className="mx-auto max-w-lg">
      <h1 className="mb-4 text-xl font-semibold">Profil</h1>
      <Card>
        <CardHeader>
          <CardTitle>{data.firstName} {data.lastName}</CardTitle>
        </CardHeader>
        <CardContent>
          <Row label="Email" value={data.email} />
          <Row label="Club" value={data.club?.name ?? "—"} />
          <Row label="Rôle" value={data.role ?? "—"} />
        </CardContent>
      </Card>
    </div>
  );
}
