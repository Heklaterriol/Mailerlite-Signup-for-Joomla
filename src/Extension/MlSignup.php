<?php

/**
 * @package     MlSignup
 * @license     GNU General Public License version 2 or later
 */

namespace MlSignup\Plugin\System\MlSignup\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Joomla\Input\Input;

/**
 * Self-hosted MailerLite signup form.
 *
 * - Shortcode {mlsignup} renders the form (no third-party scripts).
 * - The form posts to index.php (mlsignup=...) which is handled in onAfterInitialise,
 *   so it also works with page cache and without a CSRF token in the cached HTML.
 * - Spam protection: honeypot + signed, single-use proof-of-work challenge + rate limits
 *   (per IP, per attempt, per e-mail address).
 */
final class MlSignup extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    private const API_URL       = 'https://connect.mailerlite.com/api/subscribers';
    private const MAX_NUMBER    = 30000;   // proof-of-work difficulty (upper bound of the secret number)
    private const CHALLENGE_TTL = 600;     // seconds a challenge stays valid
    private const RATE_WINDOW   = 3600;    // seconds
    private const ATTEMPT_FACTOR = 4;      // attempts per IP and hour = signup limit * this factor
    private const EMAIL_LIMIT   = 3;       // signups per e-mail address ...
    private const EMAIL_WINDOW  = 86400;   // ... per this many seconds
    private const MAX_PAYLOAD   = 2048;    // max length of the encoded challenge payload

    private static int $formCount = 0;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onContentPrepare'  => 'onContentPrepare',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Form rendering
     * ------------------------------------------------------------------ */

    public function onContentPrepare(ContentPrepareEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $item = $event->getItem();

        if (!\is_object($item) || !isset($item->text) || !\is_string($item->text) || !str_contains($item->text, '{mlsignup}')) {
            return;
        }

        $item->text = str_replace(
            '{mlsignup}',
            str_starts_with($event->getContext(), 'com_finder.') ? '' : $this->renderForm(),
            $item->text
        );
    }

    private function renderForm(): string
    {
        $app = $this->getApplication();
        $doc = $app->getDocument();

        if ($doc instanceof HtmlDocument) {
            $wa = $doc->getWebAssetManager();

            // Plugins' joomla.asset.json files are not loaded automatically (unlike components/modules).
            $wa->getRegistry()->addExtensionRegistryFile('plg_system_mlsignup');
            $wa->useScript('plg_system_mlsignup.form')->useStyle('plg_system_mlsignup.form');
        }

        $displayData = [
            'uid'        => 'mlsignup' . (++self::$formCount),
            'endpoint'   => Uri::base() . 'index.php',
            'showName'   => (bool) $this->params->get('show_name', 1),
            'privacyUrl' => (string) $this->params->get('privacy_url', ''),
        ];

        ob_start();
        include PluginHelper::getLayoutPath('system', 'mlsignup');

        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ *
     * Endpoint
     * ------------------------------------------------------------------ */

    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input = $app->getInput();

        if ($input->getMethod() !== 'POST') {
            return;
        }

        $action = $input->post->getCmd('mlsignup', '');

        if ($action === '') {
            return;
        }

        if (trim((string) $this->params->get('api_key', '')) === '') {
            $this->respond(['success' => false, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ERR_GENERIC')], 503);
        }

        match ($action) {
            'challenge' => $this->respond($this->createChallenge()),
            'subscribe' => $this->handleSubscribe($input),
            default     => $this->respond(['success' => false], 400),
        };
    }

    private function handleSubscribe(Input $input): never
    {
        // Honeypot: bots fill every field. Pretend success, do nothing.
        if (trim((string) $input->post->getString('website', '')) !== '') {
            $this->respond(['success' => true, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_SUCCESS')]);
        }

        $email = trim((string) $input->post->get('email', '', 'string'));
        $name  = trim((string) preg_replace('/[\p{C}<>]/u', '', (string) $input->post->get('name', '', 'string')));
        $name  = mb_substr($name, 0, 100);

        if ($email === '' || \strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(['success' => false, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ERR_EMAIL')], 422);
        }

        if (!$input->post->getBool('consent', false)) {
            $this->respond(['success' => false, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ERR_CONSENT')], 422);
        }

        $ip    = (string) $input->server->get('REMOTE_ADDR', '', 'string');
        $limit = max(1, (int) $this->params->get('rate_limit', 5));

        $this->cleanup();

        // Generous limit on every attempt that gets this far, checked BEFORE any state is created
        // for the challenge, so junk submissions cannot pile up files.
        if ($this->hit('a_' . $this->hashId($ip), $limit * self::ATTEMPT_FACTOR, self::RATE_WINDOW)) {
            $this->respond(['success' => false, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ERR_RATE')], 429);
        }

        if (!$this->verifyChallenge((string) $input->post->get('payload', '', 'raw'))) {
            $this->respond(['success' => false, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ERR_CHALLENGE')], 400);
        }

        // Strict limit on signups per IP.
        if ($this->hit('r_' . $this->hashId($ip), $limit, self::RATE_WINDOW)) {
            $this->respond(['success' => false, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ERR_RATE')], 429);
        }

        // Optional: tell people who are already subscribed (see plugin setting "show_existing").
        $existing = $this->checkExisting($email);

        if ($existing === 'already') {
            $this->respond(['success' => true, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ALREADY')]);
        }

        // Limit per e-mail address (stored only as an HMAC): stops confirmation-mail flooding of one
        // address from many IPs. Answer is the normal success message, nothing is sent.
        $emailKey = 'e_' . $this->hashId(mb_strtolower($email));

        if ($this->hit($emailKey, self::EMAIL_LIMIT, self::EMAIL_WINDOW)) {
            $this->respond(['success' => true, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_SUCCESS')]);
        }

        $code = $this->callMailerLite($email, $name);

        if ($code === 200 || $code === 201) {
            // Without the optional lookup the message is the same for new and existing subscribers
            // (no account enumeration). An already active subscriber who only got added to the
            // group has no confirmation e-mail coming, so the text must not promise one.
            $this->respond([
                'success' => true,
                'message' => Text::_($existing === 'added' ? 'PLG_SYSTEM_MLSIGNUP_ADDED' : 'PLG_SYSTEM_MLSIGNUP_SUCCESS'),
            ]);
        }

        // Nothing was sent: give the attempt back, so a temporary MailerLite problem does not use up
        // the quota of the address (otherwise the visitor would later get a success message without a mail).
        $this->unhit($emailKey, self::EMAIL_WINDOW);

        if ($code === 422) {
            // MailerLite refused the address (details are in the log).
            $this->respond(['success' => false, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ERR_REJECTED')], 422);
        }

        $this->respond(['success' => false, 'message' => Text::_('PLG_SYSTEM_MLSIGNUP_ERR_GENERIC')], 502);
    }

    private function groupId(): string
    {
        $groupId = trim((string) $this->params->get('group_id', ''));

        return $groupId === '0' ? '' : $groupId;
    }

    private function apiHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . trim((string) $this->params->get('api_key', '')),
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Only used when the setting "show_existing" is on.
     *
     * @return string 'already' = active subscriber (and in the configured group, if any),
     *                'added'   = active subscriber who is not yet in the configured group,
     *                ''        = new, not active, unknown or lookup failed: continue as usual.
     */
    private function checkExisting(string $email): string
    {
        if (!(int) $this->params->get('show_existing', 0)) {
            return '';
        }

        $subscriber = $this->lookupSubscriber($email);

        if ($subscriber === null || $subscriber['status'] !== 'active') {
            return '';
        }

        $groupId = $this->groupId();

        return ($groupId === '' || \in_array($groupId, $subscriber['groups'], true)) ? 'already' : 'added';
    }

    /** @return array{status: string, groups: string[]}|null null if not found or the lookup failed */
    private function lookupSubscriber(string $email): ?array
    {
        try {
            $response = HttpFactory::getHttp()->get(self::API_URL . '/' . rawurlencode($email), $this->apiHeaders(), 10);
            $code     = (int) $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->log('MailerLite lookup failed: ' . $e::class);

            return null;
        }

        if ($code === 404) {
            return null;
        }

        if ($code !== 200) {
            $this->log('MailerLite lookup responded with HTTP ' . $code);

            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        $data = \is_array($body) && isset($body['data']) && \is_array($body['data']) ? $body['data'] : null;

        if ($data === null) {
            return null;
        }

        $groups = [];

        foreach ((array) ($data['groups'] ?? []) as $group) {
            if (\is_array($group) && isset($group['id'])) {
                $groups[] = (string) $group['id'];
            }
        }

        return ['status' => (string) ($data['status'] ?? ''), 'groups' => $groups];
    }

    private function callMailerLite(string $email, string $name): int
    {
        $payload = ['email' => $email];

        if ($name !== '') {
            $payload['fields'] = ['name' => $name];
        }

        $groupId = $this->groupId();

        if ($groupId !== '') {
            $payload['groups'] = [$groupId];
        }

        try {
            $response = HttpFactory::getHttp()->post(
                self::API_URL,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $this->apiHeaders() + ['Content-Type' => 'application/json'],
                10
            );

            $code = (int) $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->log('MailerLite request failed: ' . $e::class);

            return 0;
        }

        if ($code !== 200 && $code !== 201) {
            $body = json_decode((string) $response->getBody(), true);
            $msg  = \is_array($body) && isset($body['message']) && \is_string($body['message']) ? mb_substr($body['message'], 0, 200) : '';

            // Validation errors (HTTP 422) carry the actual reason in "errors", e.g. {"email": ["..."]}.
            $details = [];

            if (\is_array($body) && isset($body['errors']) && \is_array($body['errors'])) {
                foreach ($body['errors'] as $field => $messages) {
                    $details[] = $field . ': ' . implode(' ', array_map('strval', array_filter((array) $messages, 'is_scalar')));
                }
            }

            $this->log(
                'MailerLite responded with HTTP ' . $code . ($msg !== '' ? ': ' . $msg : '')
                . ($details !== [] ? ' [' . mb_substr(implode('; ', $details), 0, 500) . ']' : '')
            );
        }

        return $code;
    }

    /* ------------------------------------------------------------------ *
     * Proof-of-work challenge (signed, single use)
     * ------------------------------------------------------------------ */

    private function key(): string
    {
        return hash_hmac('sha256', 'mlsignup-v1', (string) $this->getApplication()->get('secret'));
    }

    private function createChallenge(): array
    {
        $salt      = bin2hex(random_bytes(12)) . '?expires=' . (time() + self::CHALLENGE_TTL);
        $number    = random_int(0, self::MAX_NUMBER);
        $challenge = hash('sha256', $salt . $number);

        return [
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'salt'      => $salt,
            'signature' => hash_hmac('sha256', $challenge, $this->key()),
            'maxnumber' => self::MAX_NUMBER,
        ];
    }

    private function verifyChallenge(string $encoded): bool
    {
        if ($encoded === '' || \strlen($encoded) > self::MAX_PAYLOAD) {
            return false;
        }

        $json = base64_decode($encoded, true);
        $data = \is_string($json) ? json_decode($json, true) : null;

        if (!\is_array($data)) {
            return false;
        }

        $challenge = $data['challenge'] ?? null;
        $salt      = $data['salt'] ?? null;
        $signature = $data['signature'] ?? null;
        $number    = $data['number'] ?? null;

        if (!\is_string($challenge) || !\is_string($salt) || !\is_string($signature) || !\is_int($number)
            || $number < 0 || $number > self::MAX_NUMBER) {
            return false;
        }

        if (!preg_match('/\?expires=(\d{1,12})$/', $salt, $m) || (int) $m[1] < time()) {
            return false;
        }

        if (!hash_equals(hash_hmac('sha256', $challenge, $this->key()), $signature)) {
            return false;
        }

        if (!hash_equals($challenge, hash('sha256', $salt . $number))) {
            return false;
        }

        // Single use: atomically create a marker file; fails if it already exists.
        $file   = $this->storage() . '/u_' . substr(hash('sha256', $challenge), 0, 40);
        $handle = @fopen($file, 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    /* ------------------------------------------------------------------ *
     * Rate limits (hashed identifiers only, stored in the cache folder)
     * ------------------------------------------------------------------ */

    private function storage(): string
    {
        $dir = JPATH_CACHE . '/plg_system_mlsignup';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Keep the folder from being served over HTTP.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/index.html', '<!DOCTYPE html><title></title>');
            @file_put_contents(
                $dir . '/.htaccess',
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
            );
        }

        return $dir;
    }

    private function hashId(string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->key()), 0, 40);
    }

    /**
     * Counts one hit for $name and returns true when the limit is exceeded.
     * The whole read-modify-write runs under an exclusive lock, so parallel requests are counted exactly.
     * If the counter cannot be stored, the request is refused (fail closed).
     */
    private function hit(string $name, int $limit, int $window): bool
    {
        $handle = @fopen($this->storage() . '/' . $name, 'c+');

        if ($handle === false) {
            return true;
        }

        $exceeded = true;

        if (flock($handle, LOCK_EX)) {
            $now  = time();
            $data = json_decode((string) stream_get_contents($handle), true);

            if (!\is_array($data) || !isset($data['t'], $data['n']) || ($now - (int) $data['t']) >= $window) {
                $data = ['t' => $now, 'n' => 0];
            }

            $data['n'] = (int) $data['n'] + 1;

            if (ftruncate($handle, 0) && rewind($handle) && fwrite($handle, (string) json_encode($data)) !== false) {
                fflush($handle);
                $exceeded = $data['n'] > $limit;
            }

            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return $exceeded;
    }

    /**
     * Takes back one hit counted by hit(), under the same lock. Does nothing if the counter does not
     * exist, is already zero or belongs to an expired window.
     */
    private function unhit(string $name, int $window): void
    {
        $handle = @fopen($this->storage() . '/' . $name, 'r+');

        if ($handle === false) {
            return;
        }

        if (flock($handle, LOCK_EX)) {
            $data = json_decode((string) stream_get_contents($handle), true);

            if (\is_array($data) && isset($data['t'], $data['n']) && (time() - (int) $data['t']) < $window && (int) $data['n'] > 0) {
                $data['n'] = (int) $data['n'] - 1;

                if (ftruncate($handle, 0) && rewind($handle)) {
                    fwrite($handle, (string) json_encode($data));
                    fflush($handle);
                }
            }

            flock($handle, LOCK_UN);
        }

        fclose($handle);
    }

    /**
     * Removes expired files (about once per 20 signup requests, at most 1000 files per run).
     * A challenge marker is only needed while its challenge is still valid.
     */
    private function cleanup(): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }

        $now     = time();
        $removed = 0;

        try {
            foreach (new \DirectoryIterator($this->storage()) as $file) {
                if (!$file->isFile() || $removed >= 1000) {
                    continue;
                }

                $name = $file->getFilename();
                $ttl  = match (substr($name, 0, 2)) {
                    'u_'       => self::CHALLENGE_TTL,
                    'a_', 'r_' => self::RATE_WINDOW,
                    'e_'       => self::EMAIL_WINDOW,
                    default    => null,
                };

                if ($ttl !== null && $file->getMTime() < $now - $ttl - 60 && @unlink($file->getPathname())) {
                    $removed++;
                }
            }
        } catch (\Throwable $e) {
            // Cleanup is best effort.
        }
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function log(string $message): void
    {
        // The text may come from an external service: no control characters or line breaks in the log.
        $message = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $message));

        Log::addLogger(['text_file' => 'mlsignup.php'], Log::ALL, ['mlsignup']);
        Log::add($message, Log::ERROR, 'mlsignup');
    }

    private function respond(array $data, int $status = 200): never
    {
        $app = $this->getApplication();

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store, max-age=0', true);
        $app->setHeader('X-Robots-Tag', 'noindex', true);
        $app->setHeader('status', (string) $status, true);
        $app->sendHeaders();

        echo json_encode($data, JSON_UNESCAPED_UNICODE);

        $app->close();
        exit;
    }
}
