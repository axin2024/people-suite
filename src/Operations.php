<?php

declare(strict_types=1);

namespace PeopleSuite;

final class Operations
{
    public function __construct(private readonly Database $db)
    {
    }

    public function once(Access $actor, int $companyId, string $action, string $key, array $input, callable $work): array
    {
        $key = Input::code($key, 'request_key');
        $scope = [$actor->groupId, $companyId, $actor->accountId, $action, $key];
        $digest = hash('sha256', json_encode(self::canonical($input), JSON_THROW_ON_ERROR));
        return $this->db->transaction(function () use ($scope, $digest, $work): array {
            $this->db->run(
                'INSERT INTO operation_receipts (group_id, company_id, actor_id, action, request_key, request_digest)
                 VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE request_key=operation_receipts.request_key',
                [...$scope, $digest],
            );
            $receipt = $this->db->row(
                'SELECT request_digest, response FROM operation_receipts
                 WHERE group_id=? AND company_id=? AND actor_id=? AND action=? AND request_key=? FOR UPDATE',
                $scope,
            );
            if (!hash_equals($receipt['request_digest'], $digest)) {
                throw new BusinessError(409, 'request_key_conflict');
            }
            if ($receipt['response'] !== null) {
                return json_decode($receipt['response'], true, flags: JSON_THROW_ON_ERROR);
            }
            $result = $work();
            $this->db->run(
                'UPDATE operation_receipts SET response=?
                 WHERE group_id=? AND company_id=? AND actor_id=? AND action=? AND request_key=?',
                [json_encode($result, JSON_THROW_ON_ERROR), ...$scope],
            );
            return $result;
        });
    }

    public function audit(
        Access $actor,
        int $companyId,
        string $type,
        int $objectId,
        string $action,
        array $facts = [],
    ): void {
        $this->db->run(
            'INSERT INTO audit_events (group_id, company_id, actor_id, object_type, object_id, action, facts)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$actor->groupId, $companyId, $actor->accountId, $type, $objectId, $action, json_encode($facts, JSON_THROW_ON_ERROR)],
        );
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        return $value;
    }
}
