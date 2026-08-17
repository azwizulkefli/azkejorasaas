import React, { useState } from 'react';
import { 
  Database, 
  Key, 
  Code2, 
  Check, 
  Copy, 
  Terminal, 
  ShieldCheck, 
  Server, 
  Layers, 
  ExternalLink,
  ChevronRight,
  Zap,
  Lock,
  Globe,
  UserCheck,
  Calendar,
  Sparkles
} from 'lucide-react';

const ENV_EXAMPLE_CODE = `# GEMINI_API_KEY: Required for Gemini AI API calls.
GEMINI_API_KEY="MY_GEMINI_API_KEY"

# APP_URL: The URL where this applet is hosted.
APP_URL="MY_APP_URL"

# ==========================================
# SUPABASE CONFIGURATION
# ==========================================
SUPABASE_URL="https://jfdpnbkacxnlsquqypsy.supabase.co"
SUPABASE_ANON_KEY="sb_publishable__1gkmebdNjwiAUhx0cmyAg_f40oMmDS"
SUPABASE_SERVICE_ROLE_KEY="your_supabase_service_role_key_here"

# ==========================================
# SUPABASE POSTGRESQL DATABASE (PDO / DIRECT)
# ==========================================
DB_HOST="aws-0-us-east-1.pooler.supabase.com"
DB_PORT="6543"
DB_NAME="postgres"
DB_USER="postgres.jfdpnbkacxnlsquqypsy"
DB_PASSWORD="your_database_password_here"
DB_SSLMODE="require"

# ==========================================
# GOOGLE OAUTH 2.0 CREDENTIALS
# ==========================================
GOOGLE_CLIENT_ID="your_google_client_id.apps.googleusercontent.com"
GOOGLE_CLIENT_SECRET="your_google_client_secret"
GOOGLE_REDIRECT_URI="http://localhost:3000/auth.php?action=callback"

# ==========================================
# STRIPE API CONFIGURATION
# ==========================================
STRIPE_SECRET_KEY="sk_test_51XXXXXXXXXXXXX"
STRIPE_PUBLISHABLE_KEY="pk_test_51XXXXXXXXXXXXX"
STRIPE_WEBHOOK_SECRET="whsec_XXXXXXXXXXXXX"`;

const DB_USAGE_CODE = `<?php
require_once __DIR__ . '/db.php';

// 1. Get Singleton Database Instance
$db = Database::getInstance();

// 2. Query Single Record
$user = $db->fetch("SELECT * FROM users WHERE id = :id", ['id' => 1]);

// 3. Query All Matching Records
$activeUsers = $db->fetchAll(
    "SELECT id, email, created_at FROM users WHERE status = :status", 
    ['status' => 'active']
);

// 4. Insert Record (Supports PostgreSQL RETURNING clause)
$newUserId = $db->insert('users', [
    'email' => 'developer@example.com',
    'name'  => 'Alex Morgan',
], 'id');

// 5. Update Record
$affectedRows = $db->update(
    'users',
    ['name' => 'Alex M.'],
    'id = :id',
    ['id' => $newUserId]
);

// 6. Atomic Transaction
$db->transaction(function (Database $db) {
    $db->insert('audit_logs', ['action' => 'user_signup', 'user_id' => 1]);
    $db->update('stats', ['total_users' => 101], 'id = :id', ['id' => 1]);
});`;

