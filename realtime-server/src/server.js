/**
 * E-Recepsionis realtime: Express + Socket.io
 * Events: guest_request, admin_notification, accept_request, send_message, receive_message,
 *         end_session, reject_request, typing_start/stop, guest_rejoin,
 *         admin_session_restored, admin_fetch_messages / message_history
 */
import 'dotenv/config';
import express from 'express';
import http from 'http';
import { Server } from 'socket.io';
import mysql from 'mysql2/promise';
import cors from 'cors';
import jwt from 'jsonwebtoken';
import { randomUUID } from 'crypto';

const JWT_SECRET = process.env.JWT_SECRET || 'dev-change-me';
const ADMIN_ROOM = 'admins';
const ADMIN_USER_ROOM_PREFIX = 'admin:user:';
const ADMIN_CATEGORY_ROOM_PREFIX = 'admin:category:';

const DB_PORT = (() => {
  const p = Number(process.env.DB_PORT);
  return Number.isFinite(p) && p > 0 ? p : 3306;
})();

const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  port: DB_PORT,
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'recepsionis_db',
  waitForConnections: true,
  connectionLimit: 10,
});

const corsOrigins = (() => {
  const raw = process.env.CORS_ORIGIN;
  if (raw === undefined || raw === null || String(raw).trim() === '' || String(raw).trim() === '*') {
    return true;
  }
  return String(raw)
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
})();

const app = express();
app.use(cors({ origin: corsOrigins, credentials: true }));
app.use(express.json());

app.get('/health', (req, res) => {
  res.json({ ok: true, service: 'recepsionis-realtime' });
});

const server = http.createServer(app);
const io = new Server(server, {
  cors: {
    origin: corsOrigins === true ? '*' : corsOrigins,
    methods: ['GET', 'POST'],
    credentials: true,
  },
});

/** session_id -> { staffCallId, guestSocketId, categoryId, assignedUserId, answeredBy } */
const sessionMeta = new Map();

function adminUserRoom(adminId) {
  return `${ADMIN_USER_ROOM_PREFIX}${Number(adminId)}`;
}

function adminCategoryRoom(categoryId) {
  return `${ADMIN_CATEGORY_ROOM_PREFIX}${Number(categoryId)}`;
}

function adminChatChangedRoom(meta) {
  if (Number(meta?.assignedUserId || 0) > 0) {
    return adminUserRoom(Number(meta.assignedUserId));
  }
  return adminCategoryRoom(Number(meta?.categoryId || 0));
}

io.use((socket, next) => {
  const token = socket.handshake.auth?.token;
  if (token) {
    try {
      const p = jwt.verify(token, JWT_SECRET);
      socket.data.role = 'admin';
      socket.data.adminId = Number(p.sub || p.userId || p.id);
      socket.data.adminName = String(p.name || 'Admin');
      socket.data.userRole = String(p.role || '');
      if (!socket.data.adminId) return next(new Error('Invalid token payload'));
    } catch {
      return next(new Error('Invalid token'));
    }
  } else {
    socket.data.role = 'guest';
  }
  next();
});

async function tableHasColumn(conn, table, column) {
  const [rows] = await conn.query(
    `SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1`,
    [table, column]
  );
  return rows.length > 0;
}

async function tableExists(conn, table) {
  const [rows] = await conn.query(
    `SELECT 1 FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1`,
    [table]
  );
  return rows.length > 0;
}

async function getActiveAdminsForCategory(conn, categoryId) {
  if (!categoryId || !(await tableExists(conn, 'admin_category_routing'))) {
    return [];
  }
  const [rows] = await conn.query(
    `SELECT u.id, u.username, u.nama_lengkap, u.email, u.role
     FROM admin_category_routing acr
     INNER JOIN users u ON u.id = acr.user_id
     WHERE acr.category_id = ? AND u.status_aktif = 1 AND COALESCE(u.status_tugas, 1) = 1
     ORDER BY COALESCE(NULLIF(u.nama_lengkap, ''), u.username) ASC, u.id ASC`,
    [categoryId]
  );
  return rows.map((row) => ({
    id: Number(row.id),
    username: String(row.username || ''),
    nama_lengkap: String(row.nama_lengkap || ''),
    email: String(row.email || ''),
    role: String(row.role || ''),
  }));
}

async function getActiveUserById(conn, userId) {
  if (!userId) {
    return null;
  }
  const [rows] = await conn.query(
    `SELECT id, username, nama_lengkap, email, role
     FROM users
     WHERE id = ? AND status_aktif = 1 AND COALESCE(status_tugas, 1) = 1
     LIMIT 1`,
    [userId]
  );
  if (!rows.length) {
    return null;
  }
  const row = rows[0];
  return {
    id: Number(row.id),
    username: String(row.username || ''),
    nama_lengkap: String(row.nama_lengkap || ''),
    email: String(row.email || ''),
    role: String(row.role || ''),
  };
}

async function categoryHasAnyActiveRouting(conn, categoryId) {
  const rows = await getActiveAdminsForCategory(conn, categoryId);
  return rows.length > 0;
}

async function adminCanReceiveCategory(conn, adminId, categoryId) {
  if (!adminId) {
    return false;
  }
  if (!categoryId) {
    return true;
  }
  const admins = await getActiveAdminsForCategory(conn, categoryId);
  if (admins.length === 0) {
    return true;
  }
  return admins.some((row) => Number(row.id) === Number(adminId));
}

async function getEffectiveTargetsForCall(conn, assignedUserId, categoryId) {
  const assignee = await getActiveUserById(conn, assignedUserId);
  if (assignee) {
    return [assignee];
  }
  return getActiveAdminsForCategory(conn, categoryId);
}

async function adminCanReceiveStaffCall(conn, adminId, categoryId, assignedUserId, userRole = '') {
  if (!adminId) {
    return false;
  }
  if (String(userRole || '') === 'admin') {
    return true;
  }
  const targets = await getEffectiveTargetsForCall(conn, assignedUserId, categoryId);
  if (!targets.length) {
    return adminCanReceiveCategory(conn, adminId, categoryId);
  }
  return targets.some((row) => Number(row.id) === Number(adminId));
}

