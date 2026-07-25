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
 * pdf-worker n'installe aucune dépendance npm (Dockerfile : worker.js + symlink puppeteer),
 * donc le client Redis est un RESP minimal sur socket. Testé contre un faux serveur RESP
 * local qui répond un +OK par commande reçue — ni node-redis, ni puppeteer, ni conteneur.
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
        const wire = chunk.toString();
        received.push(wire);
        // Un +OK par commande RESP reçue (compte les tableaux `*n`).
        const commands = (wire.match(/\*\d+\r\n/g) || []).length;
        socket.write('+OK\r\n'.repeat(commands || 1));
      });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    port = server.address().port;
  });

  afterEach(async () => {
    await new Promise((resolve) => server.close(resolve));
  });

  it('écrit SET <clé> <timestamp> EX 120 au format RESP et lit le +OK', async () => {
    const ok = await writeHeartbeat({ host: '127.0.0.1', port }, 1234567890);
    expect(ok).toBe(true);

    const wire = received.join('');
    expect(wire).toBe(encodeRespCommand(['SET', HEARTBEAT_KEY, '1234567890', 'EX', String(HEARTBEAT_TTL_S)]));
    expect(wire).toContain('admin_monitoring.pdf_worker.heartbeat');
  });

  it('pipeline AUTH + SELECT + SET quand REDIS_URL porte password et db (parité probe PHP)', async () => {
    const ok = await writeHeartbeat({ host: '127.0.0.1', port, password: 'secret', db: 3 }, 1000);
    expect(ok).toBe(true);

    const wire = received.join('');
    expect(wire).toContain(encodeRespCommand(['AUTH', 'secret']));
    expect(wire).toContain(encodeRespCommand(['SELECT', '3']));
    expect(wire).toContain(encodeRespCommand(['SET', HEARTBEAT_KEY, '1000', 'EX', String(HEARTBEAT_TTL_S)]));
  });

  it('échoue proprement (false) si Redis renvoie une erreur (-NOAUTH)', async () => {
    await new Promise((resolve) => server.close(resolve));
    server = net.createServer((socket) => {
      socket.on('data', () => socket.write('-NOAUTH Authentication required.\r\n'));
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const errPort = server.address().port;

    const ok = await writeHeartbeat({ host: '127.0.0.1', port: errPort, password: 'wrong' }, 1000);
    expect(ok).toBe(false);
  });

  it('ne jette JAMAIS quand Redis est injoignable (le worker PDF doit survivre)', async () => {
    await new Promise((resolve) => server.close(resolve));
    server = net.createServer(); // recréé pour l'afterEach

    const ok = await writeHeartbeat({ host: '127.0.0.1', port }, Date.now());
    expect(ok).toBe(false); // résolu, pas rejeté — aucun throw
  });

  it('parse host/port/username/password/db et retombe sur redis:6379 db0 si invalide', () => {
    expect(parseRedisUrl('redis://cache:6390/2')).toEqual({ host: 'cache', port: 6390, username: '', password: '', db: 2 });
    expect(parseRedisUrl('redis://:s3cret@host:6379/0')).toEqual({ host: 'host', port: 6379, username: '', password: 's3cret', db: 0 });
    expect(parseRedisUrl('redis://user:pw@host:6379/1')).toEqual({ host: 'host', port: 6379, username: 'user', password: 'pw', db: 1 });
    expect(parseRedisUrl('not a url')).toEqual({ host: 'redis', port: 6379, username: '', password: '', db: 0 });
  });
});
