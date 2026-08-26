-- Enable pgcrypto for secure password hashing
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'customer',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 2. Subscriptions Table
CREATE TABLE IF NOT EXISTS subscriptions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    plan VARCHAR(100),
    status VARCHAR(50) DEFAULT 'active_trial',
    price DECIMAL(10, 2) DEFAULT 0.00,
    trial_ends_at TIMESTAMP WITH TIME ZONE,
    period_ends_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 3. Transactions Table
CREATE TABLE IF NOT EXISTS transactions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    amount DECIMAL(10, 2) NOT NULL,
    method VARCHAR(50),
    status VARCHAR(50) DEFAULT 'succeeded',
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 4. Facilities Table
CREATE TABLE IF NOT EXISTS facilities (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50),
    rate DECIMAL(10, 2) NOT NULL,
    open_hour INT,
    close_hour INT,
    interval_min INT DEFAULT 60,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 5. Bookings Table
CREATE TABLE IF NOT EXISTS bookings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    facility_id UUID REFERENCES facilities(id) ON DELETE CASCADE,
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    booking_date DATE NOT NULL,
    slot VARCHAR(50) NOT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    amount DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- ==========================================
-- DEMO DATA INSERTION (10 Rows per table)
-- Default Password for ALL users: "password"
-- ==========================================

INSERT INTO users (name, email, password_hash, role) VALUES
('Admin User', 'admin@azkejora.io', crypt('password', gen_salt('bf')), 'admin'),
('Daniel Wong', 'daniel@luminares.co', crypt('password', gen_salt('bf')), 'customer'),
('Nurul Izzah', 'nurul@kedairakyat.my', crypt('password', gen_salt('bf')), 'customer'),
('Marcus Teo', 'marcus@fitzone.asia', crypt('password', gen_salt('bf')), 'customer'),
('Priya Nair', 'priya@bloomscafe.com', crypt('password', gen_salt('bf')), 'customer'),
('Hafiz Rahman', 'hafiz@servispro.my', crypt('password', gen_salt('bf')), 'customer'),
('Elena Cruz', 'elena@studiocrux.co', crypt('password', gen_salt('bf')), 'customer'),
('Amirul Fikri', 'amirul@tanjungsports.my', crypt('password', gen_salt('bf')), 'customer'),
('Grace Lim', 'grace@petalworks.sg', crypt('password', gen_salt('bf')), 'customer'),
('Ryan Ong', 'ryan@quicklane.io', crypt('password', gen_salt('bf')), 'customer');

-- Subscriptions
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, 'Scale', 'active', 450, NULL, NOW() + INTERVAL '27 days' FROM users WHERE email = 'admin@azkejora.io';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, 'Growth', 'active', 210, NULL, NOW() + INTERVAL '-5 days' FROM users WHERE email = 'daniel@luminares.co';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, 'Starter', 'active', 90, NULL, NOW() + INTERVAL '6 days' FROM users WHERE email = 'nurul@kedairakyat.my';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, 'Scale', 'active', 450, NULL, NOW() + INTERVAL '20 days' FROM users WHERE email = 'marcus@fitzone.asia';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, 'Starter', 'active', 90, NULL, NOW() + INTERVAL '23 days' FROM users WHERE email = 'priya@bloomscafe.com';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, 'Growth', 'past_due', 210, NULL, NOW() + INTERVAL '3 days' FROM users WHERE email = 'hafiz@servispro.my';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, 'Scale', 'canceled', 450, NULL, NOW() + INTERVAL '-3 days' FROM users WHERE email = 'elena@studiocrux.co';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, NULL, 'active_trial', 0, NOW() + INTERVAL '2 hours', NULL FROM users WHERE email = 'amirul@tanjungsports.my';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, NULL, 'active_trial', 0, NOW() + INTERVAL '2 hours', NULL FROM users WHERE email = 'grace@petalworks.sg';
INSERT INTO subscriptions (user_id, plan, status, price, trial_ends_at, period_ends_at)
SELECT id, 'Starter', 'canceled', 90, NULL, NOW() + INTERVAL '15 days' FROM users WHERE email = 'ryan@quicklane.io';

