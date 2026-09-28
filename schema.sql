-- Beat database schema (PostgreSQL)

CREATE TABLE users (
  id serial PRIMARY KEY,
  username text UNIQUE NOT NULL,
  password_hash text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE sessions (
  token_hash text PRIMARY KEY,
  user_id int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at timestamptz NOT NULL
);

CREATE TABLE projects (
  id serial PRIMARY KEY,
  user_id int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name text NOT NULL,
  color text NOT NULL DEFAULT '#4f7cff',
  archived boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE timers (
  id serial PRIMARY KEY,
  user_id int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  project_id int REFERENCES projects(id) ON DELETE SET NULL,
  description text NOT NULL DEFAULT '',
  notes text NOT NULL DEFAULT '',
  start_time timestamptz NOT NULL,
  end_time timestamptz,
  CHECK (end_time IS NULL OR end_time >= start_time)
);
CREATE INDEX timers_user_start ON timers(user_id, start_time DESC);

CREATE TABLE login_attempts (
  ip text NOT NULL,
  attempted_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX login_attempts_ip ON login_attempts(ip, attempted_at);
