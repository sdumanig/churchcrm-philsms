<?php
declare(strict_types=1);

namespace ChurchCRM\dto {
    final class SystemURLs {
        public static function getDocumentRoot(): string { return $GLOBALS['philsmsTestRoot']; }
    }
}
namespace {
    $root = sys_get_temp_dir() . '/philsms_view_' . bin2hex(random_bytes(6));
    mkdir($root . '/Include', 0700, true);
    file_put_contents($root . '/Include/Header.php', '<?php // test header');
    file_put_contents($root . '/Include/Footer.php', '<?php // test footer');
    $GLOBALS['philsmsTestRoot'] = $root;

    function renderOverview(array $candidates, array $leaders, ?string $error): string {
        $sRootPath = '/churchcrm/';
        $liveSendingEnabled = false;
        $assignmentCandidates = $candidates;
        $designatedLeaders = $leaders;
        $assignmentOverviewError = $error;
        $assignmentContacts = [123 => [['id' => 456, 'name' => 'Test Person']]];
        $people = [];
        $notifications = [];
        $recipientCandidates = [];
        $messageTemplates = [];
        $safeSettings = [];
        $pluginMetadata = [];
        $pendingApprovalCount = 0;
        ob_start();
        try {
            require __DIR__ . '/../views/manage.php';
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    try {
        $html = renderOverview(
            [[
                'approval_queue_id' => 123,
                'event_type' => 'individual_approved',
                'event_entity_id' => 456,
                'approved_event_at' => '2026-11-23 10:00:00',
            ]],
            [[ 'id' => 7, 'person_id' => 999, 'is_active' => 1 ]],
            null
        );
        if (!str_contains($html, 'Unassigned approval events') ||
            !str_contains($html, 'individual_approved') ||
            !str_contains($html, 'Designated leaders') ||
            !str_contains($html, '999') ||
            !str_contains($html, 'philsms-assignment-form') ||
            !str_contains($html, 'Record assignment (no SMS)') ||
            !str_contains($html, 'Test Person')) {
            throw new \RuntimeException('Populated overview missing expected records.');
        }
        echo "PASS: populated overview renders\n";

        $html = renderOverview(
            [[
                'approval_queue_id' => 124,
                'event_type' => '<script>alert(1)</script>',
                'event_entity_id' => 457,
                'approved_event_at' => '2026-11-23',
            ]],
            [], null
        );
        if (str_contains($html, '<script>alert(1)</script>') ||
            !str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;')) {
            throw new \RuntimeException('Overview failed to escape event type.');
        }
        echo "PASS: overview escapes event data\n";

        $html = renderOverview([], [], 'Migration not applied.');
        if (!str_contains($html, 'Migration not applied.') ||
            !str_contains($html, 'alert-warning')) {
            throw new \RuntimeException('Migration warning missing.');
        }
        echo "PASS: missing-migration fallback renders\n";
    } finally {
        @unlink($root . '/Include/Header.php');
        @unlink($root . '/Include/Footer.php');
        @rmdir($root . '/Include');
        @rmdir($root);
    }
}