-- Transactions
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 450, 'Stripe', 'succeeded', 'Scale - 3-month cycle' FROM users WHERE email = 'admin@azkejora.io';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 210, 'ToyyibPay', 'succeeded', 'Growth - 3-month cycle' FROM users WHERE email = 'daniel@luminares.co';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 90, 'Stripe', 'succeeded', 'Starter - 3-month cycle' FROM users WHERE email = 'nurul@kedairakyat.my';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 450, 'Stripe', 'succeeded', 'Scale - 3-month cycle' FROM users WHERE email = 'marcus@fitzone.asia';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 90, 'ToyyibPay', 'succeeded', 'Starter - 3-month cycle' FROM users WHERE email = 'priya@bloomscafe.com';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 210, 'Stripe', 'succeeded', 'Growth - 3-month cycle' FROM users WHERE email = 'hafiz@servispro.my';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 450, 'Stripe', 'succeeded', 'Scale - 3-month cycle' FROM users WHERE email = 'elena@studiocrux.co';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 0, 'None', 'pending', 'Trial signup' FROM users WHERE email = 'amirul@tanjungsports.my';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 0, 'None', 'pending', 'Trial signup' FROM users WHERE email = 'grace@petalworks.sg';
INSERT INTO transactions (user_id, amount, method, status, description) SELECT id, 90, 'Stripe', 'succeeded', 'Starter - 3-month cycle' FROM users WHERE email = 'ryan@quicklane.io';

-- Facilities
INSERT INTO facilities (name, type, rate, open_hour, close_hour, interval_min, active) VALUES
('Badminton Court 1', 'court', 18, 8, 22, 60, TRUE),
('Glass Meeting Suite', 'room', 45, 8, 22, 60, TRUE),
('The Pavilion Hall', 'hall', 220, 8, 22, 60, TRUE),
('Tennis Court A', 'court', 25, 8, 22, 60, TRUE),
('Board Room B', 'room', 50, 8, 22, 60, TRUE),
('Conference Hall 2', 'hall', 250, 8, 22, 60, TRUE),
('Squash Court 1', 'court', 20, 8, 22, 60, TRUE),
('Yoga Studio', 'room', 35, 8, 22, 60, TRUE),
('Event Space C', 'hall', 300, 8, 22, 60, TRUE),
('Pod Room 1', 'room', 40, 8, 22, 60, TRUE);

-- Bookings
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '8:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Badminton Court 1' AND u.email = 'admin@azkejora.io';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '9:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Glass Meeting Suite' AND u.email = 'daniel@luminares.co';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '10:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'The Pavilion Hall' AND u.email = 'nurul@kedairakyat.my';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '11:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Tennis Court A' AND u.email = 'marcus@fitzone.asia';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '12:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Board Room B' AND u.email = 'priya@bloomscafe.com';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '13:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Conference Hall 2' AND u.email = 'hafiz@servispro.my';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '14:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Squash Court 1' AND u.email = 'elena@studiocrux.co';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '15:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Yoga Studio' AND u.email = 'amirul@tanjungsports.my';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '16:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Event Space C' AND u.email = 'grace@petalworks.sg';
INSERT INTO bookings (facility_id, user_id, booking_date, slot, status, amount) SELECT f.id, u.id, CURRENT_DATE, '17:00', 'confirmed', f.rate FROM facilities f, users u WHERE f.name = 'Pod Room 1' AND u.email = 'ryan@quicklane.io';


-- Extend users table with phone + activation fields
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30);
ALTER TABLE users ADD COLUMN IF NOT EXISTS activation_token VARCHAR(64);
ALTER TABLE users ADD COLUMN IF NOT EXISTS activated_at TIMESTAMP WITH TIME ZONE;

