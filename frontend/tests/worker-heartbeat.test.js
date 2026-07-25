import net from 'net';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  HEARTBEAT_KEY,
  HEARTBEAT_TTL_S,
  encodeRespCommand,
  parseRedisUrl,
  writeHeartbeat,
} from '../worker-heartbeat.js';

/**
 * Heartbeat du pdf-worker (console superadmin) — module BUILTINS-ONLY : le conteneur
 * pdf-worker n'installe aucune dépendance npm (Dockerfile : worker.js + symlink
 * puppeteer), donc le client Redis est un RESP minimal sur socket. Testé contre un
 * faux serveur RESP local — ni node-redis, ni puppeteer, ni conteneur.
 */
describe('worker-heartbeat', () => {
  /** @type {net.Server} */
  let server;
  /** @type {string[]} */
  let received;
  let port;

  beforeEach(async () => {
    received = [];
    server = net.createServer((socket) => {
      socket.on('data', (chunk) => {
        received.push(chunk.toString());
        socket.write('+OK\r\n');
      });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    port = server.address().port;
  });

  afterEach(async () => {
    await new Promise((resolve) => server.close(resolve));
  });

  it('écrit SET <clé> <timestamp> EX 60 au format RESP et lit le +OK', async () => {
    const ok = await writeHeartbeat({ host: '127.0.0.1', port }, 1234567890);
    expect(ok).toBe(true);

    const wire = received.join('');
    expect(wire).toBe(encodeRespCommand(['SET', HEARTBEAT_KEY, '1234567890', 'EX', String(HEARTBEAT_TTL_S)]));
    expect(wire).toContain('admin_monitoring.pdf_worker.heartbeat');
  });

  it('ne jette JAMAIS quand Redis est injoignable (le worker PDF doit survivre)', async () => {
    await new Promise((resolve) => server.close(resolve));
    server = net.createServer(); // recréé pour l'afterEach

    const ok = await writeHeartbeat({ host: '127.0.0.1', port }, Date.now());
    expect(ok).toBe(false); // résolu, pas rejeté — aucun throw
  });

  it('parse REDIS_URL et retombe sur redis:6379 si invalide', () => {
    expect(parseRedisUrl('redis://cache-host:6390/0')).toEqual({ host: 'cache-host', port: 6390 });
    expect(parseRedisUrl('redis://redis:6379')).toEqual({ host: 'redis', port: 6379 });
    expect(parseRedisUrl('not a url')).toEqual({ host: 'redis', port: 6379 });
  });
});