async function assignStaffCall(conn, staffCallId, assignedUserId, actorUserId, categoryId, notes, metadata = {}) {
  if (!staffCallId || !assignedUserId || !(await tableHasColumn(conn, 'staff_calls', 'assigned_user_id'))) {
    return false;
  }
  const assignee = await getActiveUserById(conn, assignedUserId);
  if (!assignee) {
    return false;
  }

  const hasAssignedBy = await tableHasColumn(conn, 'staff_calls', 'assigned_by');
  const hasAssignedAt = await tableHasColumn(conn, 'staff_calls', 'assigned_at');
  const [rows] = await conn.query(
    `SELECT assigned_user_id, category_id
     FROM staff_calls
     WHERE id = ?
     LIMIT 1`,
    [staffCallId]
  );
  if (!rows.length) {
    return false;
  }

  const previousAssignedUserId = Number(rows[0].assigned_user_id) || 0;
  if (previousAssignedUserId === Number(assignedUserId)) {
    return true;
  }

  let sql = 'UPDATE staff_calls SET assigned_user_id = ?';
  const params = [assignedUserId];
  if (hasAssignedBy) {
    if (actorUserId) {
      sql += ', assigned_by = ?';
      params.push(actorUserId);
    } else {
      sql += ', assigned_by = NULL';
    }
  }
  if (hasAssignedAt) {
    sql += ', assigned_at = NOW()';
  }
  sql += ' WHERE id = ?';
  params.push(staffCallId);

  const [result] = await conn.query(sql, params);
  if (!result?.affectedRows) {
    return false;
  }

  const effectiveCategoryId = Number(categoryId || rows[0].category_id) || 0;
  await logStaffCallEvent(
    conn,
    staffCallId,
    previousAssignedUserId > 0 ? 'reassigned' : 'assigned',
    actorUserId || null,
    assignedUserId,
    effectiveCategoryId || null,
    notes || (previousAssignedUserId > 0 ? 'PIC pengaduan dipindahkan ke admin lain.' : 'PIC pengaduan ditetapkan.'),
    {
      previous_assigned_user_id: previousAssignedUserId || null,
      assigned_user_id: assignedUserId,
      ...metadata,
    }
  );
  return true;
}

async function loadAdminCategoryIds(conn, adminId) {
  if (!(await tableExists(conn, 'admin_category_routing'))) {
    return [];
  }
  const [rows] = await conn.query(
    `SELECT category_id
     FROM admin_category_routing
     WHERE user_id = ?
     ORDER BY category_id ASC`,
    [adminId]
  );
  return rows.map((row) => Number(row.category_id)).filter((value) => value > 0);
}

async function joinAdminRoutingRooms(socket) {
  const conn = await pool.getConnection();
  try {
    const categoryIds = await loadAdminCategoryIds(conn, socket.data.adminId);
    const previousIds = Array.isArray(socket.data.categoryIds) ? socket.data.categoryIds : [];
    for (const previousId of previousIds) {
      socket.leave(adminCategoryRoom(previousId));
    }
    socket.data.categoryIds = categoryIds;
    for (const categoryId of categoryIds) {
      socket.join(adminCategoryRoom(categoryId));
    }
    return categoryIds;
  } finally {
    conn.release();
  }
}

async function logStaffCallEvent(conn, staffCallId, eventType, actorUserId, targetUserId, categoryId, notes, metadata) {
  if (!staffCallId || !eventType || !(await tableExists(conn, 'staff_call_logs'))) {
    return;
  }
  const metadataJson =
    metadata && Object.keys(metadata).length > 0
      ? JSON.stringify(metadata)
      : null;
  await conn.query(
    `INSERT INTO staff_call_logs
      (staff_call_id, event_type, actor_user_id, target_user_id, category_id, notes, metadata_json)
     VALUES (?, ?, ?, ?, ?, ?, ?)`,
    [
      staffCallId,
      String(eventType).slice(0, 50),
      actorUserId || null,
      targetUserId || null,
      categoryId || null,
      notes ? String(notes).slice(0, 4000) : null,
      metadataJson,
    ]
  );
}

async function insertStaffCallLive(conn, guest_name, visitor_phone, message, category_id, live_session_id) {
  const hasLive = await tableHasColumn(conn, 'staff_calls', 'live_session_id');
  if (hasLive) {
    const [r] = await conn.query(
      `INSERT INTO staff_calls (visitor_name, visitor_phone, host_id, call_type, message, status, category_id, live_session_id, live_status)
       VALUES (?, ?, NULL, 'live_chat', ?, 'pending', ?, ?, 'waiting')`,
      [guest_name, visitor_phone, message, category_id, live_session_id]
    );
    return r.insertId;
  }
  const [r] = await conn.query(
    `INSERT INTO staff_calls (visitor_name, visitor_phone, host_id, call_type, message, status, category_id)
     VALUES (?, ?, NULL, 'live_chat', ?, 'pending', ?)`,
    [guest_name, visitor_phone, message, category_id]
  );
  return r.insertId;
}

async function syncVisitorAndBadge(conn, guest_name, visitor_phone, message, staffCallId) {
  try {
    const [rows] = await conn.query(
      'SELECT COUNT(*) AS c FROM visitors WHERE DATE(created_at) = CURDATE()'
    );
    const count = Number(rows[0].c) + 1;
    const [d] = await conn.query('SELECT DATE_FORMAT(CURDATE(), "%Y%m%d") AS d');
    const dateStr = d[0].d;
    const badge = `TMU${dateStr}${String(count).padStart(4, '0')}`;
    const [vr] = await conn.query(
      `INSERT INTO visitors (nama, no_telp, perusahaan, tujuan, status, checkin_time, badge_number)
       VALUES (?, ?, '', ?, 'checked-in', NOW(), ?)`,
      [guest_name, visitor_phone, message, badge]
    );
    await conn.query('UPDATE staff_calls SET visitor_id = ? WHERE id = ?', [vr.insertId, staffCallId]);
  } catch (e) {
    console.error('syncVisitorAndBadge', e.message);
  }
}

async function insertNotification(conn, guest_name, visitor_phone, categoryName, message) {
  try {
    const title = `Live chat: ${guest_name}`.slice(0, 200);
    const msg = `${guest_name} (${visitor_phone})\nKategori: ${categoryName}\n${message}`.slice(0, 5000);
    await conn.query(
      `INSERT INTO notifications (host_id, type, title, message) VALUES (NULL, 'system', ?, ?)`,
      [title, msg]
    );
  } catch (e) {
    console.error('insertNotification', e.message);
  }
}

