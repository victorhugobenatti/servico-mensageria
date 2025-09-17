-- schema.sql - Estrutura inicial do banco SQLite para o chat

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT UNIQUE
);

CREATE TABLE IF NOT EXISTS groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT UNIQUE
);

CREATE TABLE IF NOT EXISTS group_members (
  group_id INTEGER,
  user_id INTEGER,
  PRIMARY KEY(group_id,user_id)
);

CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sender TEXT,
  receiver TEXT,
  groupname TEXT,
  type TEXT,       -- 'text' ou 'file'
  content TEXT,    -- texto da mensagem ou caminho do arquivo salvo
  filename TEXT,   -- nome original do arquivo
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  delivered INTEGER DEFAULT 0
);

