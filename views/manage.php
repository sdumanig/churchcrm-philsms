<?php
use ChurchCRM\dto\SystemURLs;

require SystemURLs::getDocumentRoot() . '/Include/Header.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['philsms_csrf'])) {
    $_SESSION['philsms_csrf'] = bin2hex(random_bytes(24));
}

$base = htmlspecialchars($sRootPath, ENT_QUOTES, 'UTF-8');
$live = ($liveSendingEnabled === true);
?>

<div class="container py-3">
    <h2>PhilSMS Messaging</h2>

    <!-- PhilSMS five-tab navigation -->
    <ul class="nav nav-tabs mb-4"
        id="philsms-management-tabs"
        role="tablist">

        <li class="nav-item" role="presentation">
            <button class="nav-link active"
                    id="philsms-people-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#philsms-people-panel"
                    type="button" role="tab"
                    aria-controls="philsms-people-panel"
                    aria-selected="true">
                Select ChurchCRM People
            </button>
        </li>

        <li class="nav-item" role="presentation">
            <button class="nav-link"
                    id="philsms-leaders-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#philsms-leaders-panel"
                    type="button" role="tab"
                    aria-controls="philsms-leaders-panel"
                    aria-selected="false">
                Assign to a Leader
            </button>
        </li>

        <li class="nav-item" role="presentation">
            <button class="nav-link"
                    id="philsms-templates-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#philsms-templates-panel"
                    type="button" role="tab"
                    aria-controls="philsms-templates-panel"
                    aria-selected="false">
                Message Templates
            </button>
        </li>

        <li class="nav-item" role="presentation">
            <button class="nav-link"
                    id="philsms-notifications-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#philsms-notifications-panel"
                    type="button" role="tab"
                    aria-controls="philsms-notifications-panel"
                    aria-selected="false">
                Approval Notifications
                <?php if (
                    empty($pendingApprovalCountError)
                    && (int) ($pendingApprovalCount ?? 0) > 0
                ): ?>
                    <span class="badge bg-warning text-dark ms-1"
                          title="ChurchCRM registrations awaiting approval"
                          aria-label="<?= (int) $pendingApprovalCount ?> registrations awaiting approval">
                        <?= (int) $pendingApprovalCount ?>
                    </span>
                <?php endif; ?>
            </button>
        </li>

        <li class="nav-item" role="presentation">
            <button class="nav-link"
                    id="philsms-settings-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#philsms-settings-panel"
                    type="button" role="tab"
                    aria-controls="philsms-settings-panel"
                    aria-selected="false">
                Settings
            </button>
        </li>
    </ul>

    <div id="philsms-operational-status">
    <div class="alert alert-info">
        Mode:
        <strong>
            <?= $live ? 'LIVE — messages cost credits'
                      : 'DRY RUN — no messages sent' ?>
        </strong>
    </div>

    <div class="alert alert-light border mb-3">
        <strong>SMS Credits:</strong>

        <?php if (!empty($credits)): ?>
            <span class="text-success fw-bold">
                <?= htmlspecialchars(
                    $credits,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </span>
        <?php else: ?>
            <?php
            $authenticationFailed = str_starts_with(
                (string) $creditsError,
                'PhilSMS API authentication failed.'
            );
            ?>
            <?php if ($authenticationFailed): ?>
                <div class="alert alert-warning mt-2 mb-0" role="alert">
                    <strong>API Authentication Failed</strong>
                    <p class="mb-0">
                        <?= htmlspecialchars(
                            $creditsError,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                </div>
            <?php else: ?>
                <span class="text-muted">
                    <?= htmlspecialchars(
                        $creditsError ?? 'Unavailable',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    </div>

    <!-- Shared PhilSMS tab content -->
    <div class="tab-content" id="philsms-management-content">

    <!-- Message Templates tab panel -->
    <div class="tab-pane fade"
         id="philsms-templates-panel"
         role="tabpanel"
         aria-labelledby="philsms-templates-tab"
         tabindex="0">

    <!-- PhilSMS message templates: read-only -->
    <div class="card mb-4">
        <div class="card-header">
            <strong>Message Templates</strong>
            <span class="text-muted ms-2">(Read-only)</span>
        </div>

        <div class="card-body">
            <?php if (!empty($messageTemplatesError)): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars(
                        $messageTemplatesError,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
            <?php elseif (empty($messageTemplates)): ?>
                <p class="text-muted mb-0">
                    No message templates have been configured.
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Template</th>
                                <th>Status</th>
                                <th>Message</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($messageTemplates as $template): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $template['template_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= (int) $template['is_enabled'] === 1
                                            ? 'Enabled'
                                            : 'Disabled' ?>
                                    </td>
                                    <td style="white-space: pre-line;">
                                        <?= htmlspecialchars(
                                            (string) $template['message_body'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm philsms-edit-template"
                                            data-template-key="<?= htmlspecialchars(
                                                (string) $template['template_key'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            disabled>
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0">
                    Personalization placeholders include
                    {first_name}, {family_name}, {guest_name},
                    {leader_name}, and {church_name}.
                    Template editing will be added separately.
                </p>
            <?php endif; ?>
        </div>
    </div>

    </div>
    <!-- End Message Templates tab panel -->

    <!-- Approval Notifications tab panel -->
    <div class="tab-pane fade"
         id="philsms-notifications-panel"
         role="tabpanel"
         aria-labelledby="philsms-notifications-tab"
         tabindex="0">

    <!-- PhilSMS approval notification queue -->
    <div class="card mb-4">
        <div class="card-header">
            <strong>Approval Notifications</strong>
            <span class="text-muted ms-2">(Read-only)</span>
        </div>

        <div class="card-body">
            <?php if (!empty($notificationsError)): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars($notificationsError, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php elseif (empty($notifications)): ?>
                <p class="text-muted mb-0">
                    No approval notifications have been queued.
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Queue ID</th>
                                <th>Approval Type</th>
                                <th>Record ID</th>
                                <th>Status</th>
                                <th>Attempts</th>
                                <th>Recipient</th>
                                <th>Draft Message</th>
                                <th>Draft Action</th>
                                <th>Queued At (UTC)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notification): ?>
                                <tr>
                                    <td><?= (int) $notification['id'] ?></td>
                                    <td>
                                        <?= htmlspecialchars(
                                            $notification['event_type'] === 'individual_approved'
                                                ? 'Individual'
                                                : ($notification['event_type'] === 'family_approved'
                                                    ? 'Family'
                                                    : 'Unknown'),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>
                                    <td><?= (int) $notification['event_entity_id'] ?></td>
                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $notification['status'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>
                                    <td><?= (int) $notification['attempts'] ?></td>
                                    <td>
                                        <?php
                                            $queueId = (int) $notification['id'];
                                            $candidates = $recipientCandidates[$queueId] ?? [];
                                        ?>
                                        <?php if ($notification['status'] !== 'pending'): ?>
                                            <span class="text-muted">Not editable</span>
                                        <?php elseif (empty($candidates)): ?>
                                            <span class="text-muted">No valid mobile recipient</span>
                                        <?php else: ?>
                                            <select class="form-select form-select-sm philsms-draft-recipient"
                                                    aria-label="Recipient for notification <?= $queueId ?>">
                                                <option value="">Select recipient (not saved)</option>
                                                <?php foreach ($candidates as $candidate): ?>
                                                    <option
                                                        value="<?= (int) $candidate['person_id'] ?>"
                                                        <?= (
                                                            !empty($notification['recipient_phone']) &&
                                                            hash_equals(
                                                                (string) $notification['recipient_phone'],
                                                                (string) $candidate['phone']
                                                            ) &&
                                                            hash_equals(
                                                                (string) ($notification['recipient_name'] ?? ''),
                                                                (string) $candidate['name']
                                                            )
                                                        ) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars(
                                                            (string) $candidate['name'],
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($notification['status'] === 'pending'): ?>
                                            <textarea
                                                class="form-control form-control-sm philsms-draft-message"
                                                rows="3"
                                                maxlength="1000"
                                                aria-label="Draft message for notification <?= (int) $notification['id'] ?>"
                                                placeholder="Enter approval notification message..."><?= htmlspecialchars(
                                                    (string) ($notification['message'] ?? ''),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?></textarea>
                                            <div class="small text-muted mt-1 philsms-draft-sms-usage"
                                                 role="status"
                                                 aria-live="polite">
                                                Calculating SMS usage...
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Not editable</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($notification['status'] === 'pending'): ?>
                                            <button
                                                type="button"
                                                class="btn btn-primary btn-sm philsms-save-draft"
                                                data-queue-id="<?= (int) $notification['id'] ?>">
                                                Save Draft
                                            </button>
                                            <button
                                                 type="button"
                                                 class="btn btn-outline-primary btn-sm philsms-review-deliver"
                                                 data-queue-id="<?= (int) $notification['id'] ?>">
                                                 <i class="fa fa-clipboard-check" aria-hidden="true"></i>
                                                 <?= $live ? 'Review &amp; Deliver' : 'Review Notification' ?>
                                             </button>
                                            <div class="small mt-1 philsms-draft-result"
                                                 role="status"
                                                 aria-live="polite"></div>
                                        <?php else: ?>
                                            <span class="text-muted">Not available</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $notification['created_at'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    </div>
    <!-- End Approval Notifications tab panel -->

    <!-- Select ChurchCRM People tab panel -->
    <div class="tab-pane fade show active"
         id="philsms-people-panel"
         role="tabpanel"
         aria-labelledby="philsms-people-tab"
         tabindex="0">

    <form method="post"
          action="<?= $base ?>/plugins/philsms/send"
          id="philsms-form">

        <input type="hidden"
               name="csrf"
               value="<?= htmlspecialchars(
                   $_SESSION['philsms_csrf'],
                   ENT_QUOTES,
                   'UTF-8'
               ) ?>">

        <input type="hidden"
               name="person_ids"
               id="person_ids">

        <div class="card mb-3">
            <div class="card-header">
                <strong>Select ChurchCRM People</strong>
            </div>

            <div class="card-body">
                <label for="member-search" class="form-label">
                    Search by name or Person ID
                </label>

                <input type="search"
                       class="form-control mb-3"
                       id="member-search"
                       placeholder="Search ChurchCRM people...">

                <p>
                    <strong id="selected-count">0</strong>
                    of 25 recipients selected
                </p>

                <div style="max-height:350px;overflow-y:auto"
                     class="border rounded">

                    <?php foreach ($people as $person): ?>
                        <?php
                        $id = (int) $person['id'];
                        $name = htmlspecialchars(
                            $person['name'],
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        $phone = (string) $person['phone'];
                        $masked = substr($phone, 0, 5)
                            . '****'
                            . substr($phone, -3);
                        ?>

                        <label class="d-block p-2 border-bottom member-row"
                               data-search="<?= htmlspecialchars(
                                   strtolower($person['name'] . ' ' . $id),
                                   ENT_QUOTES,
                                   'UTF-8'
                               ) ?>">

                            <input type="checkbox"
                                   class="member-checkbox me-2"
                                   value="<?= $id ?>">

                            <strong><?= $name ?></strong>

                            <small class="text-muted">
                                (#<?= $id ?>)
                                <?= htmlspecialchars(
                                    $masked,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </small>
                        </label>
                    <?php endforeach; ?>

                </div>

                <?php if (empty($people)): ?>
                    <p class="text-muted mt-2">
                        No people with valid mobile numbers found.
                    </p>
                <?php endif; ?>

            </div>
        </div>

        <div class="mb-3">
            <label for="sms-message" class="form-label">
                SMS message
            </label>

            <textarea class="form-control"
                      name="message"
                      id="sms-message"
                      rows="5"
                      maxlength="1000"
                      required></textarea>

            <div class="small text-muted mt-1"
                 id="sms-usage"
                 role="status"
                 aria-live="polite">
                <div id="sms-message-details">0 characters · GSM-7</div>
                <div id="sms-credit-details">
                    0 SMS segment(s) · Credits: 0 credit(s)
                </div>
                <div id="sms-total-credits">
                    0 recipient(s) · Credits:
                    <strong id="sms-total-credit-value">0 total credit(s)</strong>
                </div>
            </div>
        </div>

        <button class="btn btn-primary"
                type="submit"
                id="send-button"
                disabled>
            <?= $live ? 'Process SMS' : 'Run SMS Dry Test' ?>
        </button>
    </form>

<div id="philsms-result"
     class="alert mt-3"
     role="status"
     aria-live="polite"
     style="display:none;">
</div>

    <p class="mt-3 text-muted">
        Administrators only. Confirm recipient consent before
        sending. Maximum 25 recipients per submission.
    </p>
    </div>
    <!-- End Select ChurchCRM People tab panel -->

    <!-- Assign to a Leader tab panel -->
    <div class="tab-pane fade"
         id="philsms-leaders-panel"
         role="tabpanel"
         aria-labelledby="philsms-leaders-tab"
         tabindex="0">
        <div class="card mb-4">
            <div class="card-header">
                <strong>Assign to a Leader</strong>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Manage designated leaders and record follow-up assignments for approved guests. No SMS is sent.
                </p>
                <?php if (!empty($assignmentOverviewError)): ?>
                    <div class="alert alert-warning" role="status">
                        <?= htmlspecialchars((string) $assignmentOverviewError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php else: ?>
                    <h5>Designate a leader</h5>
                    <p class="text-muted">Enter the ChurchCRM person ID of a leader. Up to seven leaders may be active. Entering an inactive leader's ID reactivates that designation.</p>
                    <form id="philsms-leader-designation-form" method="post"
                          action="<?= $base ?>/plugins/philsms/leaders/designate">
                        <input type="hidden" name="csrf"
                               value="<?= htmlspecialchars((string) $_SESSION['philsms_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label class="form-label" for="philsms-leader-person-id">ChurchCRM person ID</label>
                            <input class="form-control" id="philsms-leader-person-id"
                                   name="person_id" type="number" min="1" step="1" required
                                   inputmode="numeric">
                        </div>
                        <button type="submit" class="btn btn-primary">Save leader designation (no SMS)</button>
                        <div id="philsms-leader-designation-result" class="mt-3"
                             role="status" aria-live="polite"></div>
                    </form>
                    <hr class="my-4">
                    <h5>Unassigned approval events</h5>
                    <?php if (empty($assignmentCandidates)): ?>
                        <p>No unassigned approval events are recorded.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead><tr>
                                    <th>Approval event</th><th>Type</th>
                                    <th>ChurchCRM record ID</th><th>Recorded at</th>
                                </tr></thead>
                                <tbody id="philsms-unassigned-approval-rows">
                                <?php foreach ($assignmentCandidates as $candidate): ?>
                                    <tr data-approval-queue-id="<?= (int) $candidate['approval_queue_id'] ?>">
                                        <td><?= (int) $candidate['approval_queue_id'] ?></td>
                                        <td><?= htmlspecialchars((string) $candidate['event_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $candidate['event_entity_id'] ?></td>
                                        <td><?= htmlspecialchars((string) $candidate['approved_event_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <h5>Assign an approval</h5>
                    <p class="text-muted">Select the recorded approval, an active designated leader, and an eligible contact. Assignment does not send SMS.</p>
                    <form id="philsms-assignment-form" method="post"
                          action="<?= $base ?>/plugins/philsms/assignments/create">
                        <input type="hidden" name="csrf"
                               value="<?= htmlspecialchars((string) $_SESSION['philsms_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label class="form-label" for="philsms-assignment-event">Approval event</label>
                            <select class="form-select" id="philsms-assignment-event" name="approval_queue_id" required>
                                <option value="">Select approval</option>
                                <?php foreach ($assignmentCandidates as $candidate): ?>
                                    <?php if (!empty($assignmentContacts[(int) $candidate['approval_queue_id']])): ?>
                                        <option value="<?= (int) $candidate['approval_queue_id'] ?>">
                                            <?= htmlspecialchars((string) $candidate['event_type'], ENT_QUOTES, 'UTF-8') ?>
                                            #<?= (int) $candidate['event_entity_id'] ?>
                                            (event <?= (int) $candidate['approval_queue_id'] ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="philsms-assignment-leader">Designated leader</label>
                            <select class="form-select" id="philsms-assignment-leader" name="leader_id" required>
                                <option value="">Select active leader</option>
                                <?php foreach ($designatedLeaders as $leader): ?>
                                    <?php if (!empty($leader['is_active'])): ?>
                                        <option value="<?= (int) $leader['id'] ?>">
                                            <?= htmlspecialchars(
                                                (string) ($leader['display_name'] ?? ('ChurchCRM person #' . (int) $leader['person_id'])),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?> (person #<?= (int) $leader['person_id'] ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="philsms-assignment-contact">Eligible contact</label>
                            <select class="form-select" id="philsms-assignment-contact" name="contact_person_id" required disabled>
                                <option value="">Select an approval first</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Record assignment (no SMS)</button>
                        <div id="philsms-assignment-result" class="mt-3" role="status" aria-live="polite"></div>
                    </form>
                    <script type="application/json" id="philsms-assignment-contacts-json"><?= json_encode(
                        $assignmentContacts ?? [],
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                    ) ?></script>
                    <h5 class="mt-4">Designated leaders</h5>
                    <?php if (empty($designatedLeaders)): ?>
                        <p>No designated leaders are recorded.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead><tr><th>Leader record</th><th>ChurchCRM person ID</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($designatedLeaders as $leader): ?>
                                    <tr>
                                        <td><?= (int) $leader['id'] ?></td>
                                        <td><?= htmlspecialchars(
                                            (string) ($leader['display_name'] ?? ('ChurchCRM person #' . (int) $leader['person_id'])),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?> (#<?= (int) $leader['person_id'] ?>)</td>
                                        <td><?= !empty($leader['is_active']) ? 'Active' : 'Inactive' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    (() => {
        const form = document.getElementById('philsms-leader-designation-form');
        if (!form) return;
        const result = document.getElementById('philsms-leader-designation-result');
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            result.textContent = 'Saving leader designation…';
            result.className = 'mt-3';
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    body: new FormData(form)
                });
                const data = await response.json();
                result.textContent = data.message || 'Leader designation request completed.';
                result.className = response.ok && data.success
                    ? 'alert alert-success mt-3' : 'alert alert-warning mt-3';
                if (response.ok && data.success) {
                    form.reset();
                    const reload = document.createElement('button');
                    reload.type = 'button';
                    reload.className = 'btn btn-sm btn-outline-primary ms-2';
                    reload.textContent = 'Refresh leader list';
                    reload.addEventListener('click', () => window.location.reload());
                    result.appendChild(reload);
                }
            } catch (error) {
                result.textContent = 'Unable to save leader designation. Please try again.';
                result.className = 'alert alert-danger mt-3';
            } finally {
                button.disabled = false;
            }
        });
    })();
    </script>
    <script>
    (() => {
        const form = document.getElementById('philsms-assignment-form');
        if (!form) return;
        const eventSelect = document.getElementById('philsms-assignment-event');
        const contactSelect = document.getElementById('philsms-assignment-contact');
        const result = document.getElementById('philsms-assignment-result');
        const choices = JSON.parse(document.getElementById('philsms-assignment-contacts-json').textContent);
        eventSelect.addEventListener('change', () => {
            contactSelect.replaceChildren(new Option('Select eligible contact', ''));
            (choices[eventSelect.value] || []).forEach(person => {
                contactSelect.add(new Option(person.name + ' (#' + person.id + ')', String(person.id)));
            });
            contactSelect.disabled = !eventSelect.value || !(choices[eventSelect.value] || []).length;
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            result.textContent = 'Recording assignment…';
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    body: new FormData(form)
                });
                const data = await response.json();
                result.textContent = data.message || 'Assignment request completed.';
                result.className = response.ok && data.success
                    ? 'alert alert-success mt-3' : 'alert alert-warning mt-3';
                if (response.ok && data.success) {
                    const assignedEventId = eventSelect.value;
                    const selectedOption = eventSelect.selectedOptions[0];
                    if (selectedOption && selectedOption.value) selectedOption.remove();
                    const approvalRows = document.getElementById('philsms-unassigned-approval-rows');
                    if (approvalRows) {
                        for (const row of approvalRows.querySelectorAll('tr[data-approval-queue-id]')) {
                            if (row.dataset.approvalQueueId === assignedEventId) {
                                row.remove();
                                break;
                            }
                        }
                        if (!approvalRows.querySelector('tr[data-approval-queue-id]')) {
                            const emptyRow = document.createElement('tr');
                            const emptyCell = document.createElement('td');
                            emptyCell.colSpan = 4;
                            emptyCell.textContent = 'No unassigned approval events remain in this list.';
                            emptyRow.appendChild(emptyCell);
                            approvalRows.appendChild(emptyRow);
                        }
                    }
                    delete choices[assignedEventId];
                    eventSelect.value = '';
                    eventSelect.dispatchEvent(new Event('change'));
                }
            } catch (error) {
                result.textContent = 'Unable to record assignment. Please try again.';
                result.className = 'alert alert-danger mt-3';
            } finally {
                button.disabled = false;
            }
        });
    })();
    </script>
    <!-- End Assign to a Leader tab panel -->

    <!-- Settings tab panel -->
    <div class="tab-pane fade"
         id="philsms-settings-panel"
         role="tabpanel"
         aria-labelledby="philsms-settings-tab"
         tabindex="0">

        <div class="card mb-4">
            <div class="card-header">
                <strong>Plugin Information</strong>
                <span class="text-muted ms-2">(Read-only)</span>
            </div>
            <div class="card-body">
                <?php if (!empty($pluginMetadataError)): ?>
                    <div class="alert alert-warning">
                        <?= htmlspecialchars(
                            $pluginMetadataError,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                <?php else: ?>
                    <dl class="row mb-0">
                        <?php
                        $metadataLabels = [
                            'name' => 'Plugin Name',
                            'version' => 'Version',
                            'author' => 'Developer',
                            'description' => 'Description',
                            'type' => 'Plugin Type',
                            'minimumCRMVersion' => 'Minimum ChurchCRM Version',
                        ];
                        ?>
                        <?php foreach ($metadataLabels as $field => $label): ?>
                            <dt class="col-sm-4">
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </dt>
                            <dd class="col-sm-8">
                                <?= htmlspecialchars(
                                    (string) ($pluginMetadata[$field] ?? ''),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <strong>SMS Configuration</strong>
                <span class="text-muted ms-2">(Read-only)</span>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">PhilSMS API Key</dt>
                    <dd class="col-sm-8">
                        <?php if (!empty($safeSettings['apiKeyConfigured'])): ?>
                            <span class="text-success">Configured</span>
                        <?php else: ?>
                            <span class="text-warning">Missing</span>
                        <?php endif; ?>
                        <div class="small text-muted">
                            The API key is never displayed.
                        </div>
                    </dd>

                    <dt class="col-sm-4">Sender ID</dt>
                    <dd class="col-sm-8">
                        <?= htmlspecialchars(
                            (string) ($safeSettings['senderId'] ?: 'Not configured'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </dd>

                    <dt class="col-sm-4">Plugin Manager Live Sending</dt>
                    <dd class="col-sm-8">
                        <?= !empty($safeSettings['liveSendingRequested'])
                            ? 'Enabled'
                            : 'Disabled' ?>
                    </dd>

                    <dt class="col-sm-4">Server Live Sending Authorization</dt>
                    <dd class="col-sm-8">
                        <?= !empty($safeSettings['serverLiveSendingAllowed'])
                            ? 'Enabled'
                            : 'Disabled' ?>
                    </dd>

                    <dt class="col-sm-4">Effective Sending Mode</dt>
                    <dd class="col-sm-8">
                        <strong>
                            <?= !empty($safeSettings['effectiveLiveSending'])
                                ? 'LIVE'
                                : 'DRY RUN' ?>
                        </strong>
                    </dd>
                </dl>

                <p class="small text-muted mt-3 mb-0">
                    Live SMS sending requires both the Plugin Manager
                    setting and server authorization. Configuration
                    changes are managed through ChurchCRM Plugin Manager.
                </p>
            </div>
        </div>
    </div>
    <!-- End Settings tab panel -->

    </div>
    <!-- End shared PhilSMS tab content -->

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const status = document.getElementById('philsms-operational-status');
    const tabs = document.getElementById('philsms-management-tabs');

    if (!status || !tabs) {
        return;
    }

    function updateStatusVisibility() {
        const activeTab = tabs.querySelector('.nav-link.active');

        status.hidden = activeTab !== null
            && activeTab.id === 'philsms-templates-tab';
    }

    tabs.addEventListener('shown.bs.tab', updateStatusVisibility);
    updateStatusVisibility();
});
</script>

<!-- Approval notification review: mode-aware, live delivery locked -->
<!-- PhilSMS message template editor -->
<div class="modal fade" id="philsms-template-editor-modal"
     tabindex="-1" aria-labelledby="philsms-template-editor-title"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="philsms-template-editor-title">
                    Edit Message Template
                </h5>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <form id="philsms-template-editor-form"
                      data-save-url="<?= htmlspecialchars(
                          $base . '/plugins/philsms/templates/save',
                          ENT_QUOTES,
                          'UTF-8'
                      ) ?>">
                    <input type="hidden"
                           id="philsms-template-csrf"
                           value="<?= htmlspecialchars(
                               (string) $_SESSION['philsms_csrf'],
                               ENT_QUOTES,
                               'UTF-8'
                           ) ?>">
                    <input type="hidden" id="philsms-template-key">

                    <div class="mb-3">
                        <label for="philsms-template-name" class="form-label">
                            Template Name
                        </label>
                        <input type="text" class="form-control"
                               id="philsms-template-name"
                               maxlength="120" required>
                    </div>

                    <div class="mb-3">
                        <label for="philsms-template-body" class="form-label">
                            SMS Message
                        </label>
                        <textarea class="form-control"
                                  id="philsms-template-body"
                                  rows="5" maxlength="1000"
                                  required></textarea>
                        <div id="philsms-template-sms-usage"
                             class="small text-muted mt-1"
                             role="status" aria-live="polite"></div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox"
                               id="philsms-template-enabled">
                        <label class="form-check-label"
                               for="philsms-template-enabled">
                            Template Enabled
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Personalized Preview</label>
                        <div id="philsms-template-preview"
                             class="border rounded p-3"
                             style="white-space: pre-wrap; overflow-wrap: anywhere;"
                             aria-live="polite"></div>
                    </div>

                    <p class="small text-muted">
                        Supported placeholders: {first_name}, {last_name},
                        {full_name}, {family_name}, {guest_name},
                        {church_name}, {leader_name}, {service_schedule}.
                    </p>

                    <div id="philsms-template-result"
                         role="status" aria-live="polite"></div>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary"
                        id="philsms-template-save" disabled>
                    Save Template
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="philsms-notification-review-modal"
     tabindex="-1" aria-labelledby="philsms-review-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="philsms-review-title">
                    Review SMS Notification
                </h5>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <?php if ($live): ?>
                       <strong>LIVE SMS DELIVERY.</strong>
Review the recipient and message carefully.
Sending will consume SMS credits.
Explicit authorization is required.
                    <?php else: ?>
                        <strong>Dry run.</strong>
                        No SMS will be sent.
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <div class="small text-muted">Recipient</div>
                    <div id="philsms-review-recipient" class="fw-bold"></div>
                    <div id="philsms-review-phone" class="text-muted"></div>
                </div>
                <div class="small text-muted mb-1">SMS Message</div>
                <div id="philsms-review-message"
                     class="border rounded p-3"
                     style="white-space: pre-wrap; overflow-wrap: anywhere;"></div>
                <?php if ($live): ?>
                    <div class="form-check mt-3">
                        <input class="form-check-input"
                               type="checkbox"
                               id="philsms-live-confirmation">
                        <label class="form-check-label"
                               for="philsms-live-confirmation">
                            I have reviewed the recipient and message.
                            I authorize sending this SMS.
                        </label>
                    </div>
                <?php endif; ?>

                <div id="philsms-review-feedback"
                     class="small mt-3" role="status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary"
                        id="philsms-confirm-dry-run">
                    <?= $live ? 'Confirm &amp; Send SMS' : 'Confirm Dry Run' ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="philsms-template-data"><?= json_encode(
    $messageTemplates ?? [],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
    JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE |
    JSON_THROW_ON_ERROR
) ?></script>
<script src="<?= $base ?>/plugins/community/philsms/assets/js/sms-segments.js"></script>
<script src="<?= $base ?>/plugins/community/philsms/assets/js/message-template-editor.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/message-template-editor.js') ?>"></script>
<script>
(function () {
    'use strict';

    // Notification review workflow. Live delivery remains locked.
    const draftRevisions = new WeakMap();
    const busyDraftRows = new WeakSet();
    const unsavedDraftRows = new WeakSet();
    const reviewedDraftRows = new WeakSet();

    const notificationLiveMode =
        <?= $live ? 'true' : 'false' ?>;

    function setNotificationBusy(row, busy) {
        if (busy) {
            busyDraftRows.add(row);
        } else {
            busyDraftRows.delete(row);
        }

        row.querySelectorAll(
            '.philsms-save-draft, .philsms-review-deliver'
        ).forEach(function (button) {
            button.disabled = busy;
        });
    }

    function showNotificationResult(row, message, style) {
        const result = row.querySelector('.philsms-draft-result');
        if (!result) return;

        result.textContent = message;
        result.className =
            'small mt-1 philsms-draft-result text-' + style;
    }

    document.querySelectorAll(
        '.philsms-draft-recipient, .philsms-draft-message'
    ).forEach(function (field) {
        field.addEventListener('input', function () {
            const row = field.closest('tr');
            if (!row) return;

            unsavedDraftRows.add(row);
            reviewedDraftRows.delete(row);

            draftRevisions.set(
                row,
                (draftRevisions.get(row) || 0) + 1
            );

            showNotificationResult(
                row,
                'Draft changed. Save before reviewing for delivery.',
                'warning'
            );
        });
    });

    document.querySelectorAll('.philsms-draft-message').forEach(function (field) {
        const row = field.closest('tr');
        const display = row?.querySelector('.philsms-draft-sms-usage');

        if (!display) return;

        function updateSmsUsage() {
            if (!window.PhilSMSSegments) {
                display.textContent = 'SMS usage calculator unavailable.';
                return;
            }

            const usage = window.PhilSMSSegments.calculate(field.value);
            display.replaceChildren();

            const first = document.createElement('div');
            first.textContent =
                usage.characters + ' characters · ' + usage.encoding;

            const second = document.createElement('div');
            second.textContent =
                usage.segments + ' SMS segment(s) · Credits: ' +
                usage.estimatedCreditsPerRecipient + ' credit(s)';

            display.append(first, second);
        }

        field.addEventListener('input', updateSmsUsage);
        updateSmsUsage();
    });

    async function notificationPost(endpoint, payload) {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: payload.toString()
        });

        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            throw new Error('Unexpected server response');
        }

        return {response, data: await response.json()};
    }

    const notificationCsrf = document.querySelector(
        '#philsms-form input[name="csrf"]'
    );

    const notificationSaveUrl = <?= json_encode(
        $base . '/plugins/philsms/notifications/save-draft',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const notificationReviewUrl = <?= json_encode(
        $base . '/plugins/philsms/notifications/review-delivery',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const notificationDeliverUrl = <?= json_encode(
        $base . '/plugins/philsms/notifications/deliver',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const notificationPrepareUrl = <?= json_encode(
        $base . '/plugins/philsms/notifications/prepare-welcome',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const welcomeRequestVersions = new WeakMap();

    document.querySelectorAll('.philsms-draft-recipient').forEach(function (recipient) {
        const row = recipient.closest('tr');
        const draft = row?.querySelector('.philsms-draft-message');
        const saveButton = row?.querySelector('.philsms-save-draft');

        if (!row || !draft || !saveButton) return;

        recipient.addEventListener('change', async function () {
            const version = (welcomeRequestVersions.get(row) || 0) + 1;
            welcomeRequestVersions.set(row, version);

            // Preserve saved messages and any text already entered.
            if (!recipient.value || draft.value.trim() !== '' ||
                !notificationCsrf || busyDraftRows.has(row)) {
                return;
            }

            const selectedRecipient = recipient.value;
            const startingRevision = draftRevisions.get(row) || 0;

            showNotificationResult(
                row, 'Preparing welcome message...', 'muted'
            );

            try {
                const {response, data} = await notificationPost(
                    notificationPrepareUrl,
                    new URLSearchParams({
                        csrf: notificationCsrf.value,
                        queue_id: saveButton.dataset.queueId,
                        recipient_person_id: selectedRecipient
                    })
                );

                // Do not overwrite user edits or a newer recipient selection.
                if (welcomeRequestVersions.get(row) !== version ||
                    recipient.value !== selectedRecipient ||
                    (draftRevisions.get(row) || 0) !== startingRevision ||
                    draft.value !== '' ||
                    busyDraftRows.has(row)) {
                    return;
                }

                if (!response.ok || data.success !== true) {
                    showNotificationResult(
                        row,
                        data.message || 'Unable to prepare welcome message.',
                        'warning'
                    );
                    return;
                }

                if (data.available !== true ||
                    typeof data.message !== 'string' ||
                    data.message.trim() === '') {
                    showNotificationResult(
                        row,
                        'No enabled welcome template is available. Enter a message manually.',
                        'warning'
                    );
                    return;
                }

                draft.value = data.message;
                draft.dispatchEvent(new Event('input', {bubbles: true}));

                showNotificationResult(
                    row,
                    'Welcome message prepared. Review and save the draft.',
                    'warning'
                );
            } catch (error) {
                if (welcomeRequestVersions.get(row) === version &&
                    recipient.value === selectedRecipient &&
                    draft.value === '') {
                    showNotificationResult(
                        row,
                        'Unable to prepare welcome message. You may enter it manually.',
                        'warning'
                    );
                }
            }
        });
    });

    document.querySelectorAll('.philsms-save-draft').forEach(function (button) {
        button.addEventListener('click', async function () {
            const row = button.closest('tr');
            if (!row || busyDraftRows.has(row)) return;

            const queueId = button.dataset.queueId;
            const recipient = row.querySelector('.philsms-draft-recipient');
            const draft = row.querySelector('.philsms-draft-message');

            if (!notificationCsrf || !recipient || !draft) {
                showNotificationResult(
                    row, 'Missing security token or draft fields.', 'danger'
                );
                return;
            }

            const message = draft.value.trim();

            if (!recipient.value || !message ||
                Array.from(message).length > 1000) {
                showNotificationResult(
                    row,
                    'Select a recipient and enter a message of up to 1000 characters.',
                    'danger'
                );
                return;
            }

            const revision = draftRevisions.get(row) || 0;
            unsavedDraftRows.add(row);
            reviewedDraftRows.delete(row);
            setNotificationBusy(row, true);
            showNotificationResult(row, 'Saving draft...', 'muted');

            try {
                const {response, data} = await notificationPost(
                    notificationSaveUrl,
                    new URLSearchParams({
                        csrf: notificationCsrf.value,
                        queue_id: queueId,
                        recipient_person_id: recipient.value,
                        message: message
                    })
                );

                if ((draftRevisions.get(row) || 0) !== revision) return;

                if (response.ok && data.success === true) {
                    unsavedDraftRows.delete(row);
                    showNotificationResult(
                        row, 'Draft saved. Ready for review.', 'success'
                    );
                } else {
                    showNotificationResult(
                        row, data.message || 'Unable to save draft.', 'danger'
                    );
                }
            } catch (error) {
                showNotificationResult(
                    row, 'Save outcome unknown. Refresh before continuing.',
                    'warning'
                );
            } finally {
                setNotificationBusy(row, false);
            }
        });
    });

    const reviewModalElement = document.getElementById(
        'philsms-notification-review-modal'
    );
    const confirmDryRunButton = document.getElementById(
        'philsms-confirm-dry-run'
    );
    const liveConfirmationCheckbox = document.getElementById(
        'philsms-live-confirmation'
    );

function updateConfirmationButtonState() {
    if (!confirmDryRunButton) return;

    confirmDryRunButton.disabled =
        notificationLiveMode &&
        (!liveConfirmationCheckbox ||
         !liveConfirmationCheckbox.checked);
}

liveConfirmationCheckbox?.addEventListener(
    'change',
    updateConfirmationButtonState
);

updateConfirmationButtonState();

    let pendingReview = null;

    function closeReviewModal() {
        if (window.bootstrap?.Modal && reviewModalElement) {
            window.bootstrap.Modal.getOrCreateInstance(
                reviewModalElement
            ).hide();
        }
    }

    reviewModalElement?.addEventListener('hidden.bs.modal', function () {
        pendingReview = null;

        if (liveConfirmationCheckbox) {
            liveConfirmationCheckbox.checked = false;
        }

        updateConfirmationButtonState();
    });

    document.querySelectorAll('.philsms-review-deliver').forEach(function (button) {
        button.addEventListener('click', async function () {
            const row = button.closest('tr');

            if (!row || busyDraftRows.has(row) ||
                unsavedDraftRows.has(row)) {
                if (row && unsavedDraftRows.has(row)) {
                    showNotificationResult(
                        row, 'Save the current draft before reviewing.', 'warning'
                    );
                }
                return;
            }

            if (!notificationCsrf || !reviewModalElement ||
                !confirmDryRunButton || !window.bootstrap?.Modal) {
                showNotificationResult(
                    row, 'Review dialog unavailable. Refresh the page.', 'danger'
                );
                return;
            }

            const queueId = button.dataset.queueId;
            const revision = draftRevisions.get(row) || 0;

            setNotificationBusy(row, true);
            showNotificationResult(
                row, 'Validating saved notification...', 'muted'
            );

            try {
                const {response, data} = await notificationPost(
                    notificationReviewUrl,
                    new URLSearchParams({
                        csrf: notificationCsrf.value,
                        queue_id: queueId
                    })
                );

                if ((draftRevisions.get(row) || 0) !== revision ||
                    unsavedDraftRows.has(row)) return;

                if (!response.ok || data.success !== true ||
                    data.review_only !== true || !data.preview ||
                    String(data.queue_id) !== String(queueId) ||
                    typeof data.confirmation_token !== 'string' ||
                    !/^[a-f0-9]{64}$/.test(data.confirmation_token)) {
                    showNotificationResult(
                        row,
                        data.message || 'Unable to validate notification.',
                        'warning'
                    );
                    return;
                }

                const preview = data.preview;

                if (typeof preview.recipient_name !== 'string' ||
                    typeof preview.recipient_phone !== 'string' ||
                    typeof preview.message !== 'string') {
                    throw new Error('Incomplete preview');
                }

                document.getElementById(
                    'philsms-review-recipient'
                ).textContent = preview.recipient_name;

                document.getElementById(
                    'philsms-review-phone'
                ).textContent = preview.recipient_phone;

                document.getElementById(
                    'philsms-review-message'
                ).textContent = preview.message;

                document.getElementById(
                    'philsms-review-feedback'
                ).textContent = '';

                pendingReview = {
                    row,
                    queueId,
                    revision,
                    confirmationToken: data.confirmation_token
                };

                if (liveConfirmationCheckbox) {
                    liveConfirmationCheckbox.checked = false;
                }

                updateConfirmationButtonState();

                window.bootstrap.Modal.getOrCreateInstance(
                    reviewModalElement
                ).show();

                showNotificationResult(
                    row, 'Saved draft validated for review.', 'success'
                );
            } catch (error) {
                showNotificationResult(
                    row, 'Unable to validate saved notification.', 'danger'
                );
            } finally {
                setNotificationBusy(row, false);
            }
        });
    });

    confirmDryRunButton?.addEventListener('click', async function () {
        const review = pendingReview;

        if (!review || confirmDryRunButton.disabled) return;

        const {
            row,
            queueId,
            revision,
            confirmationToken
        } = review;

        if (notificationLiveMode &&
            (!liveConfirmationCheckbox ||
             !liveConfirmationCheckbox.checked)) {
            document.getElementById(
                'philsms-review-feedback'
            ).textContent =
                'Please authorize SMS delivery before continuing.';
            return;
        }

        if (busyDraftRows.has(row) ||
            unsavedDraftRows.has(row) ||
            (draftRevisions.get(row) || 0) !== revision) {
            document.getElementById(
                'philsms-review-feedback'
            ).textContent = 'Draft changed. Close and review again.';
            return;
        }

        confirmDryRunButton.disabled = true;
        setNotificationBusy(row, true);

        try {

            // Submit the exact single-use authorization issued during review.
            const deliveryPayload = new URLSearchParams({
                csrf: notificationCsrf.value,
                queue_id: queueId,
                confirmation_token: confirmationToken
            });

            // Include explicit administrator authorization in live mode.
            if (notificationLiveMode) {
                deliveryPayload.set('live_authorization', '1');
            }

            const {response, data} = await notificationPost(
                notificationDeliverUrl,
                deliveryPayload
            );

            let message;
            let tone = 'warning';

            if (data.status === 'sent' && response.ok && data.success === true) {
                message = data.message ||
                    'PhilSMS accepted the SMS submission.';
                tone = 'success';
            } else if (data.dry_run === true && response.ok) {
                message = 'Dry run completed successfully. No SMS was sent.';
                tone = 'success';
            } else if (data.status === 'uncertain') {
                message = 'SMS submission outcome is uncertain. Check the queue before taking further action.';
                tone = 'warning';
            } else if (data.status === 'failed') {
                message = data.message ||
                    'SMS submission failed. Review the notification.';
                tone = 'danger';
            } else if (data.status === 'not_pending') {
                message = 'Notification is no longer pending. Refresh the queue.';
                tone = 'warning';
            } else {
                message = data.message ||
                    'Delivery was not authorized. Review the notification again.';
                tone = 'warning';
            }

            showNotificationResult(row, message, tone);

            // The server has consumed the confirmation token.
            pendingReview = null;
            closeReviewModal();

        } catch (error) {
            // The request may have reached the server even if the browser
            // did not receive its response. Never automatically retry.
            pendingReview = null;

            showNotificationResult(
                row,
                'Delivery outcome could not be confirmed. Refresh the queue and verify its status before taking further action.',
                'warning'
            );

            closeReviewModal();

        } finally {
            setNotificationBusy(row, false);
            updateConfirmationButtonState();
        }
    });

    const form = document.getElementById('philsms-form');
    const search = document.getElementById('member-search');
    const boxes = Array.from(
        document.querySelectorAll('.member-checkbox')
    );
    const rows = Array.from(
        document.querySelectorAll('.member-row')
    );
    const idsField = document.getElementById('person_ids');
    const counter = document.getElementById('selected-count');
    const message = document.getElementById('sms-message');
    const messageDetails = document.getElementById('sms-message-details');
    const creditDetails = document.getElementById('sms-credit-details');
    const totalCredits = document.getElementById('sms-total-credits');
    const button = document.getElementById('send-button');

    function update() {
        const selected = boxes
            .filter(box => box.checked)
            .map(box => box.value);

        idsField.value = selected.join(',');
        counter.textContent = selected.length;
        if (window.PhilSMSSegments) {
            const usage = window.PhilSMSSegments.calculate(message.value);

            messageDetails.textContent =
                usage.characters + ' characters · ' + usage.encoding;

            creditDetails.textContent =
                usage.segments + ' SMS segment(s) · Credits: ' +
                usage.estimatedCreditsPerRecipient + ' credit(s)';

            totalCredits.firstChild.textContent =
                selected.length + ' recipient(s) · Credits: ';

            document.getElementById('sms-total-credit-value').textContent =
                (usage.estimatedCreditsPerRecipient * selected.length) +
                ' total credit(s)';
        } else {
            messageDetails.textContent = 'SMS calculator unavailable.';
            creditDetails.textContent = '';
            totalCredits.textContent = '';
        }

        button.disabled =
            selected.length === 0 ||
            selected.length > 25 ||
            message.value.trim() === '';
    }

    function filterMembers() {
    const query = search.value.trim().toLowerCase();

    rows.forEach(function (row) {
        const searchableText = row.textContent.toLowerCase();

        row.style.setProperty(
            'display',
            searchableText.includes(query) ? 'block' : 'none',
            'important'
        );
    });
}

search.addEventListener('input', filterMembers);
search.addEventListener('search', filterMembers);

    boxes.forEach(box => {
        box.addEventListener('change', function () {
            if (boxes.filter(item => item.checked).length > 25) {
                box.checked = false;
                alert('Maximum of 25 recipients per submission.');
            }

            update();
        });
    });

    message.addEventListener('input', update);


form.addEventListener('submit', async function (event) {
    event.preventDefault();
    update();

    if (button.disabled) {
        return;
    }

    const count = Number(counter.textContent);

    if (!confirm(
        'Process SMS for ' + count + ' selected recipient(s)?'
    )) {
        return;
    }

    const resultBox = document.getElementById('philsms-result');
    const originalText = button.textContent;

    button.disabled = true;
    button.textContent = 'Processing...';

    resultBox.style.display = 'block';
    resultBox.className = 'alert alert-info mt-3';
    resultBox.textContent = 'Processing SMS request...';

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        });

        const contentType = response.headers.get('content-type') || '';

        if (!contentType.includes('application/json')) {
            throw new Error('Unexpected server response.');
        }

        const data = await response.json();

        resultBox.className =
            response.ok && data.success
                ? 'alert alert-success mt-3'
                : 'alert alert-warning mt-3';

        const summary = document.createElement('div');
        summary.textContent = data.message || 'Request completed.';

        resultBox.replaceChildren(summary);

        if (Array.isArray(data.results)) {
            const list = document.createElement('ul');
            list.className = 'mb-0 mt-2';

            data.results.forEach(function (item) {
                const li = document.createElement('li');
                li.textContent = item;
                list.appendChild(li);
            });

            resultBox.appendChild(list);
        }

        if (data.dry_run === true) {
            const note = document.createElement('div');
            note.className = 'mt-2 fw-bold';
            note.textContent = 'DRY RUN — no SMS was sent.';
            resultBox.appendChild(note);
        }

    } catch (error) {
        resultBox.className = 'alert alert-danger mt-3';
        resultBox.textContent =
            'Unable to process the request. Check your connection and try again.';
    } finally {
        button.textContent = originalText;
        update();
    }
});


    update();
})();
</script>

<?php
require SystemURLs::getDocumentRoot() . '/Include/Footer.php';
?>
