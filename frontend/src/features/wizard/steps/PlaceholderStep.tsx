import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";

export function PlaceholderStep({ title }: { title: string }) {
  return (
    <Card className="border-dashed">
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>Cet écran arrive dans une prochaine sous-tranche.</CardDescription>
      </CardHeader>
      <CardContent className="text-sm text-muted-foreground">À venir.</CardContent>
    </Card>
  );
}
