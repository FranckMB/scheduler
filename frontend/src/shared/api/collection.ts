import { api } from "./client";

/**
 * Fetch an API Platform collection. Comes back as JSON-LD (`{ member: [...] }`);
 * also tolerates a plain array. Tenant (club) + season are resolved server-side
 * from the JWT — no header is sent.
 */
export async function collection<T>(path: string, searchParams?: Record<string, string>, headers?: Record<string, string>): Promise<T[]> {
  const raw = await api.get(path, { ...(searchParams ? { searchParams } : {}), ...(headers ? { headers } : {}) }).json<unknown>();
  if (Array.isArray(raw)) {
    return raw as T[];
  }
  if (null !== raw && typeof raw === "object" && Array.isArray((raw as { member?: unknown }).member)) {
    return (raw as { member: T[] }).member;
  }
  return [];
}

const PAGE_SIZE = 30;

/**
 * Page through an unfiltered collection so every club row is returned. Dedupe by
 * id, stop on a short page or a page that adds nothing new (guards a no-op `page`).
 */
export async function collectionAll<T extends { id: string }>(path: string, searchParams?: Record<string, string>, headers?: Record<string, string>): Promise<T[]> {
  const seen = new Set<string>();
  const all: T[] = [];
  for (let page = 1; page <= 50; page += 1) {
    const batch = await collection<T>(path, { ...searchParams, page: String(page) }, headers);
    const fresh = batch.filter((item) => !seen.has(item.id));
    for (const item of fresh) {
      seen.add(item.id);
      all.push(item);
    }
    if (batch.length < PAGE_SIZE || 0 === fresh.length) {
      break;
    }
  }
  return all;
}
