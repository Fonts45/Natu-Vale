import { createServer } from 'node:http';
import { createHash, createHmac, randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import { fileURLToPath } from 'node:url';

const env = process.env;
const demo = process.argv.includes('--demo');
const db = new DatabaseSync(demo ? ':memory:' : fileURLToPath(new URL('./natus.sqlite', import.meta.url)));
db.exec(`
  CREATE TABLE IF NOT EXISTS chats (
    id TEXT PRIMARY KEY, status TEXT DEFAULT 'pendente', owner TEXT,
    option INTEGER, activity INTEGER NOT NULL, reminder INTEGER DEFAULT 0, alert INTEGER DEFAULT 0,
    step INTEGER DEFAULT 0
  );
  CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY, chat TEXT NOT NULL, actor TEXT NOT NULL, body TEXT NOT NULL, at INTEGER NOT NULL
  );
  CREATE TABLE IF NOT EXISTS received (id TEXT PRIMARY KEY);
`);

const users = demo ? { equipe: 'natus-demo' } : JSON.parse(env.NATUS_USERS || '{}');
if (!Object.keys(users).length) throw new Error('Configure NATUS_USERS ou execute com --demo.');
const salt = randomBytes(32);
const passwords = new Map(Object.entries(users).map(([name, password]) => [name, scryptSync(password, salt, 32)]));
const sessions = new Map();
const attempts = new Map();
const page = readFileSync(new URL('./painel.html', import.meta.url));
const now = Date.now;
const get = id => db.prepare('SELECT * FROM chats WHERE id = ?').get(id);
const menu = 'Olá! Eu sou o Natus, assistente da Natu Vale. Como posso ajudar?\n\n1 — Uniformes\n2 — Produtos personalizados\n3 — Trabalhe Conosco\n4 — Acompanhar pedido\n5 — Falar com um atendente\n\nDigite o número da opção que você quer.';
const routes = {
  1: 'Vou encaminhar você para o WhatsApp responsável pelo atendimento de uniformes da Educação Adventista, no número +55 24 98141-9394.\nhttps://wa.me/5524981419394',
  2: 'Vou encaminhar você para o WhatsApp responsável pelos produtos personalizados, no número +55 24 98101-9000. Envie para esse número fotos de referência de como você quer que seu pedido fique.\nhttps://wa.me/5524981019000',
  3: 'Entre em contato com o Departamento Pessoal pelo WhatsApp +55 24 99395-6947. Informe a área ou função de interesse, suas habilidades e experiências. Envie seu currículo junto com a mensagem.\nhttps://wa.me/5524993956947',
  4: 'Para rastrear seu pedido, envie aqui o número da Nota Fiscal (NF) e o CNPJ que consta na nota.',
  5: 'Vou encaminhar sua conversa para a equipe. Se quiser, me conte brevemente o que você precisa.',
};

function record(id, actor, body) {
  db.prepare('INSERT INTO messages (chat, actor, body, at) VALUES (?, ?, ?, ?)').run(id, actor, body, now());
}

async function send(id, body, actor = 'Natus', buttons = false) {
  if (!demo) {
    if (!env.META_PHONE_ID || !env.META_TOKEN || !env.META_API_VERSION) throw new Error('WhatsApp ainda não configurado.');
    const payload = buttons
      ? { type: 'interactive', interactive: { type: 'button', body: { text: body }, action: {
          buttons: ['Sim', 'Não'].map(title => ({ type: 'reply', reply: { id: title, title } })),
        } } }
      : { type: 'text', text: { body } };
    const response = await fetch(`https://graph.facebook.com/${env.META_API_VERSION}/${env.META_PHONE_ID}/messages`, {
      method: 'POST', headers: { Authorization: `Bearer ${env.META_TOKEN}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ messaging_product: 'whatsapp', to: id, ...payload }), signal: AbortSignal.timeout(10000),
    });
    if (!response.ok) throw new Error(`WhatsApp recusou a mensagem (HTTP ${response.status}).`);
  }
  record(id, actor, body);
}

async function incoming(id, body, manual = false) {
  const previous = get(id);
  db.prepare('INSERT OR IGNORE INTO chats (id, activity) VALUES (?, ?)').run(id, now());
  db.prepare('UPDATE chats SET activity = ? WHERE id = ?').run(now(), id);
  record(id, manual ? 'WhatsApp Business' : 'Cliente', body);
  if (manual) {
    db.prepare("UPDATE chats SET status = 'humano', owner = COALESCE(owner, 'WhatsApp Business') WHERE id = ?").run(id);
    return;
  }

  const choice = body.trim().toLowerCase();
  if (previous?.reminder === 1 && previous.status !== 'resolvido') {
    if (choice === 'sim' || choice === 'não' || choice === 'nao') {
      if (choice === 'sim') {
        await send(id, 'Obrigado pelo retorno! Seu atendimento foi encerrado.');
        db.prepare("UPDATE chats SET status = 'resolvido', reminder = 2, alert = 0 WHERE id = ?").run(id);
      } else {
        db.prepare('UPDATE chats SET reminder = 2, alert = 1 WHERE id = ?').run(id);
      }
      return;
    }
  }
  if (previous?.status === 'humano' || (previous?.option === 5 && previous.status === 'pendente')) return;
  if (previous?.option === 4 && previous.status === 'pendente') {
    if (previous.step === 1) {
      db.prepare('UPDATE chats SET step = 2, alert = 1 WHERE id = ?').run(id);
      await send(id, 'Recebi sua mensagem. A equipe pode consultar o rastreamento com o número da NF e o CNPJ.');
    }
    return;
  }
  const option = /^[1-5]$/.test(choice) ? Number(choice) : null;
  db.prepare("UPDATE chats SET option = ?, status = ?, owner = NULL, reminder = 0, alert = ?, step = ? WHERE id = ?")
    .run(option, option && option <= 3 ? 'resolvido' : option === 5 ? 'pendente' : 'natus',
      option === 5 ? 1 : 0, option === 4 ? 1 : 0, id);
  if (option === 4) db.prepare("UPDATE chats SET status = 'pendente' WHERE id = ?").run(id);
  await send(id, routes[option] || menu);
}

// Serialize incoming messages, operator actions and reminders while updating a conversation.
let pending = Promise.resolve();
function serial(action) {
  const result = pending.then(action);
  pending = result.catch(() => {});
  return result;
}

async function reminders() {
  const due = db.prepare("SELECT id FROM chats WHERE option IN (4, 5) AND status IN ('pendente', 'humano') AND reminder = 0 AND activity <= ?")
    .all(now() - 20 * 60 * 1000);
  for (const { id } of due) {
    try {
      await send(id, 'Sua solicitação já foi resolvida?', 'Natus', true);
      db.prepare('UPDATE chats SET reminder = 1 WHERE id = ?').run(id);
    } catch (error) {
      console.error(error.message);
    }
  }
}

if (demo) {
  await incoming('Cliente de teste', '5');
  await incoming('Rastreamento de teste', '4');
}

function reply(res, code, data) {
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(data));
}

function fail(status, message) {
  throw Object.assign(new Error(message), { status });
}

async function readBody(req) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > 1024 * 1024) fail(413, 'Mensagem muito grande.');
    chunks.push(chunk);
  }
  return Buffer.concat(chunks);
}

const digest = value => createHash('sha256').update(value).digest();
const same = (left, right) => timingSafeEqual(digest(left), digest(right));

async function webhook(req, res, path) {
  if (demo || !env.META_APP_SECRET || !env.META_VERIFY_TOKEN || !env.META_PHONE_ID) fail(404, 'Webhook indisponível.');
  if (req.method === 'GET') {
    const q = path.searchParams;
    if (q.get('hub.mode') !== 'subscribe' || !same(q.get('hub.verify_token') || '', env.META_VERIFY_TOKEN)) fail(403, 'Verificação inválida.');
    res.end(q.get('hub.challenge') || '');
    return;
  }
  if (req.method !== 'POST') fail(405, 'Método inválido.');
  const raw = await readBody(req);
  const signature = 'sha256=' + createHmac('sha256', env.META_APP_SECRET).update(raw).digest('hex');
  if (!same(req.headers['x-hub-signature-256'] || '', signature)) fail(403, 'Assinatura inválida.');
  const event = JSON.parse(raw);
  await serial(async () => {
    for (const entry of event.entry || []) {
      for (const change of entry.changes || []) {
        if (String(change.value?.metadata?.phone_number_id) !== env.META_PHONE_ID) continue;
        const manual = change.field === 'smb_message_echoes';
        for (const message of (manual ? change.value.message_echoes : change.value.messages) || []) {
          const id = manual ? message.to : message.from;
          if (!message.id || !/^\d{7,20}$/.test(id)) continue;
          if (db.prepare('SELECT 1 FROM received WHERE id = ?').get(message.id)) continue;
          const body = message.interactive?.button_reply?.id || message.text?.body || `[${message.type || 'Anexo'}]`;
          await incoming(id, body, manual);
          db.prepare('INSERT INTO received VALUES (?)').run(message.id);
        }
      }
    }
  });
  reply(res, 200, { ok: true });
}

createServer(async (req, res) => {
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  try {
    const path = new URL(req.url, 'http://localhost');
    if (path.pathname === '/webhook') return await webhook(req, res, path);
    if (req.method === 'GET' && path.pathname === '/') {
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      return res.end(page);
    }

    const token = /(?:^|;\s*)natus=([a-f0-9]{64})(?:;|$)/.exec(req.headers.cookie || '')?.[1];
    const session = sessions.get(token);
    const method = req.method;
    if (method === 'POST' && !/^application\/json(?:;|$)/i.test(req.headers['content-type'] || '')) fail(415, 'Use JSON.');
    if (method === 'POST' && path.pathname === '/api/login') {
      const key = req.socket.remoteAddress;
      const attempt = attempts.get(key);
      const count = attempt?.until > now() ? attempt.count : 0;
      if (count >= 10) fail(429, 'Aguarde um minuto e tente novamente.');
      attempts.set(key, { count: count + 1, until: now() + 60000 });
      const { name, password } = JSON.parse(await readBody(req));
      if (typeof name !== 'string' || typeof password !== 'string' || password.length > 1024) fail(400, 'Dados inválidos.');
      const expected = passwords.get(name);
      const supplied = scryptSync(password, salt, 32);
      if (!timingSafeEqual(supplied, expected || salt) || !expected) fail(401, 'Usuário ou senha inválidos.');
      attempts.delete(key);
      const id = randomBytes(32).toString('hex');
      sessions.set(id, { name, expiry: now() + 8 * 60 * 60 * 1000 });
      res.setHeader('Set-Cookie', `natus=${id}; HttpOnly; SameSite=Strict; Path=/; Max-Age=28800${demo ? '' : '; Secure'}`);
      return reply(res, 200, { name });
    }
    if (!session || session.expiry <= now()) fail(401, 'Entre para acessar o painel.');
    if (method === 'GET' && path.pathname === '/api/chats') {
      const chatId = path.searchParams.get('id');
      return reply(res, 200, {
        name: session.name, demo,
        chats: db.prepare('SELECT id, status, owner, option, activity, alert FROM chats ORDER BY alert DESC, activity DESC').all(),
        messages: chatId ? db.prepare('SELECT actor, body, at FROM messages WHERE chat = ? ORDER BY id').all(chatId) : [],
      });
    }
    if (method !== 'POST' || path.pathname !== '/api/action') fail(404, 'Página indisponível.');
    const { id, action, body } = JSON.parse(await readBody(req));
    if (action === 'logout') {
      sessions.delete(token);
      return reply(res, 200, { ok: true });
    }

    await serial(async () => {
      const chat = get(id);
      if (!chat) fail(404, 'Conversa indisponível.');
      if (action === 'demo' && demo) {
        if (typeof body !== 'string' || !body.trim() || body.length > 4096) fail(400, 'Digite uma mensagem.');
        await incoming(id, body);
        return;
      }
      if (action === 'demo-whatsapp' && demo) {
        if (typeof body !== 'string' || !body.trim() || body.length > 4096) fail(400, 'Digite uma mensagem.');
        await incoming(id, body, true);
        return;
      }
      if (action === 'demo-reminder' && demo) {
        db.prepare('UPDATE chats SET activity = ? WHERE id = ?').run(now() - 20 * 60 * 1000, id);
        await reminders();
        return;
      }
      if (chat.status === 'humano' && chat.owner !== session.name && chat.owner !== 'WhatsApp Business') fail(409, 'Outro atendente assumiu esta conversa.');
      if (action === 'claim') {
        if (chat.status === 'resolvido') fail(409, 'Esta conversa já foi encerrada.');
        db.prepare("UPDATE chats SET status = 'humano', owner = ?, alert = 0 WHERE id = ?").run(session.name, id);
      } else if (action === 'release' && chat.status === 'humano') {
        db.prepare("UPDATE chats SET status = CASE WHEN option IN (4,5) THEN 'pendente' ELSE 'natus' END, owner = NULL WHERE id = ?").run(id);
      } else if (action === 'resolve') {
        db.prepare("UPDATE chats SET status = 'resolvido', alert = 0 WHERE id = ?").run(id);
      } else if (action === 'send' && chat.status === 'humano' && chat.owner === session.name) {
        if (typeof body !== 'string' || !body.trim() || body.length > 4096) fail(400, 'Digite uma mensagem de até 4.096 caracteres.');
        await send(id, body.trim(), session.name);
        db.prepare('UPDATE chats SET activity = ?, alert = 0 WHERE id = ?').run(now(), id);
      } else {
        fail(409, 'A ação não está disponível para esta conversa.');
      }
    });
    reply(res, 200, { ok: true });
  } catch (error) {
    if (!error.status) console.error(error.message);
    reply(res, error.status || (error instanceof SyntaxError ? 400 : 502),
      { error: error.status ? error.message : error instanceof SyntaxError ? 'Dados inválidos.' : 'Falha na operação. Consulte o servidor.' });
  }
}).listen(Number(env.NATUS_PORT || 8787), '127.0.0.1', () => console.log(`Natus em http://127.0.0.1:${env.NATUS_PORT || 8787}`));

setInterval(() => {
  for (const [key, session] of sessions) if (session.expiry <= now()) sessions.delete(key);
  for (const [key, attempt] of attempts) if (attempt.until <= now()) attempts.delete(key);
  serial(reminders).catch(error => console.error(error.message));
}, 15000).unref();