async function persistMessage(conn, session_id, staffCallId, sender, adminUserId, text) {
  try {
    const hasTable = await tableExists(conn, 'live_chat_messages');
    if (!hasTable) return;
    await conn.query(
      `INSERT INTO live_chat_messages (live_session_id, staff_call_id, sender, admin_user_id, body) VALUES (?, ?, ?, ?, ?)`,
      [session_id, staffCallId, sender, adminUserId, text.slice(0, 8000)]
    );
  } catch (e) {
    console.error('persistMessage', e.message);
  }
}

async function hasAdminStateTable(conn) {
  return tableExists(conn, 'live_chat_admin_state');
}

/**
 * Pulihkan antrian live yang masih pending (admin belum Terima).
 * Inti: live_session_id NOT NULL + pending; call_type longgar (live_chat atau NULL legacy).
 */
async function syncPendingLiveForAdmin(socket) {
  const conn = await pool.getConnection();
  let emitted = 0;
  try {
    const hasLiveStatus = await tableHasColumn(conn, 'staff_calls', 'live_status');
    const hasRouting = await tableExists(conn, 'admin_category_routing');
    const hasAssignedUser = await tableHasColumn(conn, 'staff_calls', 'assigned_user_id');
    let sql = `
      SELECT sc.id AS staff_call_id, sc.live_session_id, sc.visitor_name AS guest_name,
             sc.visitor_phone, sc.message, sc.category_id,
             ${hasAssignedUser ? 'sc.assigned_user_id,' : 'NULL AS assigned_user_id,'}
             cc.nama_kategori AS category
      FROM staff_calls sc
      LEFT JOIN complaint_categories cc ON cc.id = sc.category_id
      WHERE sc.status = 'pending'
        AND sc.live_session_id IS NOT NULL
        AND sc.live_session_id <> ''
        AND (sc.call_type = 'live_chat' OR sc.call_type IS NULL)
    `;
    const params = [];
    if (hasAssignedUser && hasRouting) {
      sql += `
        AND (
          (
            sc.assigned_user_id IS NOT NULL
            AND EXISTS (
              SELECT 1
              FROM users ua
              WHERE ua.id = sc.assigned_user_id
                AND ua.status_aktif = 1 AND COALESCE(ua.status_tugas, 1) = 1
            )
            AND sc.assigned_user_id = ?
          )
          OR (
            (
              sc.assigned_user_id IS NULL
              OR NOT EXISTS (
                SELECT 1
                FROM users ua2
                WHERE ua2.id = sc.assigned_user_id
                  AND ua2.status_aktif = 1 AND COALESCE(ua2.status_tugas, 1) = 1
              )
            )
            AND (
              sc.category_id IS NULL
              OR EXISTS (
                SELECT 1
                FROM admin_category_routing acr
                INNER JOIN users u1 ON u1.id = acr.user_id AND u1.status_aktif = 1 AND COALESCE(u1.status_tugas, 1) = 1
                WHERE acr.category_id = sc.category_id
                  AND acr.user_id = ?
              )
              OR NOT EXISTS (
                SELECT 1
                FROM admin_category_routing acr2
                INNER JOIN users u2 ON u2.id = acr2.user_id AND u2.status_aktif = 1 AND COALESCE(u2.status_tugas, 1) = 1
                WHERE acr2.category_id = sc.category_id
              )
            )
          )
        )
      `;
      params.push(socket.data.adminId, socket.data.adminId);
    } else if (hasAssignedUser) {
      sql += `
        AND (
          sc.assigned_user_id IS NULL
          OR NOT EXISTS (
            SELECT 1
            FROM users ua2
            WHERE ua2.id = sc.assigned_user_id
              AND ua2.status_aktif = 1 AND COALESCE(ua2.status_tugas, 1) = 1
          )
          OR (
            sc.assigned_user_id = ?
            AND EXISTS (
              SELECT 1
              FROM users ua
              WHERE ua.id = sc.assigned_user_id
                AND ua.status_aktif = 1 AND COALESCE(ua.status_tugas, 1) = 1
            )
          )
        )
      `;
      params.push(socket.data.adminId);
    } else if (hasRouting) {
      sql += `
        AND (
          sc.category_id IS NULL
          OR EXISTS (
            SELECT 1
            FROM admin_category_routing acr
            INNER JOIN users u1 ON u1.id = acr.user_id AND u1.status_aktif = 1 AND COALESCE(u1.status_tugas, 1) = 1
            WHERE acr.category_id = sc.category_id
              AND acr.user_id = ?
          )
          OR NOT EXISTS (
            SELECT 1
            FROM admin_category_routing acr2
            INNER JOIN users u2 ON u2.id = acr2.user_id AND u2.status_aktif = 1 AND COALESCE(u2.status_tugas, 1) = 1
            WHERE acr2.category_id = sc.category_id
          )
        )
      `;
      params.push(socket.data.adminId);
    }
    if (hasLiveStatus) {
      sql += ` AND (sc.live_status IS NULL OR sc.live_status = 'waiting')`;
    }
    sql += ` ORDER BY sc.id DESC LIMIT 50`;
    const [rows] = await conn.query(sql, params);
    for (const row of rows) {
      const sid = String(row.live_session_id || '');
      if (!sid) continue;
      const prev = sessionMeta.get(sid);
      sessionMeta.set(sid, {
        staffCallId: row.staff_call_id,
        guestSocketId: prev?.guestSocketId ?? null,
        categoryId: Number(row.category_id) || 0,
        assignedUserId: Number(row.assigned_user_id) || null,
        answeredBy: prev?.answeredBy ?? null,
      });
      socket.emit('admin_notification', {
        session_id: sid,
        staff_call_id: row.staff_call_id,
        guest_name: row.guest_name,
        category: row.category || '—',
        category_id: Number(row.category_id) || 0,
        assigned_user_id: Number(row.assigned_user_id) || null,
        message_preview: String(row.message || '').slice(0, 200),
        visitor_phone: row.visitor_phone || '',
      });
      emitted += 1;
    }
    console.log(
      `[recepsionis-realtime] syncPendingLiveForAdmin: ${emitted} pending live row(s) → admin socket ${socket.id}`
    );
    socket.emit('admin_queue_sync', { count: emitted, ok: true });
    return emitted;
  } catch (e) {
    console.error('[recepsionis-realtime] syncPendingLiveForAdmin failed:', e);
    socket.emit('admin_queue_sync', { count: 0, ok: false, error: String(e?.message || e) });
    return 0;
  } finally {
    conn.release();
  }
}