-- E-Invoice items storage (referenced by dashboard)
CREATE TABLE IF NOT EXISTS einvoice_items (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 user_id UUID REFERENCES users(id) ON DELETE CASCADE,
 ref VARCHAR(100), invoice_date DATE, description TEXT, category VARCHAR(100),
 tin VARCHAR(50), amount DECIMAL(12,2) DEFAULT 0, tax DECIMAL(12,2) DEFAULT 0,
 status VARCHAR(30) DEFAULT 'pending',
 created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_einvoice_user ON einvoice_items(user_id);

-- Add avatar to users
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255);

-- Company profiles
CREATE TABLE IF NOT EXISTS companies (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(255),
    registration_no VARCHAR(100),
    address TEXT,
    business_type VARCHAR(100),
    postcode VARCHAR(20),
    state VARCHAR(100),
    town VARCHAR(100),
    -- E-Invoice config
    msic_code VARCHAR(50),
    classification_code VARCHAR(50),
    taxpayer_tin VARCHAR(100),
    taxpayer_brn VARCHAR(100),
    sandbox_clientid TEXT,
    sandbox_secret1 TEXT,
    sandbox_secret2 TEXT,
    prod_clientid TEXT,
    prod_secret1 TEXT,
    prod_secret2 TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_companies_user ON companies(user_id);

ALTER TABLE users ADD COLUMN IF NOT EXISTS reg_type VARCHAR(20) DEFAULT 'manual';
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(255);
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_picture VARCHAR(500);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_google ON users(google_id) WHERE google_id IS NOT NULL;
UPDATE users SET reg_type = 'manual' WHERE reg_type IS NULL OR reg_type = '';

ALTER TABLE users ALTER COLUMN password_hash DROP NOT NULL;

-- 1) Submission environment selector (Sandbox UAT / Production)
ALTER TABLE users ADD COLUMN IF NOT EXISTS ei_env VARCHAR(20) NOT NULL DEFAULT 'sandbox';

-- 2) MyInvois API base URLs (with your default data)
ALTER TABLE users ADD COLUMN IF NOT EXISTS ei_url_sandbox VARCHAR(255) NOT NULL DEFAULT 'https://preprod-api.myinvois.hasil.gov.my';
ALTER TABLE users ADD COLUMN IF NOT EXISTS ei_url_prod    VARCHAR(255) NOT NULL DEFAULT 'https://api.myinvois.hasil.gov.my';

-- 3) OAuth token storage + last token date
ALTER TABLE users ADD COLUMN IF NOT EXISTS ei_token TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS ei_token_at TIMESTAMP WITH TIME ZONE;

-- 1. Platform Admin Company Profile (For AZ Kejora's own business details)
CREATE TABLE IF NOT EXISTS admin_company (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL DEFAULT 'AZ Kejora SaaS',
    registration_no VARCHAR(100),
    address TEXT,
    postcode VARCHAR(20),
    state VARCHAR(100),
    town VARCHAR(100),
    email VARCHAR(255),
    phone VARCHAR(50),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);
INSERT INTO admin_company (name) VALUES ('AZ Kejora SaaS') ON CONFLICT DO NOTHING;

-- 2. Admin Users Management (Dedicated table for platform administrators)
CREATE TABLE IF NOT EXISTS admin_users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255),
    role VARCHAR(50) DEFAULT 'admin',
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    last_login TIMESTAMP WITH TIME ZONE
);

-- Activity log table (optional - for granular activity tracking)
CREATE TABLE IF NOT EXISTS activity_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    metadata JSONB,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_activity_user ON activity_logs(user_id, created_at DESC);

-- Subscriber Users Management (sub-users under the main customer account)
CREATE TABLE IF NOT EXISTS subscriber_users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id UUID REFERENCES users(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    position VARCHAR(100),
    password_hash VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    UNIQUE(owner_id, email)
);

CREATE INDEX IF NOT EXISTS idx_sub_users_owner ON subscriber_users(owner_id);
CREATE INDEX IF NOT EXISTS idx_sub_users_role  ON subscriber_users(owner_id, role);

