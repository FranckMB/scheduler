import net from 'net';

/**
 * Heartbeat Redis du pdf-worker — builtins UNIQUEMENT (revue superadmin C3).
 *
 * Le Dockerfile pdf-worker ne fait PAS de `npm ci` : il copie worker.js et symlink le
 * puppeteer de l'image de base — toute dépendance npm (node-redis compris) serait
 * introuvable dans un conteneur frais et crasherait le worker au boot (le volume
 * node_modules local le masquait en dev). D'où un client RESP minimal sur net.Socket :
 * une commande SET key value EX ttl, une connexion éphémère par battement (1 toutes
 * les 30 s — négligeable, et zéro état de reconnexion à gérer).
 *
 * Ne jette JAMAIS : Redis down, DNS cassé, timeout → silencieux, le worker PDF vit.
 */
export const HEARTBEAT_KEY = 'admin_monitoring.pdf_worker.heartbeat';
export const HEARTBEAT_INTERVAL_MS = 30000;
// TTL > maxAge (60 s côté probe) : la clé survit assez pour que l'état « down » soit
// atteignable (sinon elle expire pile à maxAge → on saute up→unknown, cf. revue).
export const HEARTBEAT_TTL_S = 120;

/** redis://host:port(/db) → {host, port}. Repli sur le service compose. */
export function parseRedisUrl(url) {
  try {
    const u = new URL(url);
    return { host: u.hostname || 'redis', port: Number(u.port) || 6379 };
  } catch {
    return { host: 'redis', port: 6379 };
  }
}

/** Encode une commande Redis au format RESP (tableau de bulk strings). */
export function encodeRespCommand(args) {
  let out = `*${args.length}\r\n`;
  for (const arg of args) {
    const s = String(arg);
    out += `$${Buffer.byteLength(s)}\r\n${s}\r\n`;
  }
  return out;
}

/**
 * Écrit UN battement (SET … EX ttl) sur une connexion éphémère. La valeur est un timestamp
 * UNIX en SECONDES (le probe backend fait `time() - valeur` en secondes ; envoyer des ms
 * fausserait l'âge). Résout toujours (true = ack Redis, false = échec) — jamais de rejet.
 */
export function writeHeartbeat({ host, port }, nowSeconds = Math.floor(Date.now() / 1000)) {
  return new Promise((resolve) => {
    const socket = net.createConnection({ host, port });
    const done = (ok) => {
      socket.destroy();
      resolve(ok);
    };
    socket.setTimeout(2000, () => done(false));
    socket.on('error', () => done(false));
    socket.on('connect', () => {
      socket.write(encodeRespCommand(['SET', HEARTBEAT_KEY, String(nowSeconds), 'EX', String(HEARTBEAT_TTL_S)]));
    });
    socket.on('data', (chunk) => done(chunk.toString().startsWith('+OK')));
  });
}

/** Battement immédiat + toutes les 30 s. Retourne le timer (unref'd — ne retient pas le process). */
export function startHeartbeat(redisUrl = process.env.REDIS_URL || 'redis://redis:6379') {
  const target = parseRedisUrl(redisUrl);
  writeHeartbeat(target);
  const timer = setInterval(() => writeHeartbeat(target), HEARTBEAT_INTERVAL_MS);
  timer.unref?.();
  return timer;
}