async function emitChatListForAdmin(socket, adminId) {
  const conn = await pool.getConnection();
  try {
    const hasLiveStatus = await tableHasColumn(conn, 'staff_calls', 'live_status');
    const hasState = await hasAdminStateTable(conn);
    const hasRouting = await tableExists(conn, 'admin_category_routing');
    const hasAssignedUser = await tableHasColumn(conn, 'staff_calls', 'assigned_user_id');

    const stateJoin = hasState
      ? `LEFT JOIN live_chat_admin_state st
           ON st.live_session_id = sc.live_session_id AND st.admin_user_id = ?`
      : '';
    const stateAdminParam = hasState ? [adminId] : [];
    const whereNotDeleted = hasState ? ` AND st.deleted_at IS NULL` : '';
    const readExpr = hasState ? 'COALESCE(st.last_read_message_id, 0)' : '0';

    const routingClause = hasRouting
      ? `(
            sc.category_id IS NULL
            OR EXISTS (
              SELECT 1
              FROM admin_category_routing acr
              INNER JOIN users u1 ON u1.id = acr.user_id AND u1.status_aktif = 1 AND COALESCE(u1.status_tugas, 1) = 1
              WHERE acr.category_id = sc.category_id
                AND acr.user_id = ?
            )
            OR NOT EXISTS (
              SELECT 1
              FROM admin_category_routing acr2
              INNER JOIN users u2 ON u2.id = acr2.user_id AND u2.status_aktif = 1 AND COALESCE(u2.status_tugas, 1) = 1
              WHERE acr2.category_id = sc.category_id
            )
          )`
      : '1=1';

    const pendingVisibilityClause = hasAssignedUser && hasRouting
      ? `(
            (
              sc.assigned_user_id IS NOT NULL
              AND EXISTS (
                SELECT 1
                FROM users ua
                WHERE ua.id = sc.assigned_user_id
                  AND ua.status_aktif = 1 AND COALESCE(ua.status_tugas, 1) = 1
              )
              AND sc.assigned_user_id = ?
            )
            OR (
              (
                sc.assigned_user_id IS NULL
                OR NOT EXISTS (
                  SELECT 1
                  FROM users ua2
                  WHERE ua2.id = sc.assigned_user_id
                    AND ua2.status_aktif = 1 AND COALESCE(ua2.status_tugas, 1) = 1
                )
              )
              AND ${routingClause}
            )
          )`
      : hasAssignedUser
        ? `(
              sc.assigned_user_id IS NULL
              OR NOT EXISTS (
                SELECT 1
                FROM users ua2
                WHERE ua2.id = sc.assigned_user_id
                  AND ua2.status_aktif = 1 AND COALESCE(ua2.status_tugas, 1) = 1
              )
              OR (
                sc.assigned_user_id = ?
                AND EXISTS (
                  SELECT 1
                  FROM users ua
                  WHERE ua.id = sc.assigned_user_id
                    AND ua.status_aktif = 1 AND COALESCE(ua.status_tugas, 1) = 1
                )
              )
            )`
        : routingClause;

    const pendingVisibilityParams = hasAssignedUser && hasRouting
      ? [adminId, adminId]
      : hasAssignedUser
        ? [adminId]
        : hasRouting
          ? [adminId]
          : [];

    const [rows] = await conn.query(
      `
      SELECT
        sc.id AS staff_call_id,
        sc.live_session_id,
        sc.visitor_name AS guest_name,
        sc.visitor_phone,
        sc.message,
        sc.category_id,
        ${hasAssignedUser ? 'sc.assigned_user_id,' : 'NULL AS assigned_user_id,'}
        cc.nama_kategori AS category,
        sc.status,
        ${hasLiveStatus ? 'sc.live_status' : 'NULL AS live_status'},
        ${hasState ? 'st.last_read_message_id' : '0 AS last_read_message_id'},
        (SELECT MAX(m.id) FROM live_chat_messages m WHERE m.live_session_id = sc.live_session_id) AS last_msg_id,
        (SELECT m.body FROM live_chat_messages m WHERE m.live_session_id = sc.live_session_id ORDER BY m.id DESC LIMIT 1) AS last_msg_body,
        (SELECT m.created_at FROM live_chat_messages m WHERE m.live_session_id = sc.live_session_id ORDER BY m.id DESC LIMIT 1) AS last_msg_at,
        (SELECT COUNT(*) FROM live_chat_messages m
           WHERE m.live_session_id = sc.live_session_id
             AND m.sender = 'guest'
             AND m.id > ${readExpr}
        ) AS unread
      FROM staff_calls sc
      LEFT JOIN complaint_categories cc ON cc.id = sc.category_id
      ${stateJoin}
      WHERE sc.live_session_id IS NOT NULL
        AND sc.live_session_id <> ''
        AND (sc.call_type = 'live_chat' OR sc.call_type IS NULL)
        AND sc.status <> 'cancelled'
        AND (
          (sc.status = 'pending' AND ${pendingVisibilityClause})
          OR sc.answered_by = ?
        )
        ${whereNotDeleted}
      ORDER BY
        COALESCE(last_msg_id, 0) DESC,
        sc.id DESC
      LIMIT 80
      `,
      [...stateAdminParam, ...pendingVisibilityParams, adminId]
    );

    const chats = rows.map((r) => ({
      session_id: String(r.live_session_id),
      staff_call_id: Number(r.staff_call_id),
      guest_name: r.guest_name || '',
      visitor_phone: r.visitor_phone || '',
      category: r.category || '—',
      category_id: Number(r.category_id) || 0,
      assigned_user_id: Number(r.assigned_user_id) || null,
      message_preview: String(r.message || '').slice(0, 200),
      status: r.status,
      live_status: r.live_status,
      last_message: r.last_msg_body ? String(r.last_msg_body).slice(0, 200) : '',
      last_message_at:
        r.last_msg_at instanceof Date
          ? r.last_msg_at.toISOString()
          : r.last_msg_at
            ? String(r.last_msg_at)
            : '',
      unread: Number(r.unread) || 0,
    }));

    socket.emit('admin_chat_list', { ok: true, chats });
    return chats;
  } catch (e) {
    console.error('[recepsionis-realtime] emitChatListForAdmin failed:', e);
    socket.emit('admin_chat_list', { ok: false, chats: [], error: String(e?.message || e) });
    return [];
  } finally {
    conn.release();
  }
}