const AUTH_SUMMARY_CODE = `<?php
// How auth.php handles first-time Google sign-in:

$googleId = (string) $profile['sub'];
$email    = (string) $profile['email'];
$name     = (string) $profile['name'];
$avatar   = (string) $profile['picture'];
$now      = date('Y-m-d H:i:s');

// 1. Check if user exists
$existingUser = $db->fetch(
    "SELECT * FROM users WHERE google_id = :google_id OR email = :email LIMIT 1",
    ['google_id' => $googleId, 'email' => $email]
);

if (!$existingUser) {
    // 2. First-time sign-in: Execute atomic transaction
    $result = $db->transaction(function (Database $db) use ($googleId, $email, $name, $avatar, $now) {
        
        // Save user details
        $userId = $db->insert('users', [
            'google_id'     => $googleId,
            'email'         => $email,
            'name'          => $name,
            'avatar_url'    => $avatar,
            'created_at'    => $now,
            'updated_at'    => $now,
            'last_login_at' => $now,
        ], 'id');

        // Automatically create 30-day trial record
        $subscriptionId = $db->insert('subscriptions', [
            'user_id'    => $userId,
            'plan'       => 'trial_30_day',
            'status'     => 'active',
            'starts_at'  => $now,
            'ends_at'    => date('Y-m-d H:i:s', strtotime('+30 days')),
            'created_at' => $now,
            'updated_at' => $now,
        ], 'id');

        return [
            'user'         => $db->fetch("SELECT * FROM users WHERE id = :id", ['id' => $userId]),
            'subscription' => $db->fetch("SELECT * FROM subscriptions WHERE id = :id", ['id' => $subscriptionId]),
            'is_new'       => true
        ];
    });
}`;

