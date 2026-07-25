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

/**
 * redis://[user][:pass]@host:port(/db) → {host, port, username, password, db}.
 * Le probe PHP applique AUTH + SELECT depuis la MÊME URL : il faut donc les propager,
 * sinon writer (Node) et reader (PHP) tapent des contextes Redis différents dès qu'il y a
 * un mot de passe ou une DB ≠ 0 (prod managée) → pdf-worker faussement « Inconnu ».
 */
export function parseRedisUrl(url) {
  try {
    const u = new URL(url);
    const db = u.pathname.replace(/^\//, '');
    return {
      host: u.hostname || 'redis',
      port: Number(u.port) || 6379,
      username: u.username ? decodeURIComponent(u.username) : '',
      password: u.password ? decodeURIComponent(u.password) : '',
      db: /^\d+$/.test(db) ? Number(db) : 0,
    };
  } catch {
    return { host: 'redis', port: 6379, username: '', password: '', db: 0 };
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
export function writeHeartbeat({ host, port, username = '', password = '', db = 0 }, nowSeconds = Math.floor(Date.now() / 1000)) {
  return new Promise((resolve) => {
    const socket = net.createConnection({ host, port });
    const done = (ok) => {
      socket.destroy();
      resolve(ok);
    };
    // Pipeline AUTH? + SELECT? + SET, dans le MÊME ordre que le probe PHP. Succès = autant de
    // +OK que de commandes envoyées, aucune erreur (-). Réponses RESP dans l'ordre d'envoi.
    const commands = [];
    if (password) {
      commands.push(username ? ['AUTH', username, password] : ['AUTH', password]);
    }
    if (db > 0) {
      commands.push(['SELECT', String(db)]);
    }
    commands.push(['SET', HEARTBEAT_KEY, String(nowSeconds), 'EX', String(HEARTBEAT_TTL_S)]);

    let buffer = '';
    socket.setTimeout(2000, () => done(false));
    socket.on('error', () => done(false));
    socket.on('connect', () => {
      socket.write(commands.map(encodeRespCommand).join(''));
    });
    socket.on('data', (chunk) => {
      buffer += chunk.toString();
      if (buffer.includes('-')) {
        done(false); // -ERR / -NOAUTH quelque part → échec
        return;
      }
      // Toutes les commandes ont répondu +OK (une ligne \r\n par réponse).
      if ((buffer.match(/\+OK\r\n/g) || []).length >= commands.length) {
        done(true);
      }
    });
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