-- Add payment-related columns to subscriptions table
ALTER TABLE public.subscriptions
  ADD COLUMN IF NOT EXISTS receipt_no    VARCHAR(50),
  ADD COLUMN IF NOT EXISTS payment_date  TIMESTAMP WITH TIME ZONE,
  ADD COLUMN IF NOT EXISTS payment_type  VARCHAR(50),
  ADD COLUMN IF NOT EXISTS ref_no        VARCHAR(100),
  ADD COLUMN IF NOT EXISTS bank          VARCHAR(100),
  ADD COLUMN IF NOT EXISTS amount        NUMERIC(10,2);

-- Optional: add indexes for common lookups
CREATE INDEX IF NOT EXISTS idx_subs_user_payment_date 
  ON public.subscriptions (user_id, payment_date DESC);

CREATE INDEX IF NOT EXISTS idx_subs_receipt 
  ON public.subscriptions (receipt_no);

-- Track uploaded files
CREATE TABLE IF NOT EXISTS einvoice_uploads (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500),
    total_records INTEGER DEFAULT 0,
    valid_records INTEGER DEFAULT 0,
    invalid_records INTEGER DEFAULT 0,
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Individual invoice records from uploads
CREATE TABLE IF NOT EXISTS einvoice_records (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    upload_id UUID REFERENCES einvoice_uploads(id) ON DELETE CASCADE,
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    document_type VARCHAR(10) DEFAULT '01',
    sale_no VARCHAR(100),
    customer_name VARCHAR(255),
    customer_address TEXT,
    customer_postcode VARCHAR(20),
    customer_phone VARCHAR(50),
    customer_email VARCHAR(255),
    customer_ic VARCHAR(50),
    customer_type VARCHAR(50) DEFAULT 'general',
    sale_title VARCHAR(255),
    sale_amount DECIMAL(12,2) DEFAULT 0,
    sale_tax DECIMAL(12,2) DEFAULT 0,
    total_amount DECIMAL(12,2) DEFAULT 0,
    sale_datetime TIMESTAMP WITH TIME ZONE,
    validation_status VARCHAR(50) DEFAULT 'pending',
    validation_errors TEXT,
    submission_status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Daily consolidated sales
CREATE TABLE IF NOT EXISTS einvoice_consolidated (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    sale_date DATE NOT NULL,
    total_records INTEGER DEFAULT 0,
    total_amount DECIMAL(12,2) DEFAULT 0,
    total_tax DECIMAL(12,2) DEFAULT 0,
    grand_total DECIMAL(12,2) DEFAULT 0,
    submission_status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- LHDN API submission log
CREATE TABLE IF NOT EXISTS einvoice_submissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    record_id UUID REFERENCES einvoice_records(id) ON DELETE CASCADE,
    consolidated_id UUID REFERENCES einvoice_consolidated(id) ON DELETE CASCADE,
    submission_type VARCHAR(50), -- 'individual' or 'consolidated'
    lhdn_uuid VARCHAR(255),
    submission_datetime TIMESTAMP WITH TIME ZONE,
    status VARCHAR(50),
    api_response JSONB,
    error_message TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_uploads_user ON einvoice_uploads(user_id);
CREATE INDEX IF NOT EXISTS idx_records_upload ON einvoice_records(upload_id);
CREATE INDEX IF NOT EXISTS idx_records_user ON einvoice_records(user_id);
CREATE INDEX IF NOT EXISTS idx_consolidated_user ON einvoice_consolidated(user_id);
CREATE INDEX IF NOT EXISTS idx_submissions_user ON einvoice_submissions(user_id);


-- 1. Add Token & Expiry Columns to Companies
ALTER TABLE public.companies 
ADD COLUMN IF NOT EXISTS sandbox_token text,
ADD COLUMN IF NOT EXISTS sandbox_token_expiry timestamp with time zone,
ADD COLUMN IF NOT EXISTS prod_token text,
ADD COLUMN IF NOT EXISTS prod_token_expiry timestamp with time zone,
ADD COLUMN IF NOT EXISTS phone character varying(50),
ADD COLUMN IF NOT EXISTS email character varying(100);

-- 2. Add Document Status Columns to Submissions
ALTER TABLE public.einvoice_submissions
ADD COLUMN IF NOT EXISTS long_id character varying(255),
ADD COLUMN IF NOT EXISTS document_status character varying(50),
ADD COLUMN IF NOT EXISTS document_status_response jsonb;

-- 3. Add Missing Customer Fields to Records (for JSON mapping)
ALTER TABLE public.einvoice_records
ADD COLUMN IF NOT EXISTS customer_tin character varying(100),
ADD COLUMN IF NOT EXISTS customer_town character varying(100),
ADD COLUMN IF NOT EXISTS reference_no character varying(100),
ADD COLUMN IF NOT EXISTS reference_uuid character varying(255);

-- 4. Create Queue Table for Async Processing
CREATE TABLE IF NOT EXISTS public.einvoice_queue (
  id uuid not null default gen_random_uuid(),
  user_id uuid null,
  submission_id uuid null,
  record_id uuid null,
  consolidated_id uuid null,
  payload jsonb null,
  status character varying(50) default 'pending', -- pending, processing, completed, failed
  attempts integer default 0,
  last_error text,
  created_at timestamp with time zone default now(),
  processed_at timestamp with time zone
);

-- 5. Create Internal Logs Table for Monitoring
CREATE TABLE IF NOT EXISTS public.einvoice_logs (
  id uuid not null default gen_random_uuid(),
  user_id uuid null,
  submission_id uuid null,
  action character varying(100),
  message text,
  response jsonb,
  created_at timestamp with time zone default now()
);

ALTER TABLE public.einvoice_logs ADD COLUMN IF NOT EXISTS step VARCHAR(50);

-- 1. Add missing 'step' column to einvoice_logs (if not exists)
ALTER TABLE public.einvoice_logs ADD COLUMN IF NOT EXISTS step VARCHAR(50);

-- 2. Add consolidated_id to einvoice_records to link detail records to a consolidated submission
ALTER TABLE public.einvoice_records ADD COLUMN IF NOT EXISTS consolidated_id uuid REFERENCES public.einvoice_consolidated(id) ON DELETE SET NULL;

-- 3. Add payload and response tracking columns to einvoice_consolidated
ALTER TABLE public.einvoice_consolidated ADD COLUMN IF NOT EXISTS ei_json text;
ALTER TABLE public.einvoice_consolidated ADD COLUMN IF NOT EXISTS ei_convert text;
ALTER TABLE public.einvoice_consolidated ADD COLUMN IF NOT EXISTS ei_submission_id character varying(255);
ALTER TABLE public.einvoice_consolidated ADD COLUMN IF NOT EXISTS ei_uuid character varying(255);
ALTER TABLE public.einvoice_consolidated ADD COLUMN IF NOT EXISTS lhdn_status character varying(50);
ALTER TABLE public.einvoice_consolidated ADD COLUMN IF NOT EXISTS lhdn_response jsonb;
ALTER TABLE public.einvoice_consolidated ADD COLUMN IF NOT EXISTS lhdn_response_2 jsonb;

ALTER TABLE public.companies ADD COLUMN IF NOT EXISTS sandbox_token text;
ALTER TABLE public.companies ADD COLUMN IF NOT EXISTS sandbox_token_expiry timestamp with time zone;
ALTER TABLE public.companies ADD COLUMN IF NOT EXISTS prod_token text;
ALTER TABLE public.companies ADD COLUMN IF NOT EXISTS prod_token_expiry timestamp with time zone;
ALTER TABLE public.companies ADD COLUMN IF NOT EXISTS phone character varying(50);
ALTER TABLE public.companies ADD COLUMN IF NOT EXISTS email character varying(100);
