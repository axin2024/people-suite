<?php

declare(strict_types=1);

namespace PeopleSuite;

final class PayrollInquiries
{
    private const COLUMNS = 'id, payslip_id, opened_by, reference, category, question, state, version,
        opened_at, answered_by, decision_ref, answer, answered_at, acknowledged_at';

    public function __construct(
        private readonly Database $db,
        private readonly Operations $operations,
        private readonly Payroll $payroll,
        private readonly WorkforceAccess $workforce,
    ) {
    }

    public function open(Access $actor, int $company, int $payslip, string $key, array $input): array
    {
        $actor->company($company, 'employee');
        $employment = (int) $this->payroll->publishedSummary($actor, $company, $payslip)['employment_id'];
        $reference = Input::code($input['reference'] ?? null, 'reference');
        $category = $input['category'] ?? null;
        if (!in_array($category, ['base_pay', 'attendance', 'withholding', 'adjustment', 'other'], true)) {
            throw new BusinessError(422, 'invalid_inquiry_category');
        }
        $question = Input::text($input['question'] ?? null, 'question', 1200);
        return $this->operations->once($actor, $company, 'payroll.inquiry_opened', $key, [
            'payslip_id' => $payslip, 'reference' => $reference, 'category' => $category, 'question' => $question,
        ], function () use ($actor, $company, $payslip, $employment, $reference, $category, $question): array {
            $scope = [$actor->groupId, $company];
            $this->workforce->lockEmployment($actor, $company, $employment);
            $actor->company($company, 'employee');
            $this->db->row('SELECT id FROM payslips WHERE group_id=? AND company_id=? AND id=? FOR UPDATE', [...$scope, $payslip]);
            $previous = $this->db->row(
                'SELECT id, category, question FROM payslip_inquiries
                 WHERE group_id=? AND company_id=? AND payslip_id=? AND opened_by=? AND reference=?',
                [...$scope, $payslip, $actor->accountId, $reference],
            );
            if ($previous !== null) {
                if ($previous['category'] !== $category || $previous['question'] !== $question) {
                    throw new BusinessError(409, 'inquiry_reference_conflict');
                }
                return self::openedReceipt((int) $previous['id'], $payslip, $reference);
            }
            $this->workforce->requireNewWorkAllowed($actor, $company, $employment);
            $id = $this->db->insert(
                'INSERT INTO payslip_inquiries (group_id, company_id, payslip_id, opened_by, reference, category, question)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [...$scope, $payslip, $actor->accountId, $reference, $category, $question],
            );
            $this->operations->audit($actor, $company, 'payslip_inquiry', $id, 'payroll.inquiry_opened', [
                'payslip_id' => $payslip, 'category' => $category, 'reference' => $reference,
            ]);
            return self::openedReceipt($id, $payslip, $reference);
        });
    }

    public function forEmployee(Access $actor, int $company, int $payslip, int $after): array
    {
        $this->payroll->publishedSummary($actor, $company, $payslip);
        $rows = $this->db->rows(
            'SELECT ' . self::COLUMNS . ' FROM payslip_inquiries
             WHERE group_id=? AND company_id=? AND payslip_id=? AND opened_by=? AND id>? ORDER BY id LIMIT 100',
            [$actor->groupId, $company, $payslip, $actor->accountId, $after],
        );
        foreach ($rows as &$row) {
            unset($row['opened_by'], $row['answered_by']);
        }
        unset($row);
        return $rows;
    }

    public function reviewQueue(Access $actor, int $company, int $after, mixed $state): array
    {
        $actor->company($company, 'payroll_reviewer');
        if ($state !== null && !in_array($state, ['open', 'answered', 'acknowledged'], true)) {
            throw new BusinessError(422, 'invalid_inquiry_state');
        }
        $parameters = [$actor->groupId, $company, $after];
        $filter = '';
        if ($state !== null) {
            $filter = ' AND state=?';
            $parameters[] = $state;
        }
        return $this->db->rows(
            'SELECT ' . self::COLUMNS . ' FROM payslip_inquiries WHERE group_id=? AND company_id=? AND id>?'
            . $filter . ' ORDER BY id LIMIT 100',
            $parameters,
        );
    }

