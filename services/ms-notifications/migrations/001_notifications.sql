CREATE TABLE processed_events (
  source varchar(30) NOT NULL CHECK (source IN ('agricultural','identity','billing')),
  event_id varchar(150) NOT NULL,
  company_id bigint NOT NULL CHECK (company_id > 0),
  payload_hash char(64) NOT NULL,
  payload jsonb NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING','PROCESSING','COMPLETED','FAILED')),
  attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
  next_attempt_at timestamptz NOT NULL DEFAULT now(),
  lock_token uuid, locked_until timestamptz,
  last_error_code varchar(60), created_at timestamptz NOT NULL DEFAULT now(), processed_at timestamptz,
  PRIMARY KEY (source,event_id)
);
CREATE INDEX events_due ON processed_events (next_attempt_at) WHERE status IN ('PENDING','PROCESSING');

CREATE TABLE notifications (
  id uuid PRIMARY KEY,
  seq bigserial UNIQUE NOT NULL,
  company_id bigint NOT NULL CHECK (company_id > 0),
  recipient_id bigint NOT NULL CHECK (recipient_id > 0),
  event_source varchar(30) NOT NULL,
  event_id varchar(150) NOT NULL,
  kind varchar(60) NOT NULL,
  title varchar(200) NOT NULL,
  message varchar(2000) NOT NULL,
  destination varchar(250) NOT NULL CHECK (destination ~ '^/[^/]' AND position(chr(92) in destination)=0),
  priority varchar(10) NOT NULL CHECK (priority IN ('normal','high','critical')),
  category varchar(20) NOT NULL CHECK (category IN ('operational','security','billing')),
  eligibility_permission varchar(120) NOT NULL,
  read_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (event_source,event_id) REFERENCES processed_events(source,event_id) ON DELETE RESTRICT,
  UNIQUE (event_source,event_id,recipient_id)
);
CREATE INDEX notifications_inbox ON notifications (company_id,recipient_id,seq DESC);
CREATE INDEX notifications_unread ON notifications (company_id,recipient_id) WHERE read_at IS NULL;

CREATE TABLE notification_preferences (
  company_id bigint NOT NULL CHECK (company_id > 0),
  recipient_id bigint NOT NULL CHECK (recipient_id > 0),
  kind varchar(60) NOT NULL,
  inbox_enabled boolean NOT NULL DEFAULT true,
  email_enabled boolean NOT NULL DEFAULT false,
  updated_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (company_id,recipient_id,kind)
);

CREATE TABLE notification_deliveries (
  id uuid PRIMARY KEY,
  notification_id uuid NOT NULL UNIQUE REFERENCES notifications(id) ON DELETE RESTRICT,
  status varchar(30) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING','PROCESSING','PROVIDER_ACCEPTED','CANCELLED','FAILED')),
  attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
  next_attempt_at timestamptz NOT NULL DEFAULT now(),
  lock_token uuid, locked_until timestamptz,
  last_error_code varchar(60), provider_message_id varchar(500),
  created_at timestamptz NOT NULL DEFAULT now(), accepted_at timestamptz
);
CREATE INDEX deliveries_due ON notification_deliveries (next_attempt_at) WHERE status IN ('PENDING','PROCESSING');

CREATE TABLE notification_rate_limits (
  key varchar(300) PRIMARY KEY, window_start timestamptz NOT NULL, hits integer NOT NULL
);
