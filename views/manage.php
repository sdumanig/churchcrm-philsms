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

    <div class="alert alert-info">
        Mode:
        <strong>
            <?= $live ? 'LIVE — messages cost credits'
                      : 'DRY RUN — no messages sent' ?>
        </strong>
        <br>
        API token:
        <?= $configured ? 'configured' : 'missing' ?>
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

<!-- Approval notification review: mode-aware, live delivery locked -->
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
                        Sending will consume SMS credits.
                        Live confirmation is not yet enabled.
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
                <div id="philsms-review-feedback"
                     class="small mt-3" role="status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary"
                        id="philsms-confirm-dry-run"
                        <?= $live ? 'disabled' : '' ?>>
                    <?= $live ? 'Confirm &amp; Send SMS' : 'Confirm Dry Run' ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?= $base ?>/plugins/community/philsms/assets/js/sms-segments.js"></script>
<script>
(function () {
    'use strict';

    // Notification review workflow. Live delivery remains locked.
    const draftRevisions = new WeakMap();
    const busyDraftRows = new WeakSet();
    const unsavedDraftRows = new WeakSet();
    const reviewedDraftRows = new WeakSet();

    const notificationDryRunMode =
        <?= $live ? 'false' : 'true' ?>;

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

    const notificationPreviewUrl = <?= json_encode(
        $base . '/plugins/philsms/notifications/dry-run',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    document.querySelectorAll('.philsms-save-draft').forEach(function (button) {
        button.addEventListener('click', async function () {
            const row = button.closest('tr');
            if (!row || busyDraftRows.has(row)) return;

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
                        queue_id: button.dataset.queueId,
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
        if (confirmDryRunButton) {
            confirmDryRunButton.disabled = false;
        }
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

            if (!notificationDryRunMode) {
                showNotificationResult(
                    row, 'Staging dry-run interface is unavailable.', 'danger'
                );
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
                    notificationPreviewUrl,
                    new URLSearchParams({
                        csrf: notificationCsrf.value,
                        queue_id: queueId
                    })
                );

                if ((draftRevisions.get(row) || 0) !== revision ||
                    unsavedDraftRows.has(row)) return;

                if (!response.ok || data.success !== true ||
                    data.dry_run !== true || !data.preview) {
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

                pendingReview = {row, queueId, revision};

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

        const {row, queueId, revision} = review;

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
            // Validate again. Never call the live delivery endpoint.
            const {response, data} = await notificationPost(
                notificationPreviewUrl,
                new URLSearchParams({
                    csrf: notificationCsrf.value,
                    queue_id: queueId
                })
            );

            if (!response.ok || data.success !== true ||
                data.dry_run !== true) {
                throw new Error('Validation failed');
            }

            showNotificationResult(
                row,
                'Dry run completed successfully. No SMS was sent.',
                'success'
            );

            pendingReview = null;
            closeReviewModal();
        } catch (error) {
            document.getElementById(
                'philsms-review-feedback'
            ).textContent =
                'Dry-run validation failed. No delivery was requested.';
        } finally {
            confirmDryRunButton.disabled = false;
            setNotificationBusy(row, false);
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
