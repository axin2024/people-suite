<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Accounts
{
    public function __construct(private readonly Database $db)
    {
    }

    public function createGroup(string $code, string $name, string $email, string $displayName, string $password): array
    {
        $code = Input::code($code, 'group');
        $name = Input::text($name, 'name', 160);
        return $this->db->transaction(function () use ($code, $name, $email, $displayName, $password): array {
            $groupId = $this->db->insert('INSERT INTO hcm_groups (code, name) VALUES (?, ?)', [$code, $name]);
            $accountId = $this->createAccount($groupId, $email, $displayName, $password);
            $this->db->run('INSERT INTO group_administrators (group_id, account_id) VALUES (?, ?)', [
                $groupId, $accountId,
            ]);
            return ['group_id' => $groupId, 'account_id' => $accountId];
        });
    }

    public function createAccount(int $groupId, string $email, string $displayName, string $password): int
    {
        $email = self::email($email);
        $displayName = Input::text($displayName, 'display_name', 160);
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new BusinessError(422, 'invalid_password_length');
        }
        return $this->db->insert(
            'INSERT INTO accounts (group_id, email, display_name, password_hash) VALUES (?, ?, ?, ?)',
            [$groupId, $email, $displayName, password_hash($password, PASSWORD_DEFAULT)],
        );
    }

    public function authenticate(string $group, string $email, string $password): Access
    {
        $group = Input::code($group, 'group');
        $email = self::email($email);
        if (strlen($password) > 72 || str_contains($password, "\0")) {
            throw new BusinessError(401, 'invalid_credentials');
        }
        $row = $this->db->row(
            'SELECT a.id, a.group_id, a.enabled, a.password_hash
             FROM accounts a JOIN hcm_groups g ON g.id=a.group_id WHERE g.code=? AND a.email=?',
            [$group, $email],
        );
        $hash = $row['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password, $hash) || $row === null || !(bool) $row['enabled']) {
            throw new BusinessError(401, 'invalid_credentials');
        }
        return new Access($this->db, (int) $row['group_id'], (int) $row['id']);
    }

    public function session(int $groupId, int $accountId): Access
    {
        $row = $this->db->row('SELECT id FROM accounts WHERE group_id=? AND id=? AND enabled=1', [
            $groupId, $accountId,
        ]);
        if ($row === null) {
            throw new BusinessError(401, 'inactive_session');
        }
        return new Access($this->db, $groupId, $accountId);
    }

    private static function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new BusinessError(422, 'invalid_email');
        }
        return $email;
    }
}