/**
 * Pulihkan satu sesi live aktif untuk admin ini (refresh halaman / reconnect).
 */
async function syncActiveLiveSessionsForAdmin(socket, adminId) {
  const conn = await pool.getConnection();
  try {
    const hasLive = await tableHasColumn(conn, 'staff_calls', 'live_status');
    let sql = `
      SELECT sc.id AS staff_call_id, sc.live_session_id, sc.visitor_name AS guest_name,
             sc.visitor_phone, sc.message, sc.category_id, sc.assigned_user_id, cc.nama_kategori AS category
      FROM staff_calls sc
      LEFT JOIN complaint_categories cc ON cc.id = sc.category_id
      WHERE sc.answered_by = ?
        AND sc.status = 'answered'
        AND sc.live_session_id IS NOT NULL
        AND sc.live_session_id <> ''
    `;
    if (hasLive) {
      sql += ` AND sc.live_status = 'active'`;
    }
    sql += ` ORDER BY sc.id DESC LIMIT 1`;
    const [rows] = await conn.query(sql, [adminId]);
    if (!rows.length) return;
    const row = rows[0];
    const sid = String(row.live_session_id || '');
    if (!sid) return;
    const prev = sessionMeta.get(sid);
    sessionMeta.set(sid, {
      staffCallId: row.staff_call_id,
      guestSocketId: prev?.guestSocketId ?? null,
      categoryId: Number(row.category_id) || 0,
      assignedUserId: Number(row.assigned_user_id) || null,
      answeredBy: adminId,
    });
    socket.join(`session:${sid}`);
    socket.data.activeSessionId = sid;
    socket.emit('admin_session_restored', {
      active: true,
      session_id: sid,
      staff_call_id: row.staff_call_id,
      guest_name: row.guest_name,
      category: row.category || '—',
      category_id: Number(row.category_id) || 0,
      message_preview: String(row.message || '').slice(0, 200),
      visitor_phone: row.visitor_phone || '',
    });
    console.log(
      `[recepsionis-realtime] syncActiveLiveSessionsForAdmin: restored session ${sid} for admin ${adminId}`
    );
  } catch (e) {
    console.error('[recepsionis-realtime] syncActiveLiveSessionsForAdmin failed:', e);
  } finally {
    conn.release();
  }
}

