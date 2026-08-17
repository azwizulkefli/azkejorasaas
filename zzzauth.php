<?php

declare(strict_types=1);

/**
 * Google OAuth 2.0 Authentication & Trial Manager
 * 
 * PHP Version: 8.2+
 * Handles Google OAuth authorization flow, token exchange, profile retrieval,
 * automatic user registration, and 30-day trial subscription provisioning.
 */

require_once __DIR__ . '/db.php';

// Configure session options for secure iframe & cross-origin operation
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
    session_start();
}

class GoogleAuthHandler
{
    private Database $db;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->db = Database::getInstance();

        $this->clientId     = $_ENV['GOOGLE_CLIENT_ID']     ?? getenv('GOOGLE_CLIENT_ID')     ?? '';
        $this->clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?? '';
        
        $appUrl = $_ENV['APP_URL'] ?? getenv('APP_URL') ?? 'http://localhost:3000';
        $defaultRedirect = rtrim($appUrl, '/') . '/auth.php?action=callback';

        $this->redirectUri  = $_ENV['GOOGLE_REDIRECT_URI']  ?? getenv('GOOGLE_REDIRECT_URI')  ?? $defaultRedirect;
    }

    /**
     * Generate the Google OAuth 2.0 authorization URL with state CSRF protection.
     *
     * @return string
     */
    public function getAuthUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth2_state'] = $state;

        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid profile email',
            'state'         => $state,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token.
     *
     * @param string $code
     * @return array<string, mixed>
     * @throws Exception
     */
    public function exchangeCodeForToken(string $code): array
    {
        $tokenUrl = 'https://oauth2.googleapis.com/token';

        $postData = [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $tokenUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            throw new Exception("Failed to exchange OAuth code for token: " . ($error ?: $response));
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new Exception("Invalid token response from Google API.");
        }

        return $data;
    }

    /**
     * Fetch Google user profile using access token.
     *
     * @param string $accessToken
     * @return array<string, mixed>
     * @throws Exception
     */
    public function getUserProfile(string $accessToken): array
    {
        $userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $userInfoUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            throw new Exception("Failed to fetch Google user profile: " . ($error ?: $response));
        }

        $profile = json_decode((string) $response, true);
        if (!is_array($profile) || empty($profile['sub'])) {
            throw new Exception("Invalid user profile received from Google API.");
        }

        return $profile;
    }

    /**
     * Process Google User sign-in.
     * Creates new user & 30-day trial subscription if first time sign-in.
     * Updates profile if user already exists.
     *
     * @param array<string, mixed> $profile Google profile array (sub, email, name, picture)
     * @return array<string, mixed> Combined user & subscription array
     */
    public function handleUserAuth(array $profile): array
    {
        $googleId = (string) $profile['sub'];
        $email    = (string) ($profile['email'] ?? '');
        $name     = (string) ($profile['name'] ?? 'User');
        $avatar   = (string) ($profile['picture'] ?? '');
        $now      = date('Y-m-d H:i:s');

        // Check if user exists by Google ID or Email
        $existingUser = $this->db->fetch(
            "SELECT * FROM users WHERE google_id = :google_id OR email = :email LIMIT 1",
            ['google_id' => $googleId, 'email' => $email]
        );

        if ($existingUser) {
            // Existing user: update details & last login timestamp
            $this->db->update(
                'users',
                [
                    'google_id'     => $googleId,
                    'name'          => $name,
                    'avatar_url'    => $avatar,
                    'updated_at'    => $now,
                    'last_login_at' => $now,
                ],
                'id = :id',
                ['id' => $existingUser['id']]
            );

            // Fetch active or latest subscription details
            $subscription = $this->db->fetch(
                "SELECT * FROM subscriptions WHERE user_id = :user_id ORDER BY id DESC LIMIT 1",
                ['user_id' => $existingUser['id']]
            );

            $userRecord = array_merge($existingUser, [
                'name'          => $name,
                'avatar_url'    => $avatar,
                'last_login_at' => $now,
            ]);

            return [
                'user'         => $userRecord,
                'subscription' => $subscription,
                'is_new_user'  => false,
            ];
        }

        // New User First-Time Sign-In: Run atomic transaction
        return $this->db->transaction(function (Database $db) use ($googleId, $email, $name, $avatar, $now) {
            // 1. Insert User Record
            $userId = $db->insert('users', [
                'google_id'     => $googleId,
                'email'         => $email,
                'name'          => $name,
                'avatar_url'    => $avatar,
                'created_at'    => $now,
                'updated_at'    => $now,
                'last_login_at' => $now,
            ], 'id');

            // 2. Calculate 30-Day Trial Expiration Date
            $startsAt = $now;
            $endsAt   = date('Y-m-d H:i:s', strtotime('+30 days'));

            // 3. Insert 30-Day Trial Record in Subscriptions Table
            $subscriptionId = $db->insert('subscriptions', [
                'user_id'       => $userId,
                'plan'          => 'trial_30_day',
                'status'        => 'active',
                'starts_at'     => $startsAt,
                'ends_at'       => $endsAt,
                'created_at'    => $now,
                'updated_at'    => $now,
            ], 'id');

            $newUser = $db->fetch("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
            $newSub  = $db->fetch("SELECT * FROM subscriptions WHERE id = :id", ['id' => $subscriptionId]);

            return [
                'user'         => $newUser,
                'subscription' => $newSub,
                'is_new_user'  => true,
            ];
        });
    }
}