    public function answer(Access $actor, int $company, int $id, string $key, array $input): array
    {
        $actor->company($company, 'payroll_reviewer');
        $version = Input::integer($input['version'] ?? null, 'version');
        $reference = Input::code($input['decision_ref'] ?? null, 'decision_ref');
        $answer = Input::text($input['answer'] ?? null, 'answer', 1200);
        return $this->operations->once($actor, $company, 'payroll.inquiry_answered', $key, [
            'inquiry_id' => $id, 'version' => $version, 'decision_ref' => $reference, 'answer' => $answer,
        ], function () use ($actor, $company, $id, $version, $reference, $answer): array {
            $scope = [$actor->groupId, $company, $id];
            $row = $this->lockedInquiry($scope);
            if ((int) $row['opened_by'] === $actor->accountId) {
                throw new BusinessError(403, 'independent_inquiry_reviewer_required');
            }
            $this->requireState($row, 'open', $version);
            $this->db->run(
                "UPDATE payslip_inquiries SET state='answered', version=2, answered_by=?, decision_ref=?, answer=?,
                    answered_at=CURRENT_TIMESTAMP(6) WHERE group_id=? AND company_id=? AND id=?",
                [$actor->accountId, $reference, $answer, ...$scope],
            );
            $this->operations->audit($actor, $company, 'payslip_inquiry', $id, 'payroll.inquiry_answered', ['decision_ref' => $reference]);
            return ['inquiry_id' => $id, 'state' => 'answered', 'version' => 2, 'decision_ref' => $reference];
        });
    }

    public function acknowledge(Access $actor, int $company, int $id, string $key, array $input): array
    {
        $actor->company($company, 'employee');
        $version = Input::integer($input['version'] ?? null, 'version');
        return $this->operations->once($actor, $company, 'payroll.inquiry_acknowledged', $key, [
            'inquiry_id' => $id, 'version' => $version,
        ], function () use ($actor, $company, $id, $version): array {
            $scope = [$actor->groupId, $company, $id];
            $row = $this->lockedInquiry($scope);
            if ((int) $row['opened_by'] !== $actor->accountId) {
                throw new BusinessError(404, 'payslip_inquiry_not_found');
            }
            $this->payroll->publishedSummary($actor, $company, (int) $row['payslip_id']);
            $this->requireState($row, 'answered', $version);
            $this->db->run(
                "UPDATE payslip_inquiries SET state='acknowledged', version=3, acknowledged_at=CURRENT_TIMESTAMP(6)
                 WHERE group_id=? AND company_id=? AND id=?",
                $scope,
            );
            $this->operations->audit($actor, $company, 'payslip_inquiry', $id, 'payroll.inquiry_acknowledged');
            return ['inquiry_id' => $id, 'state' => 'acknowledged', 'version' => 3];
        });
    }

    private function lockedInquiry(array $scope): array
    {
        return $this->db->row(
            'SELECT payslip_id, opened_by, state, version FROM payslip_inquiries WHERE group_id=? AND company_id=? AND id=? FOR UPDATE',
            $scope,
        ) ?? throw new BusinessError(404, 'payslip_inquiry_not_found');
    }

    private function requireState(array $row, string $state, int $version): void
    {
        if ($row['state'] !== $state || (int) $row['version'] !== $version) {
            throw new BusinessError(409, 'payslip_inquiry_conflict', ['state' => $row['state'], 'version' => (int) $row['version']]);
        }
    }

    private static function openedReceipt(int $id, int $payslip, string $reference): array
    {
        return ['inquiry_id' => $id, 'payslip_id' => $payslip, 'reference' => $reference, 'state' => 'open', 'version' => 1];
    }
}