io.on('connection', (socket) => {
  if (socket.data.role === 'admin') {
    socket.join(ADMIN_ROOM);
    socket.join(adminUserRoom(socket.data.adminId));
    socket.emit('admin_ready', {});
    void (async () => {
      await joinAdminRoutingRooms(socket);
      await syncPendingLiveForAdmin(socket);
      await syncActiveLiveSessionsForAdmin(socket, socket.data.adminId);
      await emitChatListForAdmin(socket, socket.data.adminId);
    })();
  }

  socket.on('join_room', (payload, cb) => {
    cb?.({ ok: true, note: 'use accept_request to join session room' });
  });

  socket.on('guest_rejoin', async (payload, cb) => {
    if (socket.data.role !== 'guest') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    const session_id = payload?.session_id;
    if (!session_id) {
      cb?.({ ok: false, error: 'unknown_session' });
      return;
    }
    const conn = await pool.getConnection();
    try {
      let meta = sessionMeta.get(session_id);
      if (!meta) {
        const [found] = await conn.query(
          'SELECT id, status, category_id, assigned_user_id, answered_by FROM staff_calls WHERE live_session_id = ? LIMIT 1',
          [session_id]
        );
        if (!found.length || found[0].status === 'cancelled') {
          cb?.({ ok: false, error: 'unknown_session' });
          return;
        }
        meta = {
          staffCallId: found[0].id,
          guestSocketId: null,
          categoryId: Number(found[0].category_id) || 0,
          assignedUserId: Number(found[0].assigned_user_id) || null,
          answeredBy: Number(found[0].answered_by) || null,
        };
        sessionMeta.set(session_id, meta);
      }
      const hasLive = await tableHasColumn(conn, 'staff_calls', 'live_status');
      const [rows] = await conn.query(
        hasLive
          ? `SELECT sc.status, sc.live_status, u.nama_lengkap, u.username
             FROM staff_calls sc
             LEFT JOIN users u ON u.id = sc.answered_by
             WHERE sc.id = ? LIMIT 1`
          : `SELECT sc.status, u.nama_lengkap, u.username
             FROM staff_calls sc
             LEFT JOIN users u ON u.id = sc.answered_by
             WHERE sc.id = ? LIMIT 1`,
        [meta.staffCallId]
      );
      if (!rows.length) {
        cb?.({ ok: false, error: 'gone' });
        return;
      }
      const row = rows[0];
      if (row.status === 'cancelled' || (hasLive && row.live_status === 'ended')) {
        cb?.({ ok: false, error: 'ended' });
        return;
      }
      let phase = 'waiting';
      if (row.status === 'answered') {
        if (!hasLive) {
          phase = 'chat';
        } else if (row.live_status === 'active') {
          phase = 'chat';
        } else {
          phase = 'waiting';
        }
      }
      const adminNameRaw = row.nama_lengkap || row.username || '';
      const admin_name = String(adminNameRaw).trim() || 'Admin';
      socket.join(`session:${session_id}`);
      socket.data.liveSessionId = session_id;
      meta.guestSocketId = socket.id;
      cb?.({ ok: true, session_id, phase, admin_name });
    } finally {
      conn.release();
    }
  });

  socket.on('admin_sync_pending', async (_, cb) => {
    if (socket.data.role !== 'admin') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    try {
      await joinAdminRoutingRooms(socket);
      const count = await syncPendingLiveForAdmin(socket);
      cb?.({ ok: true, count });
    } catch (e) {
      cb?.({ ok: false, error: 'sync_failed', count: 0 });
    }
  });

  socket.on('admin_list_chats', async (_, cb) => {
    if (socket.data.role !== 'admin') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    try {
      await emitChatListForAdmin(socket, socket.data.adminId);
      cb?.({ ok: true });
    } catch (e) {
      cb?.({ ok: false, error: 'server_error' });
    }
  });

  socket.on('guest_request', async (payload, cb) => {
    if (socket.data.role !== 'guest') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    const { guest_name, visitor_phone, category_id, message } = payload || {};
    if (!guest_name || !visitor_phone || !message || !category_id) {
      cb?.({ ok: false, error: 'invalid_payload' });
      return;
    }

    const live_session_id = randomUUID();
    const conn = await pool.getConnection();
    try {
      const [cats] = await conn.query(
        'SELECT id, nama_kategori FROM complaint_categories WHERE id = ? AND status_aktif = 1 LIMIT 1',
        [category_id]
      );
      if (!cats.length) {
        cb?.({ ok: false, error: 'bad_category' });
        return;
      }
      const categoryName = cats[0].nama_kategori;
      const routedAdmins = await getActiveAdminsForCategory(conn, Number(category_id));
      if (!routedAdmins.length) {
        cb?.({ ok: false, error: 'no_target_admin' });
        return;
      }

      const staffCallId = await insertStaffCallLive(
        conn,
        String(guest_name).slice(0, 200),
        String(visitor_phone).slice(0, 50),
        String(message).slice(0, 8000),
        Number(category_id),
        live_session_id
      );

      await syncVisitorAndBadge(
        conn,
        String(guest_name).slice(0, 100),
        String(visitor_phone).slice(0, 20),
        String(message).slice(0, 2000),
        staffCallId
      );
      await insertNotification(
        conn,
        String(guest_name),
        String(visitor_phone),
        categoryName,
        String(message)
      );

      socket.join(`session:${live_session_id}`);
      socket.data.liveSessionId = live_session_id;
      let assignedUserId = null;
      let effectiveTargets = routedAdmins;
      if (routedAdmins.length === 1) {
        const autoAssignedUserId = Number(routedAdmins[0].id) || 0;
        if (
          autoAssignedUserId > 0
          && await assignStaffCall(
            conn,
            staffCallId,
            autoAssignedUserId,
            null,
            Number(category_id),
            'Pengaduan live chat otomatis ditugaskan karena kategori hanya memiliki satu admin aktif.',
            {
              source: 'realtime_guest_request',
              live_session_id,
              auto_assigned: true,
            }
          )
        ) {
          assignedUserId = autoAssignedUserId;
          effectiveTargets = await getEffectiveTargetsForCall(conn, assignedUserId, Number(category_id));
        }
      }
      sessionMeta.set(live_session_id, {
        staffCallId,
        guestSocketId: socket.id,
        categoryId: Number(category_id),
        assignedUserId,
        answeredBy: null,
      });

      await logStaffCallEvent(conn, staffCallId, 'created', null, null, Number(category_id), 'Live chat dibuat dari visitor.', {
        source: 'realtime_guest_request',
        category_name: categoryName,
        guest_name: String(guest_name).slice(0, 200),
        visitor_phone: String(visitor_phone).slice(0, 50),
        live_session_id,
        assigned_user_id: assignedUserId,
      });
      for (const admin of effectiveTargets) {
        await logStaffCallEvent(conn, staffCallId, 'notified', null, admin.id, Number(category_id), 'Notifikasi dikirim ke admin kategori.', {
          source: 'realtime_guest_request',
          live_session_id,
          assigned_user_id: assignedUserId,
        });
        io.to(adminUserRoom(admin.id)).emit('admin_notification', {
          session_id: live_session_id,
          staff_call_id: staffCallId,
          guest_name: String(guest_name),
          category: categoryName,
          category_id: Number(category_id),
          assigned_user_id: assignedUserId,
          message_preview: String(message).slice(0, 200),
          visitor_phone: String(visitor_phone),
        });
      }
      console.log(
        `[recepsionis-realtime] guest_request staff_call_id=${staffCallId} session=${live_session_id} → notified admins`
      );

      io.to(adminChatChangedRoom({ categoryId: Number(category_id), assignedUserId })).emit('admin_chat_changed', {
        session_id: live_session_id,
        reason: 'guest_request',
      });
      cb?.({ ok: true, session_id: live_session_id, staff_call_id: staffCallId });
    } catch (e) {
      console.error('guest_request', e);
      cb?.({ ok: false, error: 'server_error' });
    } finally {
      conn.release();
    }
  });

  socket.on('accept_request', async (payload, cb) => {
    if (socket.data.role !== 'admin') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    const session_id = payload?.session_id;
    if (!session_id) {
      cb?.({ ok: false, error: 'not_found' });
      return;
    }

    const conn = await pool.getConnection();
    try {
      let meta = sessionMeta.get(session_id);
      if (!meta) {
        const [found] = await conn.query(
          'SELECT id, category_id, assigned_user_id, answered_by FROM staff_calls WHERE live_session_id = ? LIMIT 1',
          [session_id]
        );
        if (!found.length) {
          cb?.({ ok: false, error: 'not_found' });
          return;
        }
        meta = {
          staffCallId: found[0].id,
          guestSocketId: null,
          categoryId: Number(found[0].category_id) || 0,
          assignedUserId: Number(found[0].assigned_user_id) || null,
          answeredBy: Number(found[0].answered_by) || null,
        };
        sessionMeta.set(session_id, meta);
      }

      const hasLiveStatus = await tableHasColumn(conn, 'staff_calls', 'live_status');
      const [rows] = await conn.query(
        hasLiveStatus
          ? 'SELECT status, live_status, answered_by, category_id, assigned_user_id FROM staff_calls WHERE id = ? LIMIT 1'
          : 'SELECT status, answered_by, category_id, assigned_user_id FROM staff_calls WHERE id = ? LIMIT 1',
        [meta.staffCallId]
      );
      if (!rows.length) {
        cb?.({ ok: false, error: 'not_found' });
        return;
      }
      const row = rows[0];
      const adminId = socket.data.adminId;
      const categoryId = Number(row.category_id) || 0;
      const assignedUserId = Number(row.assigned_user_id) || 0;

      if (row.status === 'cancelled' || (hasLiveStatus && row.live_status === 'ended')) {
        cb?.({ ok: false, error: 'ended' });
        return;
      }

      if (!(await adminCanReceiveStaffCall(conn, adminId, categoryId, assignedUserId, socket.data.userRole))) {
        cb?.({ ok: false, error: 'forbidden_category' });
        return;
      }

      if (row.status === 'pending') {
        let sql = `UPDATE staff_calls SET status = 'answered', answered_by = ?, answered_at = NOW() WHERE id = ? AND status = 'pending'`;
        if (hasLiveStatus) {
          sql = `UPDATE staff_calls SET status = 'answered', answered_by = ?, answered_at = NOW(), live_status = 'active' WHERE id = ? AND status = 'pending'`;
        }
        const [result] = await conn.query(sql, [adminId, meta.staffCallId]);
        if (result.affectedRows === 0) {
          cb?.({ ok: false, error: 'already_handled' });
          return;
        }
      } else if (row.status === 'answered') {
        if (hasLiveStatus && row.live_status === 'waiting') {
          cb?.({ ok: false, error: 'already_handled' });
          return;
        }
        const active = !hasLiveStatus || row.live_status === 'active' || row.live_status == null;
        if (!active) {
          cb?.({ ok: false, error: 'ended' });
          return;
        }
        if (Number(row.answered_by) !== adminId) {
          cb?.({ ok: false, error: 'taken' });
          return;
        }
      } else {
        cb?.({ ok: false, error: 'already_handled' });
        return;
      }

      socket.join(`session:${session_id}`);
      socket.data.activeSessionId = session_id;
      sessionMeta.set(session_id, {
        staffCallId: meta.staffCallId,
        guestSocketId: meta.guestSocketId ?? null,
        categoryId,
        assignedUserId: assignedUserId || meta.assignedUserId || null,
        answeredBy: adminId,
      });

      await logStaffCallEvent(conn, meta.staffCallId, 'accepted', adminId, null, categoryId, 'Permintaan live chat diterima admin.', {
        session_id,
      });

      io.to(`session:${session_id}`).emit('request_accepted', {
        admin_name: socket.data.adminName,
        session_id,
      });

      io.to(adminChatChangedRoom({ categoryId, assignedUserId: assignedUserId || meta.assignedUserId || null })).emit('admin_chat_changed', { session_id, reason: 'accept_request' });
      cb?.({ ok: true });
    } finally {
      conn.release();
    }
  });

  socket.on('admin_fetch_messages', async (payload, cb) => {
    if (socket.data.role !== 'admin') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    const session_id = payload?.session_id;
    if (!session_id) {
      cb?.({ ok: false, error: 'invalid' });
      return;
    }
    if (!socket.rooms.has(`session:${session_id}`)) {
      cb?.({ ok: false, error: 'not_in_room' });
      return;
    }

    const conn = await pool.getConnection();
    try {
      const hasTable = await tableExists(conn, 'live_chat_messages');
      if (!hasTable) {
        socket.emit('message_history', { session_id, messages: [] });
        cb?.({ ok: true });
        return;
      }
      const [msgs] = await conn.query(
        `SELECT m.sender, m.body, m.created_at, m.admin_user_id, u.nama_lengkap, u.username
         FROM live_chat_messages m
         LEFT JOIN users u ON u.id = m.admin_user_id AND m.sender = 'admin'
         WHERE m.live_session_id = ?
         ORDER BY m.id ASC`,
        [session_id]
      );
      const messages = msgs.map((r) => {
        const nm = r.nama_lengkap || r.username || '';
        return {
          sender: r.sender,
          body: r.body,
          created_at:
            r.created_at instanceof Date ? r.created_at.toISOString() : String(r.created_at || ''),
          admin_name: r.sender === 'admin' ? String(nm).trim() || 'Admin' : undefined,
        };
      });
      socket.emit('message_history', { session_id, messages });
      cb?.({ ok: true });
    } catch (e) {
      console.error('admin_fetch_messages', e);
      cb?.({ ok: false, error: 'server_error' });
    } finally {
      conn.release();
    }
  });

  socket.on('admin_mark_read', async (payload, cb) => {
    if (socket.data.role !== 'admin') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    const session_id = payload?.session_id;
    if (!session_id) {
      cb?.({ ok: false, error: 'invalid' });
      return;
    }

    const conn = await pool.getConnection();
    try {
      const hasState = await hasAdminStateTable(conn);
      const hasMsgs = await tableExists(conn, 'live_chat_messages');
      if (!hasState || !hasMsgs) {
        cb?.({ ok: true });
        return;
      }
      const [last] = await conn.query(
        'SELECT MAX(id) AS id FROM live_chat_messages WHERE live_session_id = ?',
        [session_id]
      );
      const lastId = Number(last?.[0]?.id || 0);
      await conn.query(
        `INSERT INTO live_chat_admin_state (live_session_id, admin_user_id, last_read_message_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), deleted_at = NULL`,
        [session_id, socket.data.adminId, lastId]
      );
      cb?.({ ok: true });
      await emitChatListForAdmin(socket, socket.data.adminId);
    } catch (e) {
      console.error('admin_mark_read', e);
      cb?.({ ok: false, error: 'server_error' });
    } finally {
      conn.release();
    }
  });

  socket.on('admin_delete_chat', async (payload, cb) => {
    if (socket.data.role !== 'admin') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    const session_id = payload?.session_id;
    if (!session_id) {
      cb?.({ ok: false, error: 'invalid' });
      return;
    }
    const conn = await pool.getConnection();
    try {
      const hasState = await hasAdminStateTable(conn);
      if (!hasState) {
        cb?.({ ok: false, error: 'no_table' });
        return;
      }
      await conn.query(
        `INSERT INTO live_chat_admin_state (live_session_id, admin_user_id, last_read_message_id, deleted_at)
         VALUES (?, ?, 0, NOW())
         ON DUPLICATE KEY UPDATE deleted_at = NOW()`,
        [session_id, socket.data.adminId]
      );
      cb?.({ ok: true });
      await emitChatListForAdmin(socket, socket.data.adminId);
    } catch (e) {
      console.error('admin_delete_chat', e);
      cb?.({ ok: false, error: 'server_error' });
    } finally {
      conn.release();
    }
  });

  socket.on('reject_request', async (payload, cb) => {
    if (socket.data.role !== 'admin') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    const session_id = payload?.session_id;
    const meta = session_id ? sessionMeta.get(session_id) : null;
    if (!meta) {
      cb?.({ ok: false, error: 'not_found' });
      return;
    }

    const conn = await pool.getConnection();
    try {
      const categoryId = Number(meta.categoryId || 0);
      await conn.query(
        `UPDATE staff_calls SET status = 'cancelled' WHERE id = ? AND status = 'pending'`,
        [meta.staffCallId]
      );
      const hasLive = await tableHasColumn(conn, 'staff_calls', 'live_status');
      if (hasLive) {
        await conn.query(`UPDATE staff_calls SET live_status = 'ended' WHERE id = ?`, [meta.staffCallId]);
      }
      await logStaffCallEvent(conn, meta.staffCallId, 'rejected', socket.data.adminId, null, categoryId, 'Permintaan live chat ditolak admin.', {
        session_id,
      });
    } finally {
      conn.release();
    }

    io.to(`session:${session_id}`).emit('request_rejected', { session_id });
    sessionMeta.delete(session_id);
    io.to(adminChatChangedRoom(meta)).emit('admin_chat_changed', { session_id, reason: 'reject_request' });
    cb?.({ ok: true });
  });

  socket.on('send_message', async (payload, cb) => {
    const session_id = payload?.session_id;
    const text = payload?.text;
    if (!session_id || !text || typeof text !== 'string') {
      cb?.({ ok: false, error: 'invalid' });
      return;
    }

    const meta = sessionMeta.get(session_id);
    if (!meta) {
      cb?.({ ok: false, error: 'no_session' });
      return;
    }

    let sender;
    let adminUserId = null;
    if (socket.data.role === 'admin') {
      if (!socket.rooms.has(`session:${session_id}`)) {
        cb?.({ ok: false, error: 'not_in_room' });
        return;
      }
      sender = 'admin';
      adminUserId = socket.data.adminId;
    } else if (socket.data.liveSessionId === session_id) {
      sender = 'guest';
    } else {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }

    const conn = await pool.getConnection();
    try {
      await persistMessage(conn, session_id, meta.staffCallId, sender, adminUserId, text);
    } finally {
      conn.release();
    }

    const created_at = new Date().toISOString();
    io.to(`session:${session_id}`).emit('receive_message', {
      session_id,
      sender,
      admin_name: socket.data.adminName,
      body: text,
      created_at,
    });
    if (sender === 'guest') {
      const roomTarget = meta.answeredBy
        ? adminUserRoom(meta.answeredBy)
        : adminChatChangedRoom(meta);
      io.to(roomTarget).emit('admin_chat_changed', { session_id, reason: 'guest_message' });
    }
    cb?.({ ok: true });
  });

  socket.on('typing_start', (payload) => {
    const session_id = payload?.session_id;
    if (!session_id) return;
    socket.to(`session:${session_id}`).emit('typing_start', {
      session_id,
      from: socket.data.role,
    });
  });

  socket.on('typing_stop', (payload) => {
    const session_id = payload?.session_id;
    if (!session_id) return;
    socket.to(`session:${session_id}`).emit('typing_stop', {
      session_id,
      from: socket.data.role,
    });
  });

  socket.on('end_session', async (payload, cb) => {
    if (socket.data.role !== 'admin') {
      cb?.({ ok: false, error: 'forbidden' });
      return;
    }
    const session_id = payload?.session_id;
    const meta = session_id ? sessionMeta.get(session_id) : null;
    if (!meta) {
      cb?.({ ok: false, error: 'not_found' });
      return;
    }

    const conn = await pool.getConnection();
    try {
      const hasLive = await tableHasColumn(conn, 'staff_calls', 'live_session_id');
      if (hasLive) {
        await conn.query(
          `UPDATE staff_calls SET live_status = 'ended' WHERE id = ? OR live_session_id = ?`,
          [meta.staffCallId, session_id]
        );
      }
      await logStaffCallEvent(
        conn,
        meta.staffCallId,
        'ended',
        socket.data.adminId,
        null,
        Number(meta.categoryId || 0),
        'Sesi live chat diakhiri admin.',
        { session_id }
      );
    } finally {
      conn.release();
    }

    io.to(`session:${session_id}`).emit('session_ended', { session_id });
    sessionMeta.delete(session_id);

    const room = `session:${session_id}`;
    const sockets = await io.in(room).fetchSockets();
    for (const s of sockets) {
      s.leave(room);
    }

    if (meta.answeredBy) {
      io.to(adminUserRoom(meta.answeredBy)).emit('admin_chat_changed', { session_id, reason: 'end_session' });
    } else {
      io.to(adminChatChangedRoom(meta)).emit('admin_chat_changed', { session_id, reason: 'end_session' });
    }

    cb?.({ ok: true });
  });

  socket.on('disconnect', () => {
    if (socket.data.role === 'guest' && socket.data.liveSessionId) {
      const sid = socket.data.liveSessionId;
      const meta = sessionMeta.get(sid);
      if (meta) meta.guestSocketId = null;
    }
  });
});

const PORT = Number(process.env.PORT || 3001);
const HOST = process.env.HOST || '0.0.0.0';

async function start() {
  try {
    const c = await pool.getConnection();
    c.release();
    const h = process.env.DB_HOST || 'localhost';
    const db = process.env.DB_NAME || 'recepsionis_db';
    console.log(`[recepsionis-realtime] MySQL OK (${h}:${DB_PORT}/${db})`);
  } catch (e) {
    console.error('[recepsionis-realtime] Gagal sambung MySQL:', e.code || e.message);
    console.error('  → VPS/aaPanel: biasanya DB_HOST=127.0.0.1  DB_PORT=3306 (samakan user/pass/db dengan koneksi.php).');
    console.error('  → MAMP: DB_PORT=8889 (cek Preferences → Ports). Node wajib TCP+port; PHP "localhost" bisa lewat socket.');
    process.exit(1);
  }
  server.listen(PORT, HOST, () => {
    console.log(`[recepsionis-realtime] listening on http://${HOST}:${PORT}`);
  });
}

start();