// ============================================================================
// ROUTE & REQUEST CONTROLLER LOGIC
// ============================================================================

$action = $_GET['action'] ?? ($_POST['action'] ?? 'default');

// Helper for JSON responses
function sendJsonResponse(array $data, int $statusCode = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $authHandler = new GoogleAuthHandler();

    switch ($action) {

        // Endpoint: /auth.php?action=url
        // Returns the Google OAuth 2.0 Authorization URL for popup or direct navigation
        case 'url':
            sendJsonResponse([
                'status' => 'success',
                'url'    => $authHandler->getAuthUrl(),
            ]);
            break;

        // Endpoint: /auth.php?action=login
        // Directly redirects the browser to Google Consent screen
        case 'login':
            $url = $authHandler->getAuthUrl();
            header("Location: " . $url);
            exit;

        // Endpoint: /auth.php?action=callback (Google OAuth Redirect Target)
        case 'callback':
            if (!empty($_GET['error'])) {
                throw new Exception("Google OAuth returned an error: " . htmlspecialchars($_GET['error']));
            }

            $code = $_GET['code'] ?? '';
            if (empty($code)) {
                throw new Exception("Authorization code missing from Google OAuth callback.");
            }

            // Verify state parameter for CSRF prevention
            $state = $_GET['state'] ?? '';
            if (!empty($_SESSION['oauth2_state']) && hash_equals($_SESSION['oauth2_state'], $state) === false) {
                throw new Exception("Invalid OAuth state parameter (CSRF detected).");
            }
            unset($_SESSION['oauth2_state']);

            // Exchange code for tokens and retrieve Google profile
            $tokenData = $authHandler->exchangeCodeForToken($code);
            $profile   = $authHandler->getUserProfile($tokenData['access_token']);

            // Create/update user & auto-provision 30-day trial subscription
            $authResult = $authHandler->handleUserAuth($profile);

            // Store in session
            $_SESSION['user']         = $authResult['user'];
            $_SESSION['subscription'] = $authResult['subscription'];
            $_SESSION['logged_in']    = true;

            // Send postMessage script for popup windows, or standard redirect fallback
            echo '<!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Authentication Successful</title>
                <style>
                    body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                    .card { background: #1e293b; padding: 2rem; border-radius: 1rem; border: 1px solid #334155; text-align: center; max-width: 400px; }
                    .badge { background: #10b98120; color: #34d399; border: 1px solid #10b98140; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; display: inline-block; margin-bottom: 1rem; }
                </style>
            </head>
            <body>
                <div class="card">
                    <div class="badge">' . ($authResult['is_new_user'] ? '30-Day Free Trial Activated 🎉' : 'Welcome Back!') . '</div>
                    <h2>Authentication Successful</h2>
                    <p>Welcome, ' . htmlspecialchars($authResult['user']['name']) . '!</p>
                    <p style="font-size: 0.85rem; color: #94a3b8;">This window will close automatically...</p>
                </div>
                <script>
                    const authData = ' . json_encode($authResult, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
                    if (window.opener) {
                        window.opener.postMessage({ type: "OAUTH_AUTH_SUCCESS", data: authData }, "*");
                        setTimeout(() => window.close(), 1200);
                    } else {
                        setTimeout(() => { window.location.href = "/"; }, 1500);
                    }
                </script>
            </body>
            </html>';
            exit;

        // Endpoint: /auth.php?action=me
        // Returns the current logged-in user profile & active trial subscription status
        case 'me':
            if (empty($_SESSION['logged_in']) || empty($_SESSION['user'])) {
                sendJsonResponse([
                    'authenticated' => false,
                    'user'          => null,
                    'subscription'  => null,
                ], 200);
            }

            sendJsonResponse([
                'authenticated' => true,
                'user'          => $_SESSION['user'],
                'subscription'  => $_SESSION['subscription'] ?? null,
            ]);
            break;

        // Endpoint: /auth.php?action=logout
        case 'logout':
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();

            if (isset($_GET['redirect'])) {
                header("Location: /");
                exit;
            }

            sendJsonResponse([
                'status'  => 'success',
                'message' => 'Logged out successfully.',
            ]);
            break;

        default:
            sendJsonResponse([
                'status'  => 'online',
                'service' => 'Google OAuth 2.0 & Trial Provisioning Service',
                'endpoints' => [
                    'GET /auth.php?action=url'      => 'Get Google OAuth Authorization URL (JSON)',
                    'GET /auth.php?action=login'    => 'Redirect directly to Google Login',
                    'GET /auth.php?action=callback' => 'Google OAuth Callback Target',
                    'GET /auth.php?action=me'       => 'Check current user session & trial status',
                    'POST/GET /auth.php?action=logout' => 'Destroy session',
                ]
            ]);
            break;
    }
} catch (Exception $e) {
    sendJsonResponse([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], 500);
}