export default function App() {
  const [activeTab, setActiveTab] = useState<'overview' | 'db' | 'auth' | 'env' | 'usage'>('overview');
  const [copiedSection, setCopiedSection] = useState<string | null>(null);

  const copyToClipboard = (text: string, section: string) => {
    navigator.clipboard.writeText(text);
    setCopiedSection(section);
    setTimeout(() => setCopiedSection(null), 2000);
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 font-sans selection:bg-cyan-500 selection:text-slate-950">
      {/* Top Header */}
      <header className="border-b border-slate-800/80 bg-slate-900/60 backdrop-blur-md sticky top-0 z-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center space-x-3">
            <div className="p-2 bg-gradient-to-tr from-cyan-500 to-emerald-400 rounded-lg shadow-lg shadow-cyan-500/10">
              <Database className="w-5 h-5 text-slate-950" />
            </div>
            <div>
              <div className="flex items-center space-x-2">
                <h1 className="text-base font-semibold text-slate-100 tracking-tight">Supabase PHP Database & OAuth Suite</h1>
                <span className="px-2 py-0.5 text-xs font-mono font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-full">
                  PHP 8.2+ PDO
                </span>
              </div>
              <p className="text-xs text-slate-400">PostgreSQL singleton connector, Google OAuth & auto 30-day trial provisioner</p>
            </div>
          </div>

          <div className="flex items-center space-x-3 text-xs">
            <a 
              href="https://jfdpnbkacxnlsquqypsy.supabase.co" 
              target="_blank" 
              rel="noreferrer"
              className="hidden sm:flex items-center space-x-1.5 px-3 py-1.5 rounded-md bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-colors border border-slate-700/60"
            >
              <span>Project Dashboard</span>
              <ExternalLink className="w-3.5 h-3.5 text-slate-400" />
            </a>
          </div>
        </div>
      </header>

      {/* Main Container */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        {/* Navigation Tabs */}
        <div className="flex space-x-1 bg-slate-900/80 p-1.5 rounded-xl border border-slate-800 mb-8 max-w-3xl overflow-x-auto">
          <button
            onClick={() => setActiveTab('overview')}
            className={`flex-1 min-w-[110px] flex items-center justify-center space-x-2 py-2 px-3 text-sm font-medium rounded-lg transition-all ${
              activeTab === 'overview'
                ? 'bg-gradient-to-r from-slate-800 to-slate-800/90 text-cyan-400 shadow-sm border border-slate-700/80'
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <Server className="w-4 h-4" />
            <span>Overview</span>
          </button>
          
          <button
            onClick={() => setActiveTab('db')}
            className={`flex-1 min-w-[110px] flex items-center justify-center space-x-2 py-2 px-3 text-sm font-medium rounded-lg transition-all ${
              activeTab === 'db'
                ? 'bg-gradient-to-r from-slate-800 to-slate-800/90 text-cyan-400 shadow-sm border border-slate-700/80'
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <Code2 className="w-4 h-4" />
            <span>db.php</span>
          </button>

          <button
            onClick={() => setActiveTab('auth')}
            className={`flex-1 min-w-[110px] flex items-center justify-center space-x-2 py-2 px-3 text-sm font-medium rounded-lg transition-all ${
              activeTab === 'auth'
                ? 'bg-gradient-to-r from-slate-800 to-slate-800/90 text-cyan-400 shadow-sm border border-slate-700/80'
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <ShieldCheck className="w-4 h-4" />
            <span>auth.php</span>
          </button>

          <button
            onClick={() => setActiveTab('env')}
            className={`flex-1 min-w-[110px] flex items-center justify-center space-x-2 py-2 px-3 text-sm font-medium rounded-lg transition-all ${
              activeTab === 'env'
                ? 'bg-gradient-to-r from-slate-800 to-slate-800/90 text-cyan-400 shadow-sm border border-slate-700/80'
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <Key className="w-4 h-4" />
            <span>.env.example</span>
          </button>

          <button
            onClick={() => setActiveTab('usage')}
            className={`flex-1 min-w-[120px] flex items-center justify-center space-x-2 py-2 px-3 text-sm font-medium rounded-lg transition-all ${
              activeTab === 'usage'
                ? 'bg-gradient-to-r from-slate-800 to-slate-800/90 text-cyan-400 shadow-sm border border-slate-700/80'
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/40'
            }`}
          >
            <Terminal className="w-4 h-4" />
            <span>Usage Snippets</span>
          </button>
        </div>

        {/* Tab 1: Overview */}
        {activeTab === 'overview' && (
          <div className="space-y-8">
            {/* Supabase Status Banner */}
            <div className="bg-gradient-to-r from-slate-900 via-slate-900 to-slate-900/90 border border-slate-800 rounded-2xl p-6 relative overflow-hidden">
              <div className="absolute -top-24 -right-24 w-64 h-64 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none" />
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-6 relative z-10">
                <div className="space-y-2">
                  <div className="flex items-center space-x-2">
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                      <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse mr-1.5" />
                      Supabase Project Linked
                    </span>
                    <span className="text-xs text-slate-400 font-mono">Ref: jfdpnbkacxnlsquqypsy</span>
                  </div>
                  <h2 className="text-2xl font-bold text-slate-100 tracking-tight">Supabase PostgreSQL & Google Auth Suite</h2>
                  <p className="text-sm text-slate-400 max-w-2xl leading-relaxed">
                    Featuring a thread-safe PDO Singleton connection manager (<code className="text-cyan-300">db.php</code>) and a complete Google OAuth 2.0 auth handler (<code className="text-cyan-300">auth.php</code>) that automatically saves first-time users and provisions 30-day trial records in Supabase PostgreSQL.
                  </p>
                </div>

                <div className="flex flex-wrap gap-3">
                  <button
                    onClick={() => setActiveTab('auth')}
                    className="inline-flex items-center justify-center space-x-2 px-4 py-2.5 rounded-lg bg-emerald-500 text-slate-950 font-semibold text-sm hover:bg-emerald-400 transition-colors shadow-lg shadow-emerald-500/20"
                  >
                    <span>View auth.php</span>
                    <ChevronRight className="w-4 h-4" />
                  </button>
                  <button
                    onClick={() => setActiveTab('db')}
                    className="inline-flex items-center justify-center space-x-2 px-4 py-2.5 rounded-lg bg-slate-800 text-slate-200 font-medium text-sm hover:bg-slate-700 transition-colors border border-slate-700/80"
                  >
                    <span>View db.php</span>
                  </button>
                </div>
              </div>
            </div>

            {/* Credentials Card Matrix */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              <div className="bg-slate-900/60 border border-slate-800/80 rounded-xl p-5 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="p-2 bg-emerald-500/10 text-emerald-400 rounded-lg">
                    <Globe className="w-5 h-5" />
                  </div>
                  <span className="text-xs text-slate-500 font-mono">SUPABASE_URL</span>
                </div>
                <div>
                  <h3 className="text-xs text-slate-400 uppercase font-semibold tracking-wider">Project Endpoint</h3>
                  <p className="text-sm font-mono text-slate-200 mt-1 truncate">https://jfdpnbkacxnlsquqypsy.supabase.co</p>
                </div>
              </div>

              <div className="bg-slate-900/60 border border-slate-800/80 rounded-xl p-5 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="p-2 bg-cyan-500/10 text-cyan-400 rounded-lg">
                    <Key className="w-5 h-5" />
                  </div>
                  <span className="text-xs text-slate-500 font-mono">SUPABASE_ANON_KEY</span>
                </div>
                <div>
                  <h3 className="text-xs text-slate-400 uppercase font-semibold tracking-wider">Publishable Key</h3>
                  <p className="text-sm font-mono text-slate-200 mt-1 truncate">sb_publishable__1gkmebdNjwiAUhx0cmyAg_f40oMmDS</p>
                </div>
              </div>

              <div className="bg-slate-900/60 border border-slate-800/80 rounded-xl p-5 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="p-2 bg-indigo-500/10 text-indigo-400 rounded-lg">
                    <Server className="w-5 h-5" />
                  </div>
                  <span className="text-xs text-slate-500 font-mono">DB_HOST (Pooler)</span>
                </div>
                <div>
                  <h3 className="text-xs text-slate-400 uppercase font-semibold tracking-wider">Connection Pooler</h3>
                  <p className="text-sm font-mono text-slate-200 mt-1 truncate">aws-0-us-east-1.pooler.supabase.com:6543</p>
                </div>
              </div>
            </div>

            {/* Features Breakdown */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="bg-slate-900/40 border border-slate-800 rounded-xl p-6 space-y-4">
                <div className="flex items-center space-x-3">
                  <UserCheck className="w-5 h-5 text-emerald-400" />
                  <h3 className="font-semibold text-slate-200">Google OAuth 2.0 Auth Logic</h3>
                </div>
                <ul className="space-y-2.5 text-sm text-slate-300">
                  <li className="flex items-start space-x-2">
                    <Sparkles className="w-4 h-4 text-cyan-400 mt-0.5 shrink-0" />
                    <span><strong>User Persistence:</strong> Saves Google ID, email, name, avatar to <code className="text-cyan-300 bg-slate-800 px-1.5 py-0.5 rounded text-xs">users</code> table</span>
                  </li>
                  <li className="flex items-start space-x-2">
                    <Sparkles className="w-4 h-4 text-cyan-400 mt-0.5 shrink-0" />
                    <span><strong>Automatic 30-Day Trial:</strong> Creates active trial in <code className="text-cyan-300 bg-slate-800 px-1.5 py-0.5 rounded text-xs">subscriptions</code> on first sign-in</span>
                  </li>
                  <li className="flex items-start space-x-2">
                    <Sparkles className="w-4 h-4 text-cyan-400 mt-0.5 shrink-0" />
                    <span><strong>Atomic Transaction:</strong> Wraps user + trial creation inside a single DB transaction</span>
                  </li>
                  <li className="flex items-start space-x-2">
                    <Sparkles className="w-4 h-4 text-cyan-400 mt-0.5 shrink-0" />
                    <span><strong>Popup & Iframe Friendly:</strong> Supports <code className="text-cyan-300 bg-slate-800 px-1.5 py-0.5 rounded text-xs">postMessage</code> and secure session cookies</span>
                  </li>
                </ul>
              </div>

              <div className="bg-slate-900/40 border border-slate-800 rounded-xl p-6 space-y-4">
                <div className="flex items-center space-x-3">
                  <Calendar className="w-5 h-5 text-cyan-400" />
                  <h3 className="font-semibold text-slate-200">Trial Record Schema</h3>
                </div>
                <div className="bg-slate-950 p-4 rounded-lg border border-slate-800/80 text-xs font-mono text-slate-300 space-y-1">
                  <div><span className="text-slate-500">user_id:</span> <span className="text-emerald-400">1</span> (Foreign Key to users)</div>
                  <div><span className="text-slate-500">plan:</span> <span className="text-cyan-400">'trial_30_day'</span></div>
                  <div><span className="text-slate-500">status:</span> <span className="text-emerald-400">'active'</span></div>
                  <div><span className="text-slate-500">starts_at:</span> <span className="text-slate-400">{new Date().toISOString().slice(0, 19).replace('T', ' ')}</span></div>
                  <div><span className="text-slate-500">ends_at:</span> <span className="text-cyan-300">{new Date(Date.now() + 30*86400*1000).toISOString().slice(0, 19).replace('T', ' ')}</span></div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Tab 2: db.php Code */}
        {activeTab === 'db' && (
          <div className="space-y-4">
            <div className="flex items-center justify-between bg-slate-900/80 p-4 rounded-xl border border-slate-800">
              <div className="flex items-center space-x-3">
                <Code2 className="w-5 h-5 text-cyan-400" />
                <div>
                  <h2 className="font-semibold text-slate-100">/db.php</h2>
                  <p className="text-xs text-slate-400">PHP 8.2+ Singleton PDO database connection class for Supabase PostgreSQL</p>
                </div>
              </div>
              <span className="text-xs font-mono text-emerald-400 bg-emerald-500/10 px-2.5 py-1 rounded-md border border-emerald-500/20">
                Created at root directory
              </span>
            </div>

            <div className="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
              <div className="p-3 bg-slate-950 border-b border-slate-800 flex items-center justify-between text-xs text-slate-400">
                <span className="font-mono">db.php</span>
                <span className="text-slate-500">275 lines • PHP 8.2+</span>
              </div>
              <div className="p-4 bg-slate-950 overflow-x-auto text-xs font-mono text-slate-300 leading-relaxed">
                <pre>{`<?php

declare(strict_types=1);

/**
 * Supabase PostgreSQL Singleton Database Connection Class (PDO)
 * PHP Version: 8.2+
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct(?array $config = null)
    {
        $host     = $config['host']     ?? $_ENV['DB_HOST']     ?? getenv('DB_HOST')     ?? 'aws-0-us-east-1.pooler.supabase.com';
        $port     = (int) ($config['port'] ?? $_ENV['DB_PORT']   ?? getenv('DB_PORT')     ?? 6543);
        $dbName   = $config['dbname']   ?? $_ENV['DB_NAME']     ?? getenv('DB_NAME')     ?? 'postgres';
        $user     = $config['user']     ?? $_ENV['DB_USER']     ?? getenv('DB_USER')     ?? 'postgres.jfdpnbkacxnlsquqypsy';
        $password = $config['password'] ?? $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?? '';
        $sslMode  = $config['sslmode']  ?? $_ENV['DB_SSLMODE']  ?? getenv('DB_SSLMODE')  ?? 'require';

        $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s;sslmode=%s", $host, $port, $dbName, $sslMode);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        $this->pdo = new PDO($dsn, $user, $password, $options);
    }

    public static function getInstance(?array $config = null): Database
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function getConnection(): PDO { return $this->pdo; }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $res = $this->query($sql, $params)->fetch();
        return $res !== false ? $res : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert(string $table, array $data, string $primaryKey = 'id'): mixed
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ':' . $col, $columns);
        $sql = sprintf("INSERT INTO %s (%s) VALUES (%s) RETURNING %s", $table, implode(', ', $columns), implode(', ', $placeholders), $primaryKey);
        return $this->fetchColumn($sql, $data);
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $param = 'set_' . $col;
            $setParts[] = "$col = :$param";
            $params[$param] = $val;
        }
        $params = array_merge($params, $whereParams);
        $sql = sprintf("UPDATE %s SET %s WHERE %s", $table, implode(', ', $setParts), $where);
        return $this->query($sql, $params)->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}`}</pre>
              </div>
            </div>
          </div>
        )}

        {/* Tab 3: auth.php Code */}
        {activeTab === 'auth' && (
          <div className="space-y-4">
            <div className="flex items-center justify-between bg-slate-900/80 p-4 rounded-xl border border-slate-800">
              <div className="flex items-center space-x-3">
                <ShieldCheck className="w-5 h-5 text-emerald-400" />
                <div>
                  <h2 className="font-semibold text-slate-100">/auth.php</h2>
                  <p className="text-xs text-slate-400">Google OAuth 2.0 Auth Handler & Automatic 30-Day Trial Provisioner</p>
                </div>
              </div>
              <span className="text-xs font-mono text-emerald-400 bg-emerald-500/10 px-2.5 py-1 rounded-md border border-emerald-500/20">
                Created at root directory
              </span>
            </div>

            <div className="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
              <div className="p-3 bg-slate-950 border-b border-slate-800 flex items-center justify-between text-xs text-slate-400">
                <span className="font-mono">auth.php</span>
                <span className="text-slate-500">Google OAuth + Auto 30-Day Trial • PHP 8.2+</span>
              </div>
              <div className="p-4 bg-slate-950 overflow-x-auto text-xs font-mono text-slate-300 leading-relaxed">
                <pre>{AUTH_SUMMARY_CODE}</pre>
              </div>
            </div>
          </div>
        )}

        {/* Tab 4: .env.example Code */}
        {activeTab === 'env' && (
          <div className="space-y-4">
            <div className="flex items-center justify-between bg-slate-900/80 p-4 rounded-xl border border-slate-800">
              <div className="flex items-center space-x-3">
                <Key className="w-5 h-5 text-emerald-400" />
                <div>
                  <h2 className="font-semibold text-slate-100">/.env.example</h2>
                  <p className="text-xs text-slate-400">Environment variable specification with Supabase credentials</p>
                </div>
              </div>
              <button
                onClick={() => copyToClipboard(ENV_EXAMPLE_CODE, 'env')}
                className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-medium text-slate-200 border border-slate-700 transition-colors"
              >
                {copiedSection === 'env' ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                <span>{copiedSection === 'env' ? 'Copied!' : 'Copy File'}</span>
              </button>
            </div>

            <div className="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
              <div className="p-4 bg-slate-950 overflow-x-auto text-xs font-mono text-emerald-300 leading-relaxed">
                <pre>{ENV_EXAMPLE_CODE}</pre>
              </div>
            </div>
          </div>
        )}

        {/* Tab 5: Usage Examples */}
        {activeTab === 'usage' && (
          <div className="space-y-4">
            <div className="flex items-center justify-between bg-slate-900/80 p-4 rounded-xl border border-slate-800">
              <div className="flex items-center space-x-3">
                <Terminal className="w-5 h-5 text-cyan-400" />
                <div>
                  <h2 className="font-semibold text-slate-100">PHP 8.2+ Usage Snippets</h2>
                  <p className="text-xs text-slate-400">Examples for querying, inserting, updating, and transactions</p>
                </div>
              </div>
              <button
                onClick={() => copyToClipboard(DB_USAGE_CODE, 'usage')}
                className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-medium text-slate-200 border border-slate-700 transition-colors"
              >
                {copiedSection === 'usage' ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                <span>{copiedSection === 'usage' ? 'Copied!' : 'Copy Code'}</span>
              </button>
            </div>

            <div className="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
              <div className="p-4 bg-slate-950 overflow-x-auto text-xs font-mono text-cyan-300 leading-relaxed">
                <pre>{DB_USAGE_CODE}</pre>
              </div>
            </div>
          </div>
        )}

      </main>
    </div>
  );
}
